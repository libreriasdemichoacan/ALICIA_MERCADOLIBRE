<?php

declare(strict_types=1);

namespace App;

use PDO;

final class RemoteMysqlBranchRepository
{
    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return Database::connection()->query('SELECT * FROM remote_mysql_branches ORDER BY is_active DESC, name')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(?int $id): ?array
    {
        if (!$id) {
            return null;
        }

        $stmt = Database::connection()->prepare('SELECT * FROM remote_mysql_branches WHERE id = ?');
        $stmt->execute([$id]);
        $branch = $stmt->fetch();

        return $branch ?: null;
    }

    /** @param array<int,int> $ids @return array<int,array<string,mixed>> */
    public function activeByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::connection()->prepare("SELECT * FROM remote_mysql_branches WHERE is_active = 1 AND id IN ({$placeholders}) ORDER BY name");
        $stmt->execute($ids);

        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $branch @return array<int,array{item_id:string,quantity:int,price:float}> */
    public function mercadoLibreProducts(array $branch, int $reserve = 2, int $limit = 5000, int $offset = 0): array
    {
        $pdo = $this->remoteConnection($branch);
        $limit = max(1, min($limit, 5000));
        $offset = max(0, $offset);
        $stmt = $pdo->query(
            'SELECT libro.id, libro.cantidad, libro.precio3, libro.MLM, COALESCE(apartados.total_apartado, 0) AS apartado '
            . 'FROM libro '
            . 'LEFT JOIN (SELECT a10, SUM(a3) AS total_apartado FROM proforma_detalle GROUP BY a10) AS apartados ON apartados.a10 = libro.id '
            . "WHERE libro.MLM <> '' LIMIT {$offset}, {$limit}"
        );
        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $rawItemId = trim((string) ($row['MLM'] ?? ''));
            $itemId = $this->normalizeMeliItemId($rawItemId);
            if ($itemId === null) {
                continue;
            }

            $stock = (int) ($row['cantidad'] ?? 0);
            $apartado = (int) ($row['apartado'] ?? 0);
            $quantity = max(0, $stock - $apartado - $reserve);
            $rows[] = [
                'item_id' => $itemId,
                'quantity' => $quantity,
                'price' => (float) ($row['precio3'] ?? 0),
            ];
        }

        return $rows;
    }

    private function normalizeMeliItemId(string $rawItemId): ?string
    {
        $itemId = strtoupper(trim($rawItemId));
        $itemId = preg_replace('/\s+/', '', $itemId) ?? '';

        if (preg_match('/^ML[A-Z]\d+$/', $itemId) === 1) {
            return $itemId;
        }

        if (preg_match('/\b(ML[A-Z]\d+)\b/', $itemId, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^\d+$/', $itemId) === 1) {
            return 'MLM' . $itemId;
        }

        return null;
    }

    /** @param array<string,mixed> $branch */
    private function remoteConnection(array $branch): PDO
    {
        $host = (string) ($branch['host'] ?? '');
        $port = (int) ($branch['port'] ?? 3306);
        $database = (string) ($branch['database_name'] ?? '');
        $charset = (string) ($branch['charset'] ?? 'utf8mb4');
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

        return new PDO(
            $dsn,
            (string) ($branch['username'] ?? ''),
            (string) ($branch['password'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 10,
            ]
        );
    }

    /** @param array<string,mixed> $data */
    public function logStockUpdate(array $data): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO remote_stock_sync_logs (remote_branch_id, remote_branch_name, meli_item_id, stock_quantity, price, success, error_message) '
            . 'VALUES (:remote_branch_id, :remote_branch_name, :meli_item_id, :stock_quantity, :price, :success, :error_message)'
        );
        $stmt->execute([
            'remote_branch_id' => $data['remote_branch_id'] ?? null,
            'remote_branch_name' => $data['remote_branch_name'] ?? null,
            'meli_item_id' => $data['meli_item_id'] ?? '',
            'stock_quantity' => $data['stock_quantity'] ?? null,
            'price' => $data['price'] ?? null,
            'success' => !empty($data['success']) ? 1 : 0,
            'error_message' => $data['error_message'] ?? null,
        ]);
    }

    /** @param array<string,mixed> $data */
    public function save(array $data): int
    {
        $id = (int) ($data['id'] ?? 0);
        $payload = [
            'name' => trim((string) ($data['name'] ?? '')),
            'code' => trim((string) ($data['code'] ?? '')) ?: null,
            'host' => trim((string) ($data['host'] ?? '')),
            'port' => (int) ($data['port'] ?? 3306),
            'database_name' => trim((string) ($data['database_name'] ?? '')),
            'username' => trim((string) ($data['username'] ?? '')),
            'password' => (string) ($data['password'] ?? ''),
            'charset' => trim((string) ($data['charset'] ?? 'utf8mb4')) ?: 'utf8mb4',
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'is_active' => isset($data['is_active']) ? 1 : 0,
        ];

        if ($payload['name'] === '') {
            $payload['name'] = 'Sucursal remota sin nombre';
        }

        if ($payload['host'] === '' || $payload['database_name'] === '' || $payload['username'] === '') {
            throw new \InvalidArgumentException('Host, base de datos y usuario son obligatorios para la conexión remota.');
        }

        if ($payload['port'] < 1 || $payload['port'] > 65535) {
            $payload['port'] = 3306;
        }

        if ($id > 0) {
            $current = $this->find($id);
            if (!$current) {
                throw new \InvalidArgumentException('La sucursal remota no existe.');
            }
            if ($payload['password'] === '') {
                $payload['password'] = (string) ($current['password'] ?? '');
            }

            $stmt = Database::connection()->prepare(
                'UPDATE remote_mysql_branches SET name = :name, code = :code, host = :host, port = :port, database_name = :database_name, '
                . 'username = :username, password = :password, charset = :charset, notes = :notes, is_active = :is_active WHERE id = :id'
            );
            $stmt->execute($payload + ['id' => $id]);

            return $id;
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO remote_mysql_branches (name, code, host, port, database_name, username, password, charset, notes, is_active) '
            . 'VALUES (:name, :code, :host, :port, :database_name, :username, :password, :charset, :notes, :is_active)'
        );
        $stmt->execute($payload);

        return (int) Database::connection()->lastInsertId();
    }
}
