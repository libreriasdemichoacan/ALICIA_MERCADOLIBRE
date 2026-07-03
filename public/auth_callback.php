<?php

declare(strict_types=1);

use App\AuthCallbackHandler;
use App\Flash;
use App\MeliAccountRepository;
use App\MeliBranchRepository;
use App\MeliClient;

session_start();
require __DIR__ . '/../config/bootstrap.php';

try {
    $branchId = isset($_SESSION['meli_oauth_branch_id']) ? (int) $_SESSION['meli_oauth_branch_id'] : null;
    $branches = new MeliBranchRepository();
    $branch = $branches->find($branchId);
    AuthCallbackHandler::handle(new MeliClient($branches->clientConfig($branch)), new MeliAccountRepository(), $branch ? (int) $branch['id'] : null);
} catch (Throwable $exception) {
    Flash::add('error', $exception->getMessage());
}

header('Location: ./?page=dashboard');
exit;
