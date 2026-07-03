<?php

declare(strict_types=1);

namespace App;

use DateInterval;
use DateTimeImmutable;

final class MeliAccountRepository
{
    public function saveFromToken(array $tokenData, ?array $user = null, ?int $branchId = null): void
    {
        $expiresAt = (new DateTimeImmutable())->add(new DateInterval('PT' . (int) ($tokenData['expires_in'] ?? 0) . 'S'));
        $sellerId = (int) ($tokenData['user_id'] ?? $user['id'] ?? 0);

        $stmt = Database::connection()->prepare(
            'INSERT INTO meli_accounts (branch_id, seller_id, nickname, site_id, access_token, refresh_token, token_expires_at, scopes) '
            . 'VALUES (:branch_id, :seller_id, :nickname, :site_id, :access_token, :refresh_token, :token_expires_at, :scopes) '
            . 'ON DUPLICATE KEY UPDATE nickname = VALUES(nickname), site_id = VALUES(site_id), access_token = VALUES(access_token), '
            . 'refresh_token = VALUES(refresh_token), token_expires_at = VALUES(token_expires_at), scopes = VALUES(scopes)'
        );
        $stmt->execute([
            'branch_id' => $branchId,
            'seller_id' => $sellerId,
            'nickname' => $user['nickname'] ?? null,
            'site_id' => $user['site_id'] ?? Settings::get('MELI_SITE_ID', 'MLM'),
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'] ?? null,
            'token_expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'scopes' => $tokenData['scope'] ?? null,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function first(?int $branchId = null): ?array
    {
        if ($branchId) {
            $stmt = Database::connection()->prepare('SELECT * FROM meli_accounts WHERE branch_id = ? ORDER BY updated_at DESC LIMIT 1');
            $stmt->execute([$branchId]);
            $row = $stmt->fetch();
            return $row ?: null;
        }

        $row = Database::connection()->query('SELECT * FROM meli_accounts ORDER BY updated_at DESC LIMIT 1')->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM meli_accounts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function validAccount(MeliClient $client, ?int $branchId = null): ?array
    {
        $account = $this->first($branchId);
        if (!$account) {
            return null;
        }

        $expiresAt = isset($account['token_expires_at']) ? strtotime((string) $account['token_expires_at']) : 0;
        if ($expiresAt > time() + 300 || empty($account['refresh_token'])) {
            return $account;
        }

        $token = $client->refreshToken((string) $account['refresh_token']);
        $user = [
            'id' => $account['seller_id'],
            'nickname' => $account['nickname'],
            'site_id' => $account['site_id'],
        ];
        $this->saveFromToken($token, $user, $branchId ?? (isset($account['branch_id']) ? (int) $account['branch_id'] : null));

        return $this->first($branchId);
    }
}
