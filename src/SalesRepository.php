<?php

declare(strict_types=1);

namespace App;

use PDO;

final class SalesRepository
{
    // ---------------------------------------------------------------
    // Constantes legacy
    // ---------------------------------------------------------------

    private const LEGACY_BD = 'chapu';

    private const CLIENT_MAP = [
        'libreria' => '4037',
        'allende'  => '5218',
        'madero'   => '5180',
        'chapu'    => '5280',
    ];

    private const SHIP_STATUS_MAP = [
        'shipped'       => 5,
        'not_delivered' => 7,
        'delivered'     => 6,
        '404'           => 8,
    ];

    private ?PDO $legacy = null;

    /** Conexión lazy a la BD legacy (credenciales desde .env) */
    private function legacy(): PDO
    {
        if ($this->legacy === null) {
            $host = Config::get('LEGACY_DB_HOST', Config::get('DB_HOST', '127.0.0.1'));
            $port = Config::get('LEGACY_DB_PORT', Config::get('DB_PORT', '3306'));
            $user = Config::get('LEGACY_DB_USERNAME', Config::get('DB_USERNAME', 'root'));
            $pass = Config::get('LEGACY_DB_PASSWORD', Config::get('DB_PASSWORD', ''));

            $this->legacy = new PDO(
                "mysql:host={$host};port={$port};charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        }

        return $this->legacy;
    }

    // ---------------------------------------------------------------
    // Métodos públicos originales — firmas sin cambios
    // ---------------------------------------------------------------

    /** @return array<string,mixed> */
    public function dashboard(): array
    {
        $pdo = Database::connection();
        return [
            'total_sales'        => (int)   $pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn(),
            'open_sales'         => (int)   $pdo->query("SELECT COUNT(*) FROM sales s JOIN sale_statuses st ON st.id = s.internal_status_id WHERE st.code NOT IN ('delivered','cancelled','returned')")->fetchColumn(),
            'revenue'            => (float) $pdo->query('SELECT COALESCE(SUM(paid_amount), 0) FROM sales')->fetchColumn(),
            'pending_stock_logs' => (int)   $pdo->query('SELECT COUNT(*) FROM inventory_syncs WHERE success = 0')->fetchColumn(),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function statuses(): array
    {
        return Database::connection()->query('SELECT * FROM sale_statuses ORDER BY sort_order')->fetchAll();
    }

    /**
     * @param array{status?:?string,date_from?:?string,date_to?:?string,q?:?string,branch_id?:?int} $filters
     * @return array<int,array<string,mixed>>
     */
    public function recentSales(array $filters = []): array
    {
        $sql = 'SELECT s.*, st.name AS internal_status, st.code AS internal_status_code, st.color AS status_color '
            . 'FROM sales s JOIN sale_statuses st ON st.id = s.internal_status_id '
            . 'LEFT JOIN meli_accounts ma ON ma.id = s.account_id';
        $where  = [];
        $params = [];

        $branchId = (int) ($filters['branch_id'] ?? 0);
        if ($branchId > 0) {
            $where[]  = 'ma.branch_id = ?';
            $params[] = $branchId;
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $where[]  = 'st.code = ?';
            $params[] = $status;
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($this->isDate($dateFrom)) {
            $where[]  = 'DATE(COALESCE(s.date_created, s.created_at)) >= ?';
            $params[] = $dateFrom;
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($this->isDate($dateTo)) {
            $where[]  = 'DATE(COALESCE(s.date_created, s.created_at)) <= ?';
            $params[] = $dateTo;
        }

        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $where[] = '('
                . 'CAST(s.meli_order_id AS CHAR) LIKE ? OR '
                . "COALESCE(s.buyer_nickname, '') LIKE ? OR "
                . "COALESCE(s.buyer_first_name, '') LIKE ? OR "
                . "COALESCE(s.buyer_last_name, '') LIKE ? OR "
                . "COALESCE(s.meli_status, '') LIKE ? OR "
                . "COALESCE(st.name, '') LIKE ? OR "
                . "COALESCE(s.shipping_status, '') LIKE ? OR "
                . "COALESCE(s.tracking_number, '') LIKE ? OR "
                . 'CAST(s.paid_amount AS CHAR) LIKE ?'
                . ')';
            $like = '%' . $query . '%';
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY COALESCE(s.date_created, s.created_at) DESC LIMIT 200';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT s.*, st.name AS internal_status, st.code AS internal_status_code, st.color AS status_color '
            . 'FROM sales s JOIN sale_statuses st ON st.id = s.internal_status_id WHERE s.id = ?'
        );
        $stmt->execute([$id]);
        $sale = $stmt->fetch();
        if (!$sale) {
            return null;
        }

        $items = Database::connection()->prepare('SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id');
        $items->execute([$id]);
        $sale['items'] = $items->fetchAll();

        $history = Database::connection()->prepare(
            'SELECT h.*, st.name, st.color FROM sale_status_history h '
            . 'JOIN sale_statuses st ON st.id = h.status_id WHERE h.sale_id = ? ORDER BY h.created_at DESC'
        );
        $history->execute([$id]);
        $sale['history'] = $history->fetchAll();

        return $sale;
    }

    public function existsByMeliOrderId(int $meliOrderId): bool
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM sales WHERE meli_order_id = ?');
        $stmt->execute([$meliOrderId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function updateStatus(int $saleId, int $statusId, ?string $notes): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE sales SET internal_status_id = ? WHERE id = ?')
            ->execute([$statusId, $saleId]);
        $pdo->prepare('INSERT INTO sale_status_history (sale_id, status_id, notes) VALUES (?, ?, ?)')
            ->execute([$saleId, $statusId, $notes]);
        $pdo->commit();
    }

    public function upsertFromMeli(array $order, ?int $accountId): int
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        $buyer      = $order['buyer']    ?? [];
        $shipping   = $order['shipping'] ?? [];
        $payments   = $order['payments'] ?? [];
        $paidAmount = 0.0;
        foreach ($payments as $payment) {
            if (($payment['status'] ?? '') === 'approved') {
                $paidAmount += (float) ($payment['transaction_amount'] ?? 0);
            }
        }

        $cancelledStatusId = (int) $pdo->query("SELECT id FROM sale_statuses WHERE code = 'cancelled'")->fetchColumn();
        $internalStatus    = (($order['status'] ?? '') === 'cancelled') ? $cancelledStatusId : 1;

        $stmt = $pdo->prepare(
            'INSERT INTO sales '
            . '(meli_order_id, pack_id, account_id, internal_status_id, meli_status, meli_status_detail, '
            . 'date_created, date_closed, last_updated, buyer_id, buyer_nickname, buyer_first_name, '
            . 'buyer_last_name, buyer_email, total_amount, paid_amount, currency_id, shipping_id, '
            . 'shipping_status, shipping_substatus, logistic_type, tracking_number, raw_payload, synced_at) '
            . 'VALUES '
            . '(:meli_order_id, :pack_id, :account_id, :internal_status_id, :meli_status, :meli_status_detail, '
            . ':date_created, :date_closed, :last_updated, :buyer_id, :buyer_nickname, :buyer_first_name, '
            . ':buyer_last_name, :buyer_email, :total_amount, :paid_amount, :currency_id, :shipping_id, '
            . ':shipping_status, :shipping_substatus, :logistic_type, :tracking_number, :raw_payload, NOW()) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'pack_id = VALUES(pack_id), account_id = VALUES(account_id), meli_status = VALUES(meli_status), '
            . 'meli_status_detail = VALUES(meli_status_detail), last_updated = VALUES(last_updated), '
            . 'buyer_nickname = VALUES(buyer_nickname), total_amount = VALUES(total_amount), '
            . 'paid_amount = VALUES(paid_amount), shipping_id = VALUES(shipping_id), '
            . 'shipping_status = VALUES(shipping_status), shipping_substatus = VALUES(shipping_substatus), '
            . 'logistic_type = VALUES(logistic_type), tracking_number = VALUES(tracking_number), '
            . 'raw_payload = VALUES(raw_payload), synced_at = NOW(), '
            . "internal_status_id = IF(VALUES(meli_status) = 'cancelled', {$internalStatus}, internal_status_id)"
        );
        $stmt->execute([
            'meli_order_id'      => $order['id'],
            'pack_id'            => $order['pack_id']               ?? null,
            'account_id'         => $accountId,
            'internal_status_id' => $internalStatus,
            'meli_status'        => $order['status']                ?? null,
            'meli_status_detail' => $order['status_detail']['code'] ?? null,
            'date_created'       => $this->dateValue($order['date_created'] ?? null),
            'date_closed'        => $this->dateValue($order['date_closed']  ?? null),
            'last_updated'       => $this->dateValue($order['last_updated'] ?? null),
            'buyer_id'           => $buyer['id']           ?? null,
            'buyer_nickname'     => $buyer['nickname']     ?? null,
            'buyer_first_name'   => $buyer['first_name']   ?? null,
            'buyer_last_name'    => $buyer['last_name']    ?? null,
            'buyer_email'        => $buyer['email']        ?? null,
            'total_amount'       => $order['total_amount'] ?? 0,
            'paid_amount'        => $paidAmount,
            'currency_id'        => $order['currency_id']  ?? 'MXN',
            'shipping_id'        => $shipping['id']               ?? null,
            'shipping_status'    => $shipping['status']           ?? null,
            'shipping_substatus' => $shipping['substatus']        ?? null,
            'logistic_type'      => $shipping['logistic_type']    ?? null,
            'tracking_number'    => $shipping['tracking_number']  ?? null,
            'raw_payload'        => json_encode($order, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);

        $saleId = (int) $pdo->lastInsertId();
        if ($saleId === 0) {
            $finder = $pdo->prepare('SELECT id FROM sales WHERE meli_order_id = ?');
            $finder->execute([$order['id']]);
            $saleId = (int) $finder->fetchColumn();
        }

        $orderItems = $order['order_items'] ?? [];
        if ($orderItems !== []) {
            $pdo->prepare('DELETE FROM sale_items WHERE sale_id = ?')->execute([$saleId]);
        }

        foreach ($orderItems as $item) {
            $details = $item['item'] ?? [];
            $pdo->prepare(
                'INSERT INTO sale_items '
                . '(sale_id, meli_item_id, variation_id, title, sku, quantity, unit_price, '
                . 'full_unit_price, currency_id, listing_type_id, warranty, raw_payload) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $saleId,
                $details['id']              ?? '',
                $details['variation_id']    ?? null,
                $details['title']           ?? 'Sin título',
                $details['seller_sku']      ?? $details['seller_custom_field'] ?? null,
                $item['quantity']           ?? 1,
                $item['unit_price']         ?? 0,
                $item['full_unit_price']    ?? null,
                $order['currency_id']       ?? 'MXN',
                $details['listing_type_id'] ?? null,
                $details['warranty']        ?? null,
                json_encode($item, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        }

        $existsHistory = $pdo->prepare('SELECT COUNT(*) FROM sale_status_history WHERE sale_id = ?');
        $existsHistory->execute([$saleId]);
        if ((int) $existsHistory->fetchColumn() === 0) {
            $pdo->prepare('INSERT INTO sale_status_history (sale_id, status_id, notes) VALUES (?, ?, ?)')
                ->execute([$saleId, $internalStatus, 'Sincronizada desde Mercado Libre']);
        }

        $pdo->commit();

        // Sincronización con BDs legacy
        $this->syncLegacy($order);

        return $saleId;
    }

    // ---------------------------------------------------------------
    // Sincronización legacy
    // ---------------------------------------------------------------

    private function syncLegacy(array $order): void
    {
        $orderId    = (int)    ($order['id']                                    ?? 0);
        $meliStatus = (string) ($order['status']                                ?? '');
        $shippingId = (int)    ($order['shipping']['id']                        ?? 0);
        $buyerNick  = (string) ($order['buyer']['nickname']                     ?? '');
        $dateRaw    = (string) ($order['date_closed'] ?? $order['date_created'] ?? '');
        $fecha      = $dateRaw !== '' ? date('Y-m-d', strtotime($dateRaw))      : date('Y-m-d');
        $horaVenta  = $dateRaw !== '' ? date('H:i:s', strtotime($dateRaw))      : '00:00:00';
        $hoy        = date('Y-m-d');
        $horaActual = date('H:i:s');
        $branches   = $this->shuffledBranches();
        $isCancelled = $meliStatus === 'cancelled';

        foreach (($order['order_items'] ?? []) as $item) {
            $sku   = (string) ($item['item']['seller_sku'] ?? $item['item']['seller_custom_field'] ?? '');
            $mlmId = (string) ($item['item']['id']         ?? '');
            $qty   = (int)    ($item['quantity']           ?? 1);
            $price = (float)  ($item['unit_price']         ?? 0);
            $fee   = (float)  ($item['sale_fee']           ?? 0);

            if ($sku === '' || $mlmId === '') {
                continue;
            }

            // ¿Ya existe en mlo?
            $existing = $this->findMloByShipping($shippingId, $mlmId);
            if ($existing !== null) {
                if ($isCancelled) {
                    $this->insertEstado($existing['id'], 8, "Cancelado {$orderId}", $hoy, $horaActual);
                }
                continue;
            }

            // Buscar sucursal con stock disponible
            ['branch' => $bc, 'libro' => $libro, 'trace' => $trace] =
                $this->findBranch($sku, $qty, $branches);

            $lid = (int)    ($libro['id']     ?? 0);
            $lti = (string) ($libro['titulo'] ?? '');
            $lau = (string) ($libro['autor']  ?? '');

            // ¿El mismo paquete (shippingId) ya tiene proforma creada?
            $pack = $this->findExistingPackProforma($shippingId);
            if ($pack !== null) {
                $this->insertDetalle($pack['sucursal'], $lid, $sku, $lti, $lau, $qty, $price, (int) $pack['idpro'], $orderId);
                $mloId = $this->insertMlo($orderId, $fecha, $mlmId, $qty, $price, (int) $pack['idpro'], $pack['sucursal'], $sku, $lid, $shippingId, $trace, $fee, $buyerNick, $horaVenta);
                $this->insertEstado($mloId, $isCancelled ? 8 : 0, ($isCancelled ? 'Cancelado' : 'Orden') . " {$orderId} - {$pack['sucursal']}", $hoy, $horaActual);
                continue;
            }

            // Proforma nueva
            $cloc     = self::CLIENT_MAP[$bc] ?? '0';
            $proforma = $this->createProforma($bc, $cloc, $orderId, $mlmId, $fecha, $horaActual, $price);
            $this->insertDetalle($bc, $lid, $sku, $lti, $lau, $qty, $price, $proforma, $orderId);
            $mloId = $this->insertMlo($orderId, $fecha, $mlmId, $qty, $price, $proforma, $bc, $sku, $lid, $shippingId, $trace, $fee, $buyerNick, $horaVenta);
            $this->insertEstado($mloId, $isCancelled ? 8 : 0, ($isCancelled ? 'Cancelado' : 'Orden') . " {$orderId} - {$bc}", $hoy, $horaActual);
        }
    }

    // ---------------------------------------------------------------
    // Helpers legacy privados
    // ---------------------------------------------------------------

    /**
     * Busca en qué sucursal hay stock >= $need, probando en el orden dado.
     * @param  string[] $branches
     * @return array{branch:string,libro:array<string,mixed>|null,trace:string}
     */
    private function findBranch(string $sku, int $need, array $branches): array
    {
        $trace    = '';
        $fallback = ['branch' => end($branches), 'libro' => null, 'trace' => ''];

        foreach ($branches as $idx => $db) {
            $stmt = $this->legacy()->prepare(
                "SELECT id, titulo, autor, cantidad FROM {$db}.libro WHERE codbar = ? LIMIT 1"
            );
            $stmt->execute([$sku]);
            $libro = $stmt->fetch();

            if (!$libro) {
                continue;
            }

            $res = $this->legacy()->prepare(
                "SELECT COALESCE(SUM(a3), 0) FROM {$db}.proforma_detalle WHERE a10 = ?"
            );
            $res->execute([$libro['id']]);
            $reservado = (int) $res->fetchColumn();
            $disp      = (int) $libro['cantidad'] - $reservado;
            $trace    .= '/' . ($idx + 1) . "-{$db}-{$disp}-{$need}";

            if ($disp >= $need) {
                return ['branch' => $db, 'libro' => $libro, 'trace' => $trace];
            }

            $fallback = ['branch' => $db, 'libro' => $libro, 'trace' => $trace];
        }

        $fallback['trace'] = $trace;
        return $fallback;
    }

    private function createProforma(
        string $bc, string $cloc, int $orderId, string $mlmId,
        string $fecha, string $hora, float $total
    ): int {
        $obs  = "{$orderId}-{$mlmId}";
        $name = "Mercadolibre {$orderId}";

        $this->legacy()->prepare(
            "INSERT INTO {$bc}.proforma "
            . "(cliente, nombre, observaciones, fecha, hora, total, tipo, moneda, documento, bodega, "
            . "vendedor, elaboro, pedido, obs2, aplicar, idWeb, plazo, cotz, pid, "
            . "c_geo_lt, c_geo_ln, c_vigencia, c_pago, c_entrega) "
            . "VALUES (?, ?, ?, ?, ?, ?, '1', '1', '0', '0', "
            . "'AAA', 'AAA', '0', 'Mercadolibre', '3', '0', '0', '0', '0', "
            . "'0', '0', '0', '0', '0')"
        )->execute([$cloc, $name, $obs, $fecha, $hora, $total]);

        $stmt = $this->legacy()->prepare(
            "SELECT id FROM {$bc}.proforma "
            . "WHERE elaboro = 'AAA' AND observaciones = ? "
            . "ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$obs]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Inserta en proforma_detalle.
     * Columnas: a0,a1,a2,a3,a5,a6,a7,a8,a9,a10,a11,a12,a13,a14,a15,a16,a17,a21,cotizacion,a22,a24,obs1,idPedido,lista_web
     * Valores:  sku,titulo,autor,qty,price,'0','0',price,price,lid,price,'1','0','0','Editorial',price,'0','0',proforma,'0','0',orderId,orderId,'0'
     * ?:        1   2      3    4   5              6     7    8   9              10                  11                   12        13
     */
    private function insertDetalle(
        string $db, int $lid, string $sku, string $titulo, string $autor,
        int $qty, float $price, int $proforma, int $orderId
    ): void {
        // 13 placeholders, 13 valores
        $this->legacy()->prepare(
            "INSERT INTO {$db}.proforma_detalle "
            . "(a0,  a1,     a2,    a3,  a5,    a6,  a7,  a8,    a9,    a10, a11,   a12, a13, a14,         a15,   a16, a17, a21,       cotizacion, a22, a24, obs1,     idPedido, lista_web) "
            . "VALUES "
            . "(?,   ?,      ?,     ?,   ?,     '0', '0', ?,     ?,     ?,   ?,     '1', '0', '0', 'Editorial',  ?,   '0', '0', ?,          '0', '0', '0', ?,        '0')"
        )->execute([
            $sku,       // a0
            $titulo,    // a1
            $autor,     // a2
            $qty,       // a3
            $price,     // a5
            // a6 = '0', a7 = '0' (literales)
            $price,     // a8
            $price,     // a9
            $lid,       // a10
            $price,     // a11
            // a12='1', a13='0', a14='0', a15='Editorial' (literales)
            $price,     // a16
            // a17='0', a21='0' (literales)
            $proforma,  // cotizacion
            // a22='0', a24='0' (literales)
            $orderId,   // obs1  ← orden ML como referencia
            // idPedido='0', lista_web='0' (literales)
        ]);
    }

    /**
     * Inserta en mlo y retorna el id generado.
     * 15 columnas → 15 valores.
     */
    private function insertMlo(
        int $orderId, string $fecha, string $mlmId, int $qty, float $price,
        int $proforma, string $bc, string $sku, int $lid,
        int $shippingId, string $trace, float $fee, string $cliente, string $hora
    ): int {
        $bd = self::LEGACY_BD;
        $this->legacy()->prepare(
            "INSERT INTO {$bd}.mlo "
            . "(orden, fecha, mlm,   cant, precio, idpro,    sucursal, idgeneral, codbar, idlibro, shuid,      reg,    costom, cliente,  hora) "
            . "VALUES "
            . "(?,     ?,     ?,     ?,    ?,      ?,        ?,        '0',       ?,      ?,       ?,          ?,      ?,      ?,        ?)"
        )->execute([
            $orderId,   // orden
            $fecha,     // fecha
            $mlmId,     // mlm
            $qty,       // cant
            $price,     // precio
            $proforma,  // idpro
            $bc,        // sucursal
            // idgeneral = '0' (literal)
            $sku,       // codbar
            $lid,       // idlibro
            $shippingId,// shuid
            $trace,     // reg
            $fee,       // costom
            $cliente,   // cliente
            $hora,      // hora
        ]);

        return (int) $this->legacy()->lastInsertId();
    }

    /** Inserta en ml_estados. */
    private function insertEstado(
        int $mloId, int $estado, string $obs,
        ?string $fecha = null, ?string $hora = null
    ): void {
        $bd = self::LEGACY_BD;
        $this->legacy()->prepare(
            "INSERT INTO {$bd}.ml_estados (mlo, estado, fecha, hora, usuario, obs) "
            . "VALUES (?, ?, ?, ?, 1, ?)"
        )->execute([
            $mloId,
            $estado,
            $fecha ?? date('Y-m-d'),
            $hora  ?? date('H:i:s'),
            $obs,
        ]);
    }

    /** Busca registro en mlo por shippingId + mlmId para detectar duplicados. */
    private function findMloByShipping(int $shippingId, string $mlmId): ?array
    {
        $bd   = self::LEGACY_BD;
        $stmt = $this->legacy()->prepare(
            "SELECT id, idpro, sucursal FROM {$bd}.mlo WHERE shuid = ? AND mlm = ? LIMIT 1"
        );
        $stmt->execute([$shippingId, $mlmId]);

        return $stmt->fetch() ?: null;
    }

    /** Busca si el mismo paquete (shippingId) ya tiene proforma creada. */
    private function findExistingPackProforma(int $shippingId): ?array
    {
        $bd   = self::LEGACY_BD;
        $stmt = $this->legacy()->prepare(
            "SELECT id, idpro, sucursal FROM {$bd}.mlo WHERE shuid = ? LIMIT 1"
        );
        $stmt->execute([$shippingId]);

        return $stmt->fetch() ?: null;
    }

    /** Orden aleatorio de sucursales para distribución de stock. */
    private function shuffledBranches(): array
    {
        $branches = ['chapu', 'madero', 'allende', 'libreria'];
        shuffle($branches);

        return $branches;
    }

    // ---------------------------------------------------------------
    // Helpers privados originales
    // ---------------------------------------------------------------

    private function isDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    private function dateValue(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        return date('Y-m-d H:i:s', strtotime($value));
    }
}
