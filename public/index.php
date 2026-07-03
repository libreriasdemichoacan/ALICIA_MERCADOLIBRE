<?php

declare(strict_types=1);

use App\Database;
use App\Flash;
use App\MeliAccountRepository;
use App\MeliClient;
use App\MeliBranchRepository;
use App\MeliSyncService;
use App\RemoteMysqlBranchRepository;
use App\SalesRepository;
use App\Settings;
use App\AuthCallbackHandler;

session_start();
require __DIR__ . '/../config/bootstrap.php';

$branches = new MeliBranchRepository();
$remoteBranches = new RemoteMysqlBranchRepository();
$selectedBranchId = (int) ($_REQUEST['branch_id'] ?? $_SESSION['branch_id'] ?? 0);
$selectedBranch = $branches->find($selectedBranchId);
if ($selectedBranch) {
    $_SESSION['branch_id'] = (int) $selectedBranch['id'];
}
$client = new MeliClient($branches->clientConfig($selectedBranch));
$accounts = new MeliAccountRepository();
$sales = new SalesRepository();
$sync = new MeliSyncService($client, $accounts, $sales);
$page = $_GET['page'] ?? 'dashboard';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action === 'save_branch') {
            $branchId = $branches->save($_POST);
            $_SESSION['branch_id'] = $branchId;
            Flash::add('success', 'Sucursal guardada correctamente.');
            redirect('?page=settings&branch_id=' . $branchId);
        }
        if ($action === 'save_remote_branch') {
            $remoteBranchId = $remoteBranches->save($_POST);
            Flash::add('success', 'Sucursal remota guardada correctamente.');
            redirect('?page=branches&edit_remote_branch=' . $remoteBranchId);
        }
        if ($action === 'save_settings') {
            foreach (['MELI_CLIENT_ID', 'MELI_CLIENT_SECRET', 'MELI_REDIRECT_URI', 'MELI_SITE_ID', 'MELI_SELLER_ID'] as $key) {
                Settings::set($key, trim((string) ($_POST[$key] ?? '')), str_contains($key, 'SECRET'));
            }
            Flash::add('success', 'Configuración guardada correctamente.');
            redirect('?page=settings');
        }
        if ($action === 'sync_orders') {
            $result = $sync->syncRecentOrders((int) ($_POST['days'] ?? 7), $selectedBranch ? (int) $selectedBranch['id'] : null);
            $_SESSION['last_sync_result'] = $result;
            Flash::add('success', "Ventas sincronizadas: {$result['total']} ({$result['new']} nuevas, {$result['existing']} ya existentes).");
            redirect('?page=sales');
        }
        if ($action === 'update_status') {
            $sales->updateStatus((int) $_POST['sale_id'], (int) $_POST['status_id'], trim((string) ($_POST['notes'] ?? '')) ?: null);
            Flash::add('success', 'Estatus actualizado.');
            redirect('?page=sale&id=' . (int) $_POST['sale_id']);
        }
        if ($action === 'update_stock') {
            $variation = trim((string) ($_POST['variation_id'] ?? ''));
            $quantity = trim((string) ($_POST['quantity'] ?? ''));
            $price = trim((string) ($_POST['price'] ?? ''));
            $sync->updateStock(
                trim((string) $_POST['item_id']),
                $variation === '' ? null : (int) $variation,
                $quantity === '' ? null : (int) $quantity,
                $price === '' ? null : (float) $price,
                $selectedBranch ? (int) $selectedBranch['id'] : null
            );
            Flash::add('success', 'Stock/precio actualizado en Mercado Libre.');
            redirect('?page=stock');
        }
    }

    if ($page === 'download_label') {
        $label = $sync->downloadShippingLabelForSale((int) ($_GET['id'] ?? 0));
        header('Content-Type: ' . $label['content_type']);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $label['filename']) . '"');
        header('Content-Length: ' . strlen($label['body']));
        echo $label['body'];
        exit;
    }

    if ($page === 'connect') {
        if (parse_url($client->redirectUri(), PHP_URL_QUERY)) {
            throw new RuntimeException('La Redirect URI de Mercado Libre no debe incluir parámetros como ?page=auth_callback. Configura y registra una URL limpia, por ejemplo: https://bookitech.mx/alicia/mercadol/0temp/public/auth_callback.php');
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['meli_oauth_state'] = $state;
        $_SESSION['meli_oauth_branch_id'] = $selectedBranch ? (int) $selectedBranch['id'] : null;
        redirect($client->authorizationUrl($state));
    }

    if ($page === 'auth_callback') {
        AuthCallbackHandler::handle($client, $accounts, $selectedBranch ? (int) $selectedBranch['id'] : null);
        redirect('?page=dashboard');
    }
} catch (Throwable $exception) {
    Flash::add('error', $exception->getMessage());
    redirect($_SERVER['HTTP_REFERER'] ?? '?page=dashboard');
}

$branchList = $branches->all();
$account = $accounts->first($selectedBranch ? (int) $selectedBranch['id'] : null);
$statuses = $sales->statuses();
$flash = Flash::all();
$lastSyncResult = $_SESSION['last_sync_result'] ?? null;
unset($_SESSION['last_sync_result']);

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function active(string $current, string $expected): string
{
    return $current === $expected ? 'active' : '';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MercadoLibre Alicia</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="brand"><span>ML</span><strong>Alicia</strong></div>
        <nav>
            <a class="<?= active($page, 'dashboard') ?>" href="?page=dashboard">Resumen</a>
            <a class="<?= active($page, 'sales') ?>" href="?page=sales">Ventas</a>
            <a class="<?= active($page, 'stock') ?>" href="?page=stock">Stock</a>
            <a class="<?= active($page, 'branches') ?>" href="?page=branches">Sucursales</a>
            <a class="<?= active($page, 'settings') ?>" href="?page=settings">Configuración</a>
        </nav>
        <div class="account-card">
            <small>Sucursal activa</small>
            <strong><?= e($selectedBranch['name'] ?? 'Sin sucursal') ?></strong>
            <small>Cuenta conectada</small>
            <strong><?= e($account['nickname'] ?? 'Sin conexión') ?></strong>
            <?php if ($account): ?><span>Seller #<?= e((string) $account['seller_id']) ?></span><?php endif; ?>
        </div>
    </aside>
    <main class="main">
        <header class="topbar">
            <div>
                <p>Panel operativo</p>
                <h1><?= pageTitle($page) ?></h1>
            </div>
            <div class="actions">
                <?php if ($branchList !== []): ?>
                    <form method="get" class="inline-form branch-switcher">
                        <input type="hidden" name="page" value="<?= e($page) ?>">
                        <select name="branch_id" onchange="this.form.submit()">
                            <?php foreach ($branchList as $branchOption): ?>
                                <option value="<?= e((string) $branchOption['id']) ?>" <?= $selectedBranch && (int) $selectedBranch['id'] === (int) $branchOption['id'] ? 'selected' : '' ?>><?= e($branchOption['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>
                <form method="post" class="inline-form">
                    <input type="hidden" name="action" value="sync_orders">
                    <input type="hidden" name="branch_id" value="<?= e((string) ($selectedBranch['id'] ?? '')) ?>">
                    <input type="number" name="days" min="1" max="90" value="7" title="Días">
                    <button type="submit">Sincronizar ventas</button>
                </form>
                <a class="button secondary" href="?page=connect&branch_id=<?= e((string) ($selectedBranch['id'] ?? '')) ?>">Conectar Mercado Libre</a>
            </div>
        </header>

        <?php foreach ($flash as $message): ?>
            <div class="alert <?= e($message['type']) ?>"><?= e($message['message']) ?></div>
        <?php endforeach; ?>

        <?php if ($page === 'dashboard'): ?>
            <?php $metrics = $sales->dashboard(); $recent = $sales->recentSales(['branch_id' => $selectedBranch['id'] ?? null]); ?>
            <section class="metrics">
                <article><span>Ventas totales</span><strong><?= e((string) $metrics['total_sales']) ?></strong></article>
                <article><span>Ventas abiertas</span><strong><?= e((string) $metrics['open_sales']) ?></strong></article>
                <article><span>Ingresos registrados</span><strong><?= e(money($metrics['revenue'])) ?></strong></article>
                <article><span>Alertas stock</span><strong><?= e((string) $metrics['pending_stock_logs']) ?></strong></article>
            </section>
            <?= renderSalesTable($recent, false) ?>
        <?php elseif ($page === 'sales'): ?>
            <?php
                $salesFilters = [
                    'status' => $_GET['status'] ?? '',
                    'date_from' => $_GET['date_from'] ?? '',
                    'date_to' => $_GET['date_to'] ?? '',
                    'q' => $_GET['q'] ?? '',
                    'branch_id' => $selectedBranch['id'] ?? null,
                ];
                $preservedFilters = array_filter([
                    'date_from' => $salesFilters['date_from'],
                    'date_to' => $salesFilters['date_to'],
                    'q' => $salesFilters['q'],
                ], static fn ($value): bool => trim((string) $value) !== '');
            ?>
            <section class="panel filter-panel">
                <h2>Filtros de ventas</h2>
                <form method="get" class="filter-grid">
                    <input type="hidden" name="page" value="sales">
                    <label>Desde<input type="date" name="date_from" value="<?= e((string) $salesFilters['date_from']) ?>"></label>
                    <label>Hasta<input type="date" name="date_to" value="<?= e((string) $salesFilters['date_to']) ?>"></label>
                    <label>Búsqueda rápida<input name="q" value="<?= e((string) $salesFilters['q']) ?>" placeholder="Orden, comprador, estado, guía, monto..."></label>
                    <label>Estatus
                        <select name="status">
                            <option value="">Todos</option>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= e($status['code']) ?>" <?= $salesFilters['status'] === $status['code'] ? 'selected' : '' ?>><?= e($status['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="filter-actions">
                        <button type="submit">Aplicar filtros</button>
                        <a class="button secondary" href="?page=sales">Limpiar</a>
                    </div>
                </form>
            </section>
            <section class="filters">
                <a href="?<?= e(http_build_query(array_merge(['page' => 'sales'], $preservedFilters))) ?>">Todas</a>
                <?php foreach ($statuses as $status): ?>
                    <a href="?<?= e(http_build_query(array_merge(['page' => 'sales', 'status' => $status['code']], $preservedFilters))) ?>"><?= e($status['name']) ?></a>
                <?php endforeach; ?>
            </section>
            <?= renderSalesTable($sales->recentSales($salesFilters), true, is_array($lastSyncResult) ? $lastSyncResult : []) ?>
        <?php elseif ($page === 'sale'): ?>
            <?php $sale = $sales->find((int) ($_GET['id'] ?? 0)); ?>
            <?php if (!$sale): ?>
                <div class="empty">Venta no encontrada.</div>
            <?php else: ?>
                <section class="detail-grid">
                    <article class="panel">
                        <h2>Venta #<?= e((string) $sale['meli_order_id']) ?></h2>
                        <p><strong>Comprador:</strong> <?= e(trim(($sale['buyer_first_name'] ?? '') . ' ' . ($sale['buyer_last_name'] ?? '')) ?: ($sale['buyer_nickname'] ?? 'N/D')) ?></p>
                        <p><strong>Total:</strong> <?= e(money((float) $sale['total_amount'], $sale['currency_id'])) ?></p>
                        <p><strong>Mercado Libre:</strong> <?= e($sale['meli_status'] ?? 'N/D') ?></p>
                        <p><strong>Envío:</strong> <?= e($sale['shipping_status'] ?? 'N/D') ?> <?= e($sale['shipping_substatus'] ? '· ' . $sale['shipping_substatus'] : '') ?></p>
                        <p><strong>Guía / tracking:</strong> <?= e($sale['tracking_number'] ?? 'Pendiente de Mercado Libre') ?></p>
                        <?php if (!empty($sale['shipping_id'])): ?>
                            <a class="button" href="?page=download_label&id=<?= e((string) $sale['id']) ?>">Descargar guía PDF</a>
                        <?php else: ?>
                            <p class="muted">Esta venta aún no tiene shipping_id para descargar guía.</p>
                        <?php endif; ?>
                    </article>
                    <article class="panel">
                        <h2>Cambiar estatus</h2>
                        <form method="post" class="stacked-form">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="sale_id" value="<?= e((string) $sale['id']) ?>">
                            <select name="status_id">
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?= e((string) $status['id']) ?>" <?= (int) $status['id'] === (int) $sale['internal_status_id'] ? 'selected' : '' ?>><?= e($status['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <textarea name="notes" placeholder="Notas internas"></textarea>
                            <button type="submit">Guardar estatus</button>
                        </form>
                    </article>
                </section>
                <section class="panel">
                    <h2>Detalle de productos</h2>
                    <div class="table-wrap"><table><thead><tr><th>Item</th><th>SKU</th><th>Cantidad</th><th>Precio</th></tr></thead><tbody>
                        <?php foreach ($sale['items'] as $item): ?>
                            <tr><td><?= e($item['title']) ?><small><?= e($item['meli_item_id']) ?></small></td><td><?= e($item['sku'] ?? 'N/D') ?></td><td><?= e((string) $item['quantity']) ?></td><td><?= e(money((float) $item['unit_price'], $item['currency_id'])) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                </section>
                <section class="panel">
                    <h2>Historial</h2>
                    <div class="timeline">
                        <?php foreach ($sale['history'] as $event): ?>
                            <div><span style="background: <?= e($event['color']) ?>"></span><strong><?= e($event['name']) ?></strong><p><?= e($event['notes'] ?? '') ?> · <?= e($event['created_at']) ?></p></div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        <?php elseif ($page === 'stock'): ?>
            <section class="panel narrow">
                <h2>Actualizar stock y precio de publicación</h2>
                <p>Para publicaciones simples usa solo el Item ID. Para publicaciones con variaciones agrega el Variation ID. Puedes enviar stock, precio o ambos.</p>
                <form method="post" class="stacked-form">
                    <input type="hidden" name="action" value="update_stock">
                    <input type="hidden" name="branch_id" value="<?= e((string) ($selectedBranch['id'] ?? '')) ?>">
                    <label>Item ID<input name="item_id" placeholder="MLM123456789" required></label>
                    <label>Variation ID opcional<input name="variation_id" placeholder="1234567890"></label>
                    <label>Stock disponible<input type="number" name="quantity" min="0" placeholder="Dejar vacío si solo cambias precio"></label>
                    <label>Precio nuevo<input type="number" step="0.01" min="0" name="price" placeholder="Dejar vacío si solo cambias stock"></label>
                    <button type="submit">Actualizar en Mercado Libre</button>
                </form>
            </section>
        <?php elseif ($page === 'branches'): ?>
            <?php
                $remoteBranchList = $remoteBranches->all();
                $editingRemoteBranch = isset($_GET['new_remote_branch']) ? null : $remoteBranches->find(isset($_GET['edit_remote_branch']) ? (int) $_GET['edit_remote_branch'] : 0);
            ?>
            <section class="panel">
                <h2>Sucursales con conexión MySQL remota</h2>
                <div class="notice">
                    Registra aquí las conexiones MySQL externas que se usarán en futuras funciones para enviar o consultar información operativa por sucursal.
                </div>
                <div class="table-wrap"><table>
                    <thead><tr><th>Sucursal</th><th>Servidor</th><th>Base de datos</th><th>Usuario</th><th>Estatus</th><th>Acciones</th></tr></thead>
                    <tbody>
                    <?php foreach ($remoteBranchList as $remoteBranch): ?>
                        <tr>
                            <td><?= e($remoteBranch['name']) ?><small><?= e($remoteBranch['code'] ?? '') ?></small></td>
                            <td><?= e($remoteBranch['host']) ?>:<?= e((string) $remoteBranch['port']) ?><small><?= e($remoteBranch['charset']) ?></small></td>
                            <td><?= e($remoteBranch['database_name']) ?></td>
                            <td><?= e($remoteBranch['username']) ?></td>
                            <td><span class="status-dot <?= (int) $remoteBranch['is_active'] === 1 ? 'enabled' : 'disabled' ?>"><?= (int) $remoteBranch['is_active'] === 1 ? 'Activa' : 'Inactiva' ?></span></td>
                            <td><a class="button secondary" href="?page=branches&edit_remote_branch=<?= e((string) $remoteBranch['id']) ?>">Modificar</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($remoteBranchList === []): ?>
                        <tr><td colspan="6" class="empty">Aún no hay sucursales remotas registradas.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table></div>
            </section>
            <section class="panel narrow">
                <h2><?= $editingRemoteBranch ? 'Modificar sucursal remota' : 'Agregar sucursal remota' ?></h2>
                <form method="post" class="stacked-form">
                    <input type="hidden" name="action" value="save_remote_branch">
                    <input type="hidden" name="id" value="<?= e((string) ($editingRemoteBranch['id'] ?? '')) ?>">
                    <label>Nombre de sucursal<input name="name" value="<?= e($editingRemoteBranch['name'] ?? '') ?>" required></label>
                    <label>Código interno<input name="code" value="<?= e($editingRemoteBranch['code'] ?? '') ?>" placeholder="matriz, bodega-norte, sucursal-2"></label>
                    <label>Host MySQL<input name="host" value="<?= e($editingRemoteBranch['host'] ?? '') ?>" placeholder="127.0.0.1 o servidor externo" required></label>
                    <label>Puerto<input type="number" name="port" min="1" max="65535" value="<?= e((string) ($editingRemoteBranch['port'] ?? 3306)) ?>" required></label>
                    <label>Base de datos<input name="database_name" value="<?= e($editingRemoteBranch['database_name'] ?? '') ?>" required></label>
                    <label>Usuario<input name="username" value="<?= e($editingRemoteBranch['username'] ?? '') ?>" required></label>
                    <label>Contraseña<input type="password" name="password" placeholder="<?= $editingRemoteBranch ? 'Dejar vacío para conservar la actual' : 'Contraseña MySQL' ?>"></label>
                    <label>Charset<input name="charset" value="<?= e($editingRemoteBranch['charset'] ?? 'utf8mb4') ?>" required></label>
                    <label>Notas<textarea name="notes" placeholder="Uso previsto o detalles de la conexión"><?= e($editingRemoteBranch['notes'] ?? '') ?></textarea></label>
                    <label class="checkbox-label"><input type="checkbox" name="is_active" value="1" <?= !$editingRemoteBranch || (int) $editingRemoteBranch['is_active'] === 1 ? 'checked' : '' ?>> Sucursal activa</label>
                    <button type="submit">Guardar sucursal remota</button>
                    <a class="button secondary" href="?page=branches&new_remote_branch=1">Agregar nueva sucursal</a>
                </form>
            </section>
        <?php elseif ($page === 'settings'): ?>
            <?php $editingBranch = isset($_GET['new_branch']) ? null : $branches->find(isset($_GET['edit_branch']) ? (int) $_GET['edit_branch'] : ($selectedBranch['id'] ?? null)); ?>
            <section class="panel">
                <h2>Sucursales Mercado Libre México</h2>
                <div class="notice">
                    Cada sucursal tiene credenciales OAuth independientes para Mercado Libre México. Registra en Developers exactamente la Redirect URI de esa sucursal, por ejemplo <code>https://bookitech.mx/alicia/mercadol/0temp/public/auth_callback.php</code>.
                </div>
                <div class="table-wrap"><table>
                    <thead><tr><th>Sucursal</th><th>Site</th><th>Seller configurado</th><th>Cuenta conectada</th><th>Acciones</th></tr></thead>
                    <tbody>
                    <?php foreach ($branchList as $branchRow): ?>
                        <?php $branchAccount = $accounts->first((int) $branchRow['id']); ?>
                        <tr>
                            <td><?= e($branchRow['name']) ?><small><?= e($branchRow['code'] ?? '') ?></small></td>
                            <td><?= e($branchRow['meli_site_id']) ?></td>
                            <td><?= e((string) ($branchRow['meli_seller_id'] ?? 'N/D')) ?></td>
                            <td><?= e($branchAccount['nickname'] ?? 'Sin conexión') ?></td>
                            <td>
                                <a class="button secondary" href="?page=settings&branch_id=<?= e((string) $branchRow['id']) ?>&edit_branch=<?= e((string) $branchRow['id']) ?>">Editar</a>
                                <a class="button" href="?page=connect&branch_id=<?= e((string) $branchRow['id']) ?>">Conectar</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </section>
            <section class="panel narrow">
                <h2><?= $editingBranch ? 'Editar sucursal' : 'Agregar sucursal' ?></h2>
                <form method="post" class="stacked-form">
                    <input type="hidden" name="action" value="save_branch">
                    <input type="hidden" name="id" value="<?= e((string) ($editingBranch['id'] ?? '')) ?>">
                    <label>Nombre de sucursal<input name="name" value="<?= e($editingBranch['name'] ?? '') ?>" required></label>
                    <label>Código interno<input name="code" value="<?= e($editingBranch['code'] ?? '') ?>" placeholder="principal, norte, bodega-2..."></label>
                    <label>Client ID<input name="meli_client_id" value="<?= e($editingBranch['meli_client_id'] ?? '') ?>" required></label>
                    <label>Client Secret<input name="meli_client_secret" value="<?= e($editingBranch['meli_client_secret'] ?? '') ?>" required></label>
                    <label>Redirect URI<input name="meli_redirect_uri" value="<?= e($editingBranch['meli_redirect_uri'] ?? '') ?>" placeholder="https://bookitech.mx/alicia/mercadol/0temp/public/auth_callback.php" required></label>
                    <label>Site ID<input name="meli_site_id" value="<?= e($editingBranch['meli_site_id'] ?? 'MLM') ?>" required></label>
                    <label>Seller ID opcional<input name="meli_seller_id" value="<?= e((string) ($editingBranch['meli_seller_id'] ?? '')) ?>"></label>
                    <label class="checkbox-label"><input type="checkbox" name="is_active" value="1" <?= !$editingBranch || (int) $editingBranch['is_active'] === 1 ? 'checked' : '' ?>> Sucursal activa</label>
                    <button type="submit">Guardar sucursal</button>
                    <a class="button secondary" href="?page=settings&new_branch=1">Agregar nueva sucursal</a>
                </form>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
<?php
function pageTitle(string $page): string
{
    return [
        'dashboard' => 'Resumen',
        'sales' => 'Ventas',
        'sale' => 'Detalle de venta',
        'stock' => 'Control de stock',
        'settings' => 'Configuración',
        'branches' => 'Sucursales',
    ][$page] ?? 'Resumen';
}

function renderSalesTable(array $rows, bool $showEmpty, array $syncResult = []): string
{
    $newIds = array_flip(array_map('intval', $syncResult['new_ids'] ?? []));
    $existingIds = array_flip(array_map('intval', $syncResult['existing_ids'] ?? []));
    $showSyncColumn = $syncResult !== [];
    ob_start();
    ?>
    <section class="panel">
        <h2>Ventas recientes</h2>
        <?php if ($rows === [] && $showEmpty): ?>
            <div class="empty">No hay ventas registradas para este filtro.</div>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Orden</th><th>Comprador</th><th>Total</th><th>Estado interno</th><th>Estado ML</th><?php if ($showSyncColumn): ?><th>Sincronización</th><?php endif; ?><th>Fecha</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $syncClass = isset($newIds[(int) $row['id']]) ? 'row-new-sync' : (isset($existingIds[(int) $row['id']]) ? 'row-existing-sync' : ''); ?>
                    <tr class="<?= e($syncClass) ?>">
                        <td><a href="?page=sale&id=<?= e((string) $row['id']) ?>">#<?= e((string) $row['meli_order_id']) ?></a></td>
                        <td><?= e($row['buyer_nickname'] ?? 'N/D') ?></td>
                        <td><?= e(money((float) $row['paid_amount'], $row['currency_id'])) ?></td>
                        <td><span class="badge" style="--badge: <?= e($row['status_color']) ?>"><?= e($row['internal_status']) ?></span></td>
                        <td><?= e($row['meli_status'] ?? 'N/D') ?></td>
                        <?php if ($showSyncColumn): ?>
                            <td>
                                <?php if (isset($newIds[(int) $row['id']])): ?>
                                    <span class="sync-pill new">Nueva</span>
                                <?php elseif (isset($existingIds[(int) $row['id']])): ?>
                                    <span class="sync-pill existing">Ya existía</span>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td><?= e($row['date_created'] ?? $row['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
    <?php
    return (string) ob_get_clean();
}
