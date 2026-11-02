<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Console;

use Dacheng\Yii2\Swoole\Server\HttpServer;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\di\Instance;

class SwooleController extends Controller
{
    public string $serverComponent = 'swooleHttpServer';

    private ?HttpServer $server = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['serverComponent']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'c' => 'serverComponent',
        ]);
    }

    /**
     * Starts swoole http server
     *
     * @param string|null $host Optional listen host override, defaults to 127.0.0.1
     * @param string|null $port Optional listen port override, defaults to 9501
     */
    public function actionStart(?string $host = null, ?string $port = null): int
    {
        $server = $this->resolveServer();
        if ($server === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $listenHost = $host ?? $server->host;
        if ($listenHost !== null) {
            $server->host = $listenHost;
        }

        $listenPort = $port ?? $server->port;
        if ($listenPort !== null) {
            if (!is_numeric($listenPort)) {
                $this->stderr("Invalid port specified.\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $portNumber = (int) $listenPort;
            if ($portNumber < 1 || $portNumber > 65535) {
                $this->stderr("Port must be between 1 and 65535.\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $server->port = $portNumber;
        }

        $this->stdout(sprintf("Swoole HTTP server listening on %s:%d\n", $server->host, $server->port));

        try {
            $server->start();
        } catch (\Swoole\ExitException $e) {
            // ExitException is thrown during graceful shutdown, this is expected
            $exitCode = method_exists($e, 'getStatus') ? $e->getStatus() : 0;
            
            if ($exitCode === 0) {
                $this->stdout("Swoole HTTP server stopped gracefully.\n");
                return ExitCode::OK;
            }

            $this->stderr(sprintf("Swoole HTTP server exited with code %d: %s\n", $exitCode, $e->getMessage()));
            return $exitCode;
        }

        return ExitCode::OK;
    }

    /**
     * Stops swoole http server
     */
    public function actionStop(): int
    {
        $server = $this->resolveServer(false);
        if ($server === null) {
            $this->stdout("Server component not found or not running.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $server->stop();
        $this->stdout("Swoole HTTP server stopped.\n");

        return ExitCode::OK;
    }

    protected function resolveServer(bool $ensure = true): ?HttpServer
    {
        if ($this->server !== null) {
            return $this->server;
        }

        if (!Yii::$app->has($this->serverComponent)) {
            if ($ensure) {
                $this->stderr(sprintf("Unable to locate server component '%s'.\n", $this->serverComponent));
            }
            return null;
        }

        $server = Instance::ensure(Yii::$app->get($this->serverComponent), HttpServer::class);

        return $this->server = $server;
    }
}
