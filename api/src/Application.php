<?php

declare(strict_types=1);

namespace DevisCrm\Api;

use DevisCrm\Api\Controllers\HealthController;
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

        return new self(self::routes($db), $logger);
    }

    public static function routes(DatabaseProbe $database): Router
    {
        $router = new Router();
        $router->get('/health', new HealthController($database));

        return $router;
    }

    /**
     * @param array<string, mixed> $server
     */
    public function handle(array $server, string $requestId): JsonResponse
    {
        try {
            $request = Request::fromServer($server, self::BASE_PATH);
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
