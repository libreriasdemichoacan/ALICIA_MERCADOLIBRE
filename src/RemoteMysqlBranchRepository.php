<?php

declare(strict_types=1);

namespace App;

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
