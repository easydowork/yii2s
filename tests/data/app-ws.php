<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Yii2S\Swoole\Websocket\WebsocketHandlerInterface;

/**
 * Test double that exercises the bridge: `open()` as the authorization point
 * (path whitelist outside it disconnects with 4001), per-connection metadata
 * owned by the implementation, message echo, metadata introspection and a
 * server-initiated disconnect.
 *
 * The file is required twice (the server bootstrap and the dispatcher's lazy
 * app config), so the class definition is guarded.
 */
if (!class_exists(EchoWsHandler::class)) {
    final class EchoWsHandler implements WebsocketHandlerInterface
    {
        /** @var array<int, mixed> per-worker connection metadata (fd => auth result) */
        public array $meta = [];

        public function handshake(Request $request): bool
        {
            // The upgrade-path whitelist belongs to the implementation: paths
            // outside it are rejected with a plain 403 before any connection
            // is established. Metadata registered on open() instead.
            return in_array($request->server['request_uri'] ?? '', ['/ws', '/ws/meta'], true);
        }

        public function open(Server $server, Request $request, int $fd): void
        {
            // Per-connection metadata registered here (owned by the handler);
            // richer auth (session/token) would also happen here or in
            // handshake().
            if (($request->server['request_uri'] ?? '') === '/ws/meta') {
                $this->meta[$fd] = ['user' => 'tester'];
            }
        }

        public function message(Server $server, Frame $frame, int $fd): void
        {
            $payload = json_decode((string) $frame->data, true);
            $op = is_array($payload) ? ($payload['op'] ?? null) : null;

            switch ($op) {
                case 'meta':
                    $server->push($fd, json_encode(['meta' => $this->meta[$fd] ?? null]));
                    return;
                case 'kick':
                    $server->disconnect($fd, 4000, 'kicked-by-test');
                    return;
                default:
                    $server->push($fd, 'echo:' . (string) $frame->data);
            }
        }

        public function close(Server $server, int $fd): void
        {
            unset($this->meta[$fd]);
        }
    }
}

return [
    'id' => 'yii2stest-ws-app',
    'basePath' => dirname(__DIR__),
    'controllerMap' => [
        'site' => ['class' => \yii2stest\server\TestSiteController::class],
    ],
    'components' => [
        'request' => [
            'class' => \Yii2S\Swoole\Runtime\CoroutineRequest::class,
            'cookieValidationKey' => 'yii2stest-cookie-key',
            'enableCsrfValidation' => false,
        ],
        'response' => [
            'class' => \Yii2S\Swoole\Runtime\CoroutineResponse::class,
            'format' => \yii\web\Response::FORMAT_JSON,
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [],
        ],
        'swooleHttpServer' => [
            'class' => \Yii2S\Swoole\Server\HttpServer::class,
            'host' => '127.0.0.1',
            'port' => 0,
            'enableWebSocket' => true,
            'websocketHandler' => new EchoWsHandler(),
            'dispatcher' => new \Yii2S\Swoole\Server\RequestDispatcher(__FILE__),
        ],
    ],
];
