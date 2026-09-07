<?php

declare(strict_types=1);

namespace App;

final class StockStore
{
    public function __construct(private readonly string $path = '/tmp/stock-store.json')
    {
        if (!is_file($this->path)) {
            $this->write($this->seed());
        }
    }

    public function listProducts(): array
    {
        return array_values($this->read()['products']);
    }

    /**
     * @param array<int, array{sku?: mixed, qty?: mixed}> $items
     * @return array{ok: true, reservation: array}|array{ok: false, status: int, body: array}
     */
    public function reserve(string $orderId, array $items): array
    {
        return $this->mutate(function (array $state) use ($orderId, $items): array {
            if ($orderId === '') {
                return $this->fail(400, 'INVALID_ORDER', 'orderId es obligatorio');
            }

            $existing = $state['reservations'][$orderId] ?? null;
            if (is_array($existing) && ($existing['status'] ?? '') === 'RESERVED') {
                return ['ok' => true, 'reservation' => $existing];
            }

            if ($items === []) {
                return $this->fail(400, 'INVALID_ITEMS', 'items no puede estar vacío');
            }

            $normalized = [];
            foreach ($items as $item) {
                $sku = (string) ($item['sku'] ?? '');
                $qty = (int) ($item['qty'] ?? 0);

                if ($sku === '' || $qty < 1) {
                    return $this->fail(400, 'INVALID_ITEM', 'Cada ítem necesita sku y qty >= 1');
                }

                if (!isset($state['products'][$sku])) {
                    return $this->fail(404, 'UNKNOWN_SKU', "El SKU {$sku} no existe", ['sku' => $sku]);
                }

                $normalized[] = ['sku' => $sku, 'qty' => $qty];
            }

            foreach ($normalized as $item) {
                $available = $state['products'][$item['sku']]['stock'];
                if ($available < $item['qty']) {
                    return $this->fail(409, 'INSUFFICIENT_STOCK', "Stock insuficiente para {$item['sku']}", [
                        'sku' => $item['sku'],
                        'requested' => $item['qty'],
                        'available' => $available,
                    ]);
                }
            }

            foreach ($normalized as $item) {
                $state['products'][$item['sku']]['stock'] -= $item['qty'];
            }

            $reservation = [
                'reservationId' => uniqid('res-', true),
                'orderId' => $orderId,
                'items' => $normalized,
                'status' => 'RESERVED',
            ];
            $state['reservations'][$orderId] = $reservation;

            return ['ok' => true, 'reservation' => $reservation, 'state' => $state];
        });
    }

    /**
     * @return array{ok: true, reservation: array}|array{ok: false, status: int, body: array}
     */
    public function release(string $orderId): array
    {
        return $this->mutate(function (array $state) use ($orderId): array {
            if ($orderId === '' || !isset($state['reservations'][$orderId])) {
                return $this->fail(404, 'RESERVATION_NOT_FOUND', 'No hay reserva para ese pedido');
            }

            $reservation = $state['reservations'][$orderId];
            if ($reservation['status'] === 'RELEASED') {
                return ['ok' => true, 'reservation' => $reservation];
            }

            foreach ($reservation['items'] as $item) {
                $state['products'][$item['sku']]['stock'] += $item['qty'];
            }

            $reservation['status'] = 'RELEASED';
            $state['reservations'][$orderId] = $reservation;

            return ['ok' => true, 'reservation' => $reservation, 'state' => $state];
        });
    }

    /**
     * @param callable(array): array $fn
     * @return array{ok: true, reservation: array}|array{ok: false, status: int, body: array}
     */
    private function mutate(callable $fn): array
    {
        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            return $this->fail(500, 'STORE_UNAVAILABLE', 'No se pudo abrir el almacén de stock');
        }

        try {
            flock($handle, LOCK_EX);
            $raw = stream_get_contents($handle);
            $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : $this->seed();
            if (!is_array($state)) {
                $state = $this->seed();
            }

            $result = $fn($state);
            if ($result['ok'] && isset($result['state'])) {
                $this->writeLocked($handle, $result['state']);
                unset($result['state']);
            }

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function read(): array
    {
        $raw = @file_get_contents($this->path);
        $state = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($state) ? $state : $this->seed();
    }

    private function write(array $state): void
    {
        file_put_contents($this->path, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    /** @param resource $handle */
    private function writeLocked($handle, array $state): void
    {
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($handle);
    }

    private function seed(): array
    {
        return [
            'products' => [
                'SKU-TSHIRT' => ['sku' => 'SKU-TSHIRT', 'name' => 'Camiseta', 'stock' => 20],
                'SKU-MUG' => ['sku' => 'SKU-MUG', 'name' => 'Taza', 'stock' => 15],
                'SKU-HOODIE' => ['sku' => 'SKU-HOODIE', 'name' => 'Sudadera', 'stock' => 8],
                'SKU-CAP' => ['sku' => 'SKU-CAP', 'name' => 'Gorra', 'stock' => 5],
            ],
            'reservations' => [],
        ];
    }

    /**
     * @return array{ok: false, status: int, body: array}
     */
    private function fail(int $status, string $error, string $message, array $details = []): array
    {
        $body = ['error' => $error, 'message' => $message];
        if ($details !== []) {
            $body = array_merge($body, $details);
        }

        return ['ok' => false, 'status' => $status, 'body' => $body];
    }
}
