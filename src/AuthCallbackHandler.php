<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

final class AuthCallbackHandler
{
    public static function handle(MeliClient $client, MeliAccountRepository $accounts, ?int $branchId = null): void
    {
        if (isset($_GET['error'])) {
            $description = $_GET['error_description'] ?? $_GET['message'] ?? $_GET['error'];
            throw new RuntimeException('Mercado Libre rechazó la autorización: ' . (string) $description);
        }

        if (!isset($_GET['code'])) {
            throw new RuntimeException('Mercado Libre no devolvió código de autorización.');
        }

        $expectedState = $_SESSION['meli_oauth_state'] ?? null;
        $receivedState = $_GET['state'] ?? null;
        unset($_SESSION['meli_oauth_state'], $_SESSION['meli_oauth_branch_id']);

        if ($expectedState !== null && !hash_equals((string) $expectedState, (string) $receivedState)) {
            throw new RuntimeException('La respuesta OAuth no coincide con la solicitud iniciada. Intenta conectar nuevamente.');
        }

        $token = $client->exchangeCode((string) $_GET['code']);
        $user = $client->get('/users/me', (string) $token['access_token']);
        $accounts->saveFromToken($token, $user, $branchId);
        Flash::add('success', 'Cuenta Mercado Libre conectada correctamente.');
    }
}
