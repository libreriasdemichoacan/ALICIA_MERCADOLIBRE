<?php

declare(strict_types=1);

namespace App;

final class MeliBranchRepository
{
    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return Database::connection()->query('SELECT * FROM meli_branches ORDER BY is_active DESC, name')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(?int $id): ?array
    {
        if (!$id) {
            return $this->first();
        }

        $stmt = Database::connection()->prepare('SELECT * FROM meli_branches WHERE id = ?');
        $stmt->execute([$id]);
        $branch = $stmt->fetch();

        return $branch ?: $this->first();
    }

    /** @return array<string,mixed>|null */
    public function first(): ?array
    {
        $branch = Database::connection()->query('SELECT * FROM meli_branches ORDER BY is_active DESC, id LIMIT 1')->fetch();
        return $branch ?: null;
    }

    /** @param array<string,mixed> $data */
    public function save(array $data): int
    {
        $id = (int) ($data['id'] ?? 0);
        $payload = [
            'name' => trim((string) ($data['name'] ?? '')),
            'code' => trim((string) ($data['code'] ?? '')) ?: null,
            'meli_client_id' => trim((string) ($data['meli_client_id'] ?? '')),
            'meli_client_secret' => trim((string) ($data['meli_client_secret'] ?? '')),
            'meli_redirect_uri' => trim((string) ($data['meli_redirect_uri'] ?? '')),
            'meli_site_id' => trim((string) ($data['meli_site_id'] ?? 'MLM')) ?: 'MLM',
            'meli_seller_id' => trim((string) ($data['meli_seller_id'] ?? '')) ?: null,
            'is_active' => isset($data['is_active']) ? 1 : 0,
        ];

        if ($payload['name'] === '') {
            $payload['name'] = 'Sucursal sin nombre';
        }

        if ($id > 0) {
            $stmt = Database::connection()->prepare(
                'UPDATE meli_branches SET name = :name, code = :code, meli_client_id = :meli_client_id, '
                . 'meli_client_secret = :meli_client_secret, meli_redirect_uri = :meli_redirect_uri, meli_site_id = :meli_site_id, '
                . 'meli_seller_id = :meli_seller_id, is_active = :is_active WHERE id = :id'
            );
            $stmt->execute($payload + ['id' => $id]);

            return $id;
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO meli_branches (name, code, meli_client_id, meli_client_secret, meli_redirect_uri, meli_site_id, meli_seller_id, is_active) '
            . 'VALUES (:name, :code, :meli_client_id, :meli_client_secret, :meli_redirect_uri, :meli_site_id, :meli_seller_id, :is_active)'
        );
        $stmt->execute($payload);

        return (int) Database::connection()->lastInsertId();
    }

    /** @return array<string,string> */
    public function clientConfig(?array $branch): array
    {
        if (!$branch) {
            return [];
        }

        return [
            'MELI_CLIENT_ID' => (string) ($branch['meli_client_id'] ?? ''),
            'MELI_CLIENT_SECRET' => (string) ($branch['meli_client_secret'] ?? ''),
            'MELI_REDIRECT_URI' => (string) ($branch['meli_redirect_uri'] ?? ''),
            'MELI_SITE_ID' => (string) ($branch['meli_site_id'] ?? 'MLM'),
            'MELI_SELLER_ID' => (string) ($branch['meli_seller_id'] ?? ''),
        ];
    }
}
