<?php

declare(strict_types=1);

namespace DevisCrm\Api;

use DevisCrm\Api\Auth\AuthService;
use DevisCrm\Api\Auth\Clock;
use DevisCrm\Api\Auth\PdoAuthStore;
use DevisCrm\Api\Auth\RequestGuard;
use DevisCrm\Api\Auth\SystemClock;
use DevisCrm\Api\Clients\ClientStore;
use DevisCrm\Api\Clients\PdoClientStore;
use DevisCrm\Api\Controllers\AuthController;
use DevisCrm\Api\Controllers\ClientsController;
use DevisCrm\Api\Controllers\HealthController;
use DevisCrm\Api\Controllers\ImportController;
use DevisCrm\Api\Controllers\OrdersController;
use DevisCrm\Api\Import\BackupImporter;
use DevisCrm\Api\Import\PdoImportStore;
use DevisCrm\Api\Orders\OrderService;
use DevisCrm\Api\Orders\PdoOrderStore;
use DevisCrm\Api\Exceptions\ApiException;
use DevisCrm\Api\Exceptions\ConfigException;
use DevisCrm\Api\Exceptions\DatabaseUnavailableException;
use DevisCrm\Api\Http\JsonResponse;
use DevisCrm\Api\Http\Request;
use DevisCrm\Api\Http\Router;
use DevisCrm\Api\Logging\Logger;

final class Application
{
    /** URL prefix under which Apache serves the front controller. */
    public const BASE_PATH = '/api';

    public function __construct(
        private Router $router,
        private Logger $logger,
    ) {
    }

    /**
     * Production wiring. $apiRoot is the directory that contains src/ (and migrations/).
     * Real configuration is expected at <dirname($apiRoot)>/config/local.php.
     */
    public static function create(string $apiRoot, Logger $logger): self
    {
        $configFile = dirname($apiRoot) . '/config/local.php';
        $db = new Db(static fn (): Config => Config::fromFile($configFile));
        $pdo = static fn (): \PDO => $db->pdo();
        $clock = new SystemClock();

        $clients = new PdoClientStore($pdo);
        $orders = new PdoOrderStore($pdo);

        return new self(
            self::routes(
                $db,
                new AuthService(new PdoAuthStore($pdo), $clock),
                $clients,
                $clock,
                $orders,
                new BackupImporter(new PdoImportStore($pdo), $clients, $orders, $clock),
            ),
            $logger,
        );
    }

    /**
     * Without $auth only the public health check is registered; client, order and import
     * routes also need their stores.
     */
    public static function routes(
        DatabaseProbe $database,
        ?AuthService $auth = null,
        ?ClientStore $clients = null,
        ?Clock $clock = null,
        ?PdoOrderStore $orders = null,
        ?BackupImporter $importer = null,
    ): Router {
        $clock ??= new SystemClock();
        $router = new Router($auth === null ? null : new RequestGuard($auth));
        $router->get('/health', new HealthController($database), public: true);

        if ($auth !== null) {
            $controller = new AuthController($auth);
            $router->post('/auth/login', [$controller, 'login'], public: true);
            $router->post('/auth/logout', [$controller, 'logout']);
            $router->get('/auth/me', [$controller, 'me']);

            if ($clients !== null) {
                $controller = new ClientsController($clients, $clock);
                $router->get('/clients', [$controller, 'list']);
                $router->post('/clients', [$controller, 'create']);
                $router->get('/clients/{id}', [$controller, 'show']);
                $router->patch('/clients/{id}', [$controller, 'update']);
                $router->delete('/clients/{id}', [$controller, 'delete']);
            }

            if ($orders !== null) {
                $controller = new OrdersController(new OrderService($orders, $clock));
                $router->get('/orders', [$controller, 'list']);
                $router->post('/orders', [$controller, 'create']);
                $router->get('/orders/{id}', [$controller, 'show']);
                $router->patch('/orders/{id}', [$controller, 'update']);
                $router->get('/orders/{id}/payments', [$controller, 'payments']);
                $router->post('/orders/{id}/payments', [$controller, 'addPayment']);
                $router->get('/payments', [$controller, 'allPayments']);
            }

            if ($importer !== null) {
                $controller = new ImportController($importer);
                $router->post('/import/preview', [$controller, 'preview']);
                $router->post('/import', [$controller, 'import']);
            }
        }

        return $router;
    }

    /**
     * @param array<string, mixed> $server
     * @param (callable(int): string)|null $bodyReader Defaults to php://input.
     */
    public function handle(array $server, string $requestId, ?callable $bodyReader = null): JsonResponse
    {
        try {
            $request = Request::fromServer($server, self::BASE_PATH, $bodyReader);
            $response = $this->router->dispatch($request);
        } catch (ApiException $exception) {
            $response = JsonResponse::error(
                $exception->status(),
                $exception->errorCode(),
                $exception->getMessage(),
                $exception->headers(),
            );
        } catch (ConfigException $exception) {
            $this->logger->exception($requestId, 'configuration_error', $exception);
            $response = JsonResponse::error(503, 'configuration_error', 'Service is not configured');
        } catch (DatabaseUnavailableException $exception) {
            $this->logger->exception($requestId, 'db_unavailable', $exception);
            $response = JsonResponse::error(503, 'db_unavailable', 'Database is unavailable');
        } catch (\Throwable $exception) {
            $this->logger->exception($requestId, 'internal_error', $exception);
            $response = JsonResponse::error(500, 'internal_error', 'Internal server error');
        }

        return $response->withHeader('X-Request-Id', $requestId);
    }
}
