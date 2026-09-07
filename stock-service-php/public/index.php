<?php

declare(strict_types=1);

use App\StockStore;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$store = new StockStore();
$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

$json = static function (Response $response, array $data, int $status = 200): Response {
    $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
};

$app->get('/health', static function (Request $request, Response $response) use ($json): Response {
    return $json($response, ['status' => 'ok', 'service' => 'stock-service']);
});

$app->get('/products', static function (Request $request, Response $response) use ($json, $store): Response {
    return $json($response, ['products' => $store->listProducts()]);
});

$app->post('/stock/reserve', static function (Request $request, Response $response) use ($json, $store): Response {
    $body = $request->getParsedBody() ?? [];
    $orderId = (string) ($body['orderId'] ?? '');
    $items = is_array($body['items'] ?? null) ? $body['items'] : [];

    $result = $store->reserve($orderId, $items);
    if (!$result['ok']) {
        return $json($response, $result['body'], $result['status']);
    }

    return $json($response, $result['reservation']);
});

$app->post('/stock/release', static function (Request $request, Response $response) use ($json, $store): Response {
    $body = $request->getParsedBody() ?? [];
    $orderId = (string) ($body['orderId'] ?? '');

    $result = $store->release($orderId);
    if (!$result['ok']) {
        return $json($response, $result['body'], $result['status']);
    }

    return $json($response, $result['reservation']);
});

$app->run();
