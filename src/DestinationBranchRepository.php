<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

final class DestinationBranchRepository
{
    private const MLO_TABLE = '`mlo`';

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return Database::connection()->query('SELECT * FROM destination_branches ORDER BY is_primary DESC, is_active DESC, name')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(?int $id): ?array
    {
        if (!$id) {
            return null;
        }

        $stmt = Database::connection()->prepare('SELECT * FROM destination_branches WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function primary(): ?array
    {
        $row = Database::connection()->query('SELECT * FROM destination_branches WHERE is_primary = 1 AND is_active = 1 ORDER BY id LIMIT 1')->fetch();
        return $row ?: null;
    }

    /** @param array<string,mixed> $data */
    public function save(array $data): int
    {
        $id = (int) ($data['id'] ?? 0);
        $current = $id > 0 ? $this->find($id) : null;
        $password = (string) ($data['db_password'] ?? '');
        if ($password === '' && $current) {
            $password = (string) ($current['db_password'] ?? '');
        }

        $payload = [
            'name' => trim((string) ($data['name'] ?? '')) ?: 'Sucursal destino',
            'code' => trim((string) ($data['code'] ?? '')) ?: null,
            'db_host' => trim((string) ($data['db_host'] ?? '')) ?: '127.0.0.1',
            'db_port' => (int) ($data['db_port'] ?? 3306) ?: 3306,
            'db_database' => trim((string) ($data['db_database'] ?? 'chapu')) ?: 'chapu',
            'db_username' => trim((string) ($data['db_username'] ?? '')),
            'db_password' => $password,
            'db_charset' => trim((string) ($data['db_charset'] ?? 'utf8mb4')) ?: 'utf8mb4',
            'is_active' => isset($data['is_active']) ? 1 : 0,
            'is_primary' => isset($data['is_active'], $data['is_primary']) ? 1 : 0,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ];

        if ($payload['db_database'] === '' || $payload['db_username'] === '') {
            throw new RuntimeException('Base de datos y usuario son obligatorios para la sucursal destino.');
        }

        if ($id > 0) {
            $stmt = Database::connection()->prepare(
                'UPDATE destination_branches SET name = :name, code = :code, db_host = :db_host, db_port = :db_port, '
                . 'db_database = :db_database, db_username = :db_username, db_password = :db_password, db_charset = :db_charset, '
                . 'is_active = :is_active, is_primary = :is_primary, notes = :notes WHERE id = :id'
            );
            $stmt->execute($payload + ['id' => $id]);
            $this->normalizePrimary($id, (int) $payload['is_primary'] === 1, (int) $payload['is_active'] === 1);

            return $id;
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO destination_branches (name, code, db_host, db_port, db_database, db_username, db_password, db_charset, is_active, is_primary, notes) '
            . 'VALUES (:name, :code, :db_host, :db_port, :db_database, :db_username, :db_password, :db_charset, :is_active, :is_primary, :notes)'
        );
        $stmt->execute($payload);
        $newId = (int) Database::connection()->lastInsertId();
        $this->normalizePrimary($newId, (int) $payload['is_primary'] === 1, (int) $payload['is_active'] === 1);

        return $newId;
    }

    public function testConnection(int $id): void
    {
        $branch = $this->find($id);
        if (!$branch) {
            throw new RuntimeException('Sucursal destino no encontrada.');
        }

        try {
            $pdo = $this->connection($branch);
            $pdo->query('SELECT 1');
            $this->saveConnectionStatus($id, true, null);
        } catch (Throwable $exception) {
            $this->saveConnectionStatus($id, false, $exception->getMessage());
            throw new RuntimeException('No se pudo conectar a la sucursal destino: ' . $exception->getMessage());
        }
    }

    public function prepareTables(int $id): void
    {
        $branch = $this->find($id);
        if (!$branch) {
            throw new RuntimeException('Sucursal destino no encontrada.');
        }
        if ((int) ($branch['is_primary'] ?? 0) !== 1) {
            throw new RuntimeException('Solo la sucursal destino principal puede crear la tabla mlo.');
        }

        $pdo = $this->connection($branch);
        $this->ensureMloTable($pdo);
        $this->saveSyncStatus($id, 'prepared', null, 0, 0);
    }

    /** @return array{sales:int,items:int} */
    public function syncSales(int $id): array
    {
        $branch = $this->find($id);
        if (!$branch) {
            throw new RuntimeException('Sucursal destino no encontrada.');
        }
        if ((int) ($branch['is_primary'] ?? 0) !== 1) {
            throw new RuntimeException('Solo la sucursal destino principal puede recibir registros en mlo.');
        }

        $remote = $this->connection($branch);
        $this->ensureMloTable($remote);

        $local = Database::connection();
        $sales = $local->query(
            'SELECT s.*, st.code AS internal_status_code, st.name AS internal_status_name '
            . 'FROM sales s JOIN sale_statuses st ON st.id = s.internal_status_id '
            . 'ORDER BY COALESCE(s.date_created, s.created_at) ASC'
        )->fetchAll();

        $salesCount = 0;
        $itemsCount = 0;
        $remote->beginTransaction();
        try {
            foreach ($sales as $sale) {
                $itemsStmt = $local->prepare('SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id');
                $itemsStmt->execute([$sale['id']]);
                $items = $itemsStmt->fetchAll();
                $salesCount++;

                foreach ($items as $item) {
                    $assignment = $this->findStockBranch((string) ($item['sku'] ?: $item['meli_item_id']), (int) $item['quantity']);
                    $this->upsertMlo($remote, $branch, $sale, $item, $assignment);
                    $itemsCount++;
                }
            }
            $remote->commit();
            $this->saveSyncStatus($id, 'success', null, $salesCount, $itemsCount);
        } catch (Throwable $exception) {
            $remote->rollBack();
            $this->saveSyncStatus($id, 'error', $exception->getMessage(), $salesCount, $itemsCount);
            throw new RuntimeException('No se pudieron enviar ventas a la sucursal destino: ' . $exception->getMessage());
        }

        return ['sales' => $salesCount, 'items' => $itemsCount];
    }

    /** @param array<string,mixed> $branch */
    private function connection(array $branch): PDO
    {
        return new PDO(
            $this->dsn($branch),
            (string) $branch['db_username'],
            (string) $branch['db_password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 20,
            ]
        );
    }

    private function ensureMloTable(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::MLO_TABLE . ' (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            orden VARCHAR(80) NOT NULL,
            fecha DATE NULL,
            mlm VARCHAR(40) NOT NULL,
            cant INT UNSIGNED NOT NULL DEFAULT 1,
            precio DECIMAL(12,2) NOT NULL DEFAULT 0,
            idpro BIGINT UNSIGNED NULL,
            sucursal VARCHAR(80) NULL,
            idgeneral VARCHAR(80) NULL,
            codbar VARCHAR(120) NULL,
            idlibro VARCHAR(80) NULL,
            shuid VARCHAR(120) NULL,
            reg VARCHAR(500) NULL,
            costom DECIMAL(12,2) NOT NULL DEFAULT 0,
            cliente VARCHAR(180) NULL,
            hora TIME NULL,
            INDEX idx_mlo_shuid_mlm (shuid, mlm),
            INDEX idx_mlo_orden (orden)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /** @param array<string,mixed> $branch @param array<string,mixed> $sale @param array<string,mixed> $item */
    private function upsertMlo(PDO $pdo, array $branch, array $sale, array $item, array $assignment): void
    {
        $date = $this->datePart($sale['date_created'] ?? $sale['created_at'] ?? null);
        $time = $this->timePart($sale['date_created'] ?? $sale['created_at'] ?? null);
        $branchCode = (string) ($assignment['branch_code'] ?: ($branch['code'] ?: $branch['name']));
        $shippingId = $sale['shipping_id'] !== null ? (string) $sale['shipping_id'] : null;
        $reg = trim(sprintf('Alicia/%s/%s %s', $sale['internal_status_code'] ?? '', $sale['tracking_number'] ?? '', $assignment['trace'] ?? ''), '/ ');
        $sku = (string) ($item['sku'] ?: $item['meli_item_id']);

        $existing = $pdo->prepare('SELECT id FROM ' . self::MLO_TABLE . ' WHERE shuid = ? AND mlm = ? LIMIT 1');
        $existing->execute([$shippingId, (string) $item['meli_item_id']]);
        $existingId = $existing->fetchColumn();

        $params = [
            'orden' => (string) $sale['meli_order_id'],
            'fecha' => $date,
            'mlm' => (string) $item['meli_item_id'],
            'cant' => (int) $item['quantity'],
            'precio' => (float) $item['unit_price'],
            'idpro' => null,
            'sucursal' => $branchCode,
            'idgeneral' => (string) $sale['meli_order_id'],
            'codbar' => $sku,
            'idlibro' => $assignment['book_id'] ?: null,
            'shuid' => $shippingId,
            'reg' => $reg,
            'costom' => 0,
            'cliente' => $sale['buyer_nickname'] ?: trim((string) (($sale['buyer_first_name'] ?? '') . ' ' . ($sale['buyer_last_name'] ?? ''))),
            'hora' => $time,
        ];

        if ($existingId) {
            $stmt = $pdo->prepare(
                'UPDATE ' . self::MLO_TABLE . ' SET orden = :orden, fecha = :fecha, cant = :cant, precio = :precio, sucursal = :sucursal, '
                . 'idgeneral = :idgeneral, codbar = :codbar, idlibro = :idlibro, shuid = :shuid, reg = :reg, costom = :costom, cliente = :cliente, hora = :hora '
                . 'WHERE id = :id LIMIT 1'
            );
            $stmt->execute($params + ['id' => $existingId]);
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO ' . self::MLO_TABLE . ' (orden, fecha, mlm, cant, precio, idpro, sucursal, idgeneral, codbar, idlibro, shuid, reg, costom, cliente, hora) '
            . 'VALUES (:orden, :fecha, :mlm, :cant, :precio, :idpro, :sucursal, :idgeneral, :codbar, :idlibro, :shuid, :reg, :costom, :cliente, :hora)'
        );
        $stmt->execute($params);
    }

    private function datePart(?string $value): ?string
    {
        return $value ? date('Y-m-d', strtotime($value)) : null;
    }

    private function timePart(?string $value): ?string
    {
        return $value ? date('H:i:s', strtotime($value)) : null;
    }

    /** @return array{branch_code:string,book_id:string,trace:string} */
    private function findStockBranch(string $sku, int $needed): array
    {
        $fallback = ['branch_code' => '', 'book_id' => '', 'trace' => ''];
        foreach ($this->activeBranches() as $branch) {
            $branchCode = (string) ($branch['code'] ?: $branch['name']);
            if ($fallback['branch_code'] === '') {
                $fallback['branch_code'] = $branchCode;
            }

            try {
                $pdo = $this->connection($branch);
                $book = $pdo->prepare('SELECT id, cantidad FROM libro WHERE codbar = ? LIMIT 1');
                $book->execute([$sku]);
                $row = $book->fetch();
                if (!$row) {
                    $fallback['trace'] .= "/{$branchCode}:sin-libro";
                    continue;
                }

                $reserved = $pdo->prepare('SELECT COALESCE(SUM(a3), 0) FROM proforma_detalle WHERE a10 = ?');
                $reserved->execute([$row['id']]);
                $available = (int) $row['cantidad'] - (int) $reserved->fetchColumn();
                $fallback['trace'] .= "/{$branchCode}:{$available}:{$needed}";
                if ($available >= $needed) {
                    return [
                        'branch_code' => $branchCode,
                        'book_id' => (string) $row['id'],
                        'trace' => $fallback['trace'],
                    ];
                }
            } catch (Throwable $exception) {
                $fallback['trace'] .= "/{$branchCode}:error";
            }
        }

        return $fallback;
    }

    /** @return array<int,array<string,mixed>> */
    private function activeBranches(): array
    {
        return Database::connection()->query('SELECT * FROM destination_branches WHERE is_active = 1 ORDER BY is_primary DESC, name')->fetchAll();
    }

    private function normalizePrimary(int $id, bool $isPrimary, bool $isActive): void
    {
        if ($isPrimary && $isActive) {
            $stmt = Database::connection()->prepare('UPDATE destination_branches SET is_primary = 0 WHERE id <> ?');
            $stmt->execute([$id]);
            return;
        }

        $hasPrimary = (int) Database::connection()->query('SELECT COUNT(*) FROM destination_branches WHERE is_primary = 1 AND is_active = 1')->fetchColumn();
        if ($hasPrimary > 0) {
            return;
        }

        $stmt = Database::connection()->prepare('SELECT id FROM destination_branches WHERE is_active = 1 ORDER BY (id = ?) DESC, id LIMIT 1');
        $stmt->execute([$id]);
        $primaryId = $stmt->fetchColumn();
        if ($primaryId) {
            $update = Database::connection()->prepare('UPDATE destination_branches SET is_primary = 1 WHERE id = ?');
            $update->execute([$primaryId]);
        }
    }

    /** @param array<string,mixed> $branch */
    private function dsn(array $branch): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $branch['db_host'],
            (int) $branch['db_port'],
            $branch['db_database'],
            $branch['db_charset'] ?: 'utf8mb4'
        );
    }

    private function saveConnectionStatus(int $id, bool $success, ?string $error): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE destination_branches SET last_connection_status = ?, last_connection_error = ?, last_connection_checked_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$success ? 'success' : 'error', $error, $id]);
    }

    private function saveSyncStatus(int $id, string $status, ?string $error, int $sales, int $items): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE destination_branches SET last_sync_status = ?, last_sync_error = ?, last_sync_sales = ?, last_sync_items = ?, last_sync_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$status, $error, $sales, $items, $id]);
    }
}
