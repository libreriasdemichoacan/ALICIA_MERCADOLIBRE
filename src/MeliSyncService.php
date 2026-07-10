<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

final class MeliSyncService
{
    public function __construct(
        private readonly MeliClient $client,
        private readonly MeliAccountRepository $accounts,
        private readonly SalesRepository $sales
    ) {
    }

    /** @return array{total:int,new:int,existing:int,new_ids:array<int,int>,existing_ids:array<int,int>} */
    public function syncRecentOrders(int $days = 7, ?int $branchId = null): array
    {
        $account = $this->accounts->validAccount($this->client, $branchId);
        if (!$account) {
            throw new RuntimeException('Primero conecta una cuenta de Mercado Libre.');
        }

        $from = date('Y-m-d\TH:i:s.000P', strtotime("-{$days} days"));
        $limit = 50;
        $offset = 0;
        $count = 0;
        $newCount = 0;
        $existingCount = 0;
        $newSaleIds = [];
        $existingSaleIds = [];
        $processedOrderIds = [];

        do {
            $orders = $this->client->get('/orders/search', (string) $account['access_token'], [
                'seller' => $account['seller_id'],
                'order.date_created.from' => $from,
                'sort' => 'date_desc',
                'limit' => $limit,
                'offset' => $offset,
            ]);

            $results = $orders['results'] ?? [];
            foreach ($results as $summary) {
                $orderId = is_array($summary) ? ($summary['id'] ?? null) : $summary;
                if (!$orderId || isset($processedOrderIds[(string) $orderId])) {
                    continue;
                }

                $wasExisting = $this->sales->existsByMeliOrderId((int) $orderId);
                $order = $this->client->get('/orders/' . $orderId, (string) $account['access_token']);
                $order = $this->withShipmentDetails($order, (string) $account['access_token']);
                $saleId = $this->sales->upsertFromMeli($order, (int) $account['id']);
                if ($wasExisting) {
                    $existingCount++;
                    $existingSaleIds[] = $saleId;
                } else {
                    $newCount++;
                    $newSaleIds[] = $saleId;
                }
                $processedOrderIds[(string) $orderId] = true;
                $count++;
            }

            $paging = $orders['paging'] ?? [];
            $pageLimit = (int) ($paging['limit'] ?? $limit);
            $total = (int) ($paging['total'] ?? $count);
            $offset += max($pageLimit, $limit);
        } while ($results !== [] && $offset < $total);

        return [
            'total' => $count,
            'new' => $newCount,
            'existing' => $existingCount,
            'new_ids' => $newSaleIds,
            'existing_ids' => $existingSaleIds,
        ];
    }

    /** @return array{body:string,content_type:string,filename:string,saved_path:string} */
    public function downloadShippingLabelForSale(int $saleId): array
    {
        $sale = $this->sales->find($saleId);
        if (!$sale || empty($sale['shipping_id'])) {
            throw new RuntimeException('Esta venta no tiene guía disponible porque no cuenta con shipping_id.');
        }

        $account = !empty($sale['account_id']) ? $this->accounts->findById((int) $sale['account_id']) : null;
        if (!$account) {
            $account = $this->accounts->validAccount($this->client);
        }
        if (!$account) {
            throw new RuntimeException('Primero conecta una cuenta de Mercado Libre.');
        }

        $label = $this->client->downloadShippingLabel((int) $sale['shipping_id'], (string) $account['access_token']);
        $filename = preg_replace('/[^0-9A-Za-z_-]+/', '-', (string) $sale['meli_order_id']) . '.pdf';
        $directory = (defined('ROOT_PATH') ? constant('ROOT_PATH') : dirname(__DIR__)) . '/storage/labels';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo crear la carpeta local para guardar guías.');
        }
        if (file_put_contents($directory . '/' . $filename, $label['body']) === false) {
            throw new RuntimeException('No se pudo guardar la copia local de la guía PDF.');
        }
        $label['filename'] = $filename;
        $label['saved_path'] = $directory . '/' . $filename;

        return $label;
    }

    /** @return array<string,mixed> */
    private function withShipmentDetails(array $order, string $token): array
    {
        $shippingId = $order['shipping']['id'] ?? null;
        if (!$shippingId) {
            return $order;
        }

        try {
            $shipment = $this->client->get('/shipments/' . $shippingId, $token, [], ['x-format-new: true']);
        } catch (RuntimeException) {
            return $order;
        }

        $order['shipping'] = array_merge($order['shipping'] ?? [], [
            'status' => $shipment['status'] ?? $order['shipping']['status'] ?? null,
            'substatus' => $shipment['substatus'] ?? $order['shipping']['substatus'] ?? null,
            'logistic_type' => $shipment['logistic_type'] ?? $order['shipping']['logistic_type'] ?? null,
            'tracking_number' => $shipment['tracking_number'] ?? $order['shipping']['tracking_number'] ?? null,
            'tracking_method' => $shipment['tracking_method'] ?? $order['shipping']['tracking_method'] ?? null,
        ]);
        $order['shipping_detail'] = $shipment;

        return $order;
    }

    public function updateStock(string $itemId, ?int $variationId, ?int $quantity, ?float $price = null, ?int $branchId = null): void
    {
        $account = $this->accounts->validAccount($this->client, $branchId);
        if (!$account) {
            throw new RuntimeException('Primero conecta una cuenta de Mercado Libre.');
        }

        if ($quantity === null && $price === null) {
            throw new RuntimeException('Indica stock, precio o ambos para actualizar la publicación.');
        }

        if ($variationId) {
            $variationPayload = ['id' => $variationId];
            if ($quantity !== null) {
                $variationPayload['available_quantity'] = $quantity;
            }
            if ($price !== null) {
                $variationPayload['price'] = $price;
            }
            $payload = ['variations' => [$variationPayload]];
        } else {
            $payload = [];
            if ($quantity !== null) {
                $payload['available_quantity'] = $quantity;
            }
            if ($price !== null) {
                $payload['price'] = $price;
            }
        }

        if ($quantity !== null && $quantity >= 1) {
            $payload['status'] = 'active';
        }

        $success = false;
        $response = null;
        $error = null;

        try {
            $response = $this->client->put('/items/' . rawurlencode($itemId), (string) $account['access_token'], $payload);
            $success = true;
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
            throw $exception;
        } finally {
            $stmt = Database::connection()->prepare(
                'INSERT INTO inventory_syncs (meli_item_id, variation_id, new_available_quantity, new_price, request_payload, response_payload, success, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $itemId,
                $variationId,
                $quantity,
                $price,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $response ? json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
                $success ? 1 : 0,
                $error,
            ]);
        }
    }
}
