<?php

declare(strict_types=1);

/*
 * Dependency-free tests for the API skeleton. No database or network is used.
 *
 *   php api/tests/run.php
 *
 * Exit code 0 when all tests pass, 1 otherwise.
 */

use DevisCrm\Api\Application;
use DevisCrm\Api\Config;
use DevisCrm\Api\DatabaseProbe;
use DevisCrm\Api\Db;
use DevisCrm\Api\Exceptions\ApiException;
use DevisCrm\Api\Exceptions\ConfigException;
use DevisCrm\Api\Exceptions\DatabaseUnavailableException;
use DevisCrm\Api\Http\JsonResponse;
use DevisCrm\Api\Http\Request;
use DevisCrm\Api\Http\Router;
use DevisCrm\Api\Logging\Logger;

require __DIR__ . '/../src/bootstrap.php';

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

final class AssertionFailed extends RuntimeException
{
}

final class SkipTest extends RuntimeException
{
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true)));
    }
}

function assertTrue(bool $condition, string $label): void
{
    if (!$condition) {
        throw new AssertionFailed($label);
    }
}

/**
 * @return array<string, mixed>
 */
function decode(JsonResponse $response): array
{
    $decoded = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
    assertTrue(is_array($decoded), 'response body is a JSON object');

    return $decoded;
}

function probe(?Throwable $failure = null): DatabaseProbe
{
    return new class ($failure) implements DatabaseProbe {
        public int $calls = 0;

        public function __construct(private ?Throwable $failure)
        {
        }

        public function ping(): void
        {
            $this->calls++;
            if ($this->failure !== null) {
                throw $this->failure;
            }
        }
    };
}

function tempLogFile(): string
{
    return sys_get_temp_dir() . '/devis-crm-api-test-' . bin2hex(random_bytes(6)) . '/api.log';
}

/**
 * @return array{0: Application, 1: string}
 */
function app(DatabaseProbe $probe): array
{
    $logFile = tempLogFile();

    return [new Application(Application::routes($probe), new Logger($logFile)), $logFile];
}

/**
 * @return array<string, string>
 */
function server(string $method, string $uri): array
{
    return ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri];
}

/**
 * Runs api/public/index.php (or a copy of it at $indexPhp) in a separate PHP process
 * (exit/headers/shutdown handlers stay isolated) and returns what it printed to stdout.
 */
function runFrontController(string $method, string $uri, string $documentRoot, ?string $indexPhp = null): string
{
    if (!function_exists('proc_open') || PHP_BINARY === '') {
        throw new SkipTest('proc_open or PHP_BINARY is unavailable');
    }

    $script = sprintf(
        '$_SERVER["REQUEST_METHOD"] = %s; $_SERVER["REQUEST_URI"] = %s; $_SERVER["DOCUMENT_ROOT"] = %s; require %s;',
        var_export($method, true),
        var_export($uri, true),
        var_export($documentRoot, true),
        var_export($indexPhp ?? dirname(__DIR__) . '/public/index.php', true),
    );

    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new AssertionFailed('could not start a PHP subprocess');
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return $stdout;
}

/**
 * Runs PHP code in a fresh process, before anything is printed there, and reports
 * stdout, stderr and the exit code so that errors in the child cannot pass silently.
 *
 * @return array{stdout: string, stderr: string, exitCode: int}
 */
function runPhpCode(string $code): array
{
    if (!function_exists('proc_open') || PHP_BINARY === '') {
        throw new SkipTest('proc_open or PHP_BINARY is unavailable');
    }

    $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new AssertionFailed('could not start a PHP subprocess');
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => proc_close($process)];
}

/**
 * Builds a REG.RU-like layout in a temporary directory from the real sources:
 *   <home>/www/crm.devisd.online/api/index.php   (public front controller)
 *   <home>/devis-crm/api/src/...                 (private code)
 * With $privateCodeInDocroot the private code is (wrongly) placed at <docroot>/src/ instead.
 *
 * @return array{home: string, docroot: string, index: string}
 */
function makeDeployLayout(bool $privateCodeInDocroot = false): array
{
    $home = sys_get_temp_dir() . '/devis-crm-layout-' . bin2hex(random_bytes(6));
    $docroot = $home . '/www/crm.devisd.online';
    $srcTarget = $privateCodeInDocroot ? $docroot . '/src' : $home . '/devis-crm/api/src';

    mkdir($docroot . '/api', 0777, true);
    copy(dirname(__DIR__) . '/public/index.php', $docroot . '/api/index.php');

    $source = dirname(__DIR__) . '/src';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $target = $srcTarget . substr($file->getPathname(), strlen($source));
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        copy($file->getPathname(), $target);
    }

    return ['home' => $home, 'docroot' => $docroot, 'index' => $docroot . '/api/index.php'];
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

const NOT_FOUND_BODY = '{"ok":false,"error":{"code":"not_found","message":"Resource not found"}}';
const REFUSED_BODY = '{"ok":false,"error":{"code":"configuration_error","message":"Service is not configured"}}';

$tests = [];

$tests['success envelope and default headers'] = static function (): void {
    $response = JsonResponse::success(['status' => 'ok']);
    assertSame(200, $response->status(), 'status');
    assertSame('{"ok":true,"data":{"status":"ok"}}', $response->body(), 'body');
    assertSame('application/json; charset=utf-8', $response->headers()['Content-Type'], 'content type');
    assertSame('no-store', $response->headers()['Cache-Control'], 'cache control');
};

$tests['empty success data is a JSON object'] = static function (): void {
    assertSame('{"ok":true,"data":{}}', JsonResponse::success([])->body(), 'body');
};

$tests['error envelope keeps unicode'] = static function (): void {
    $response = JsonResponse::error(422, 'validation_error', 'Некорректные данные');
    assertSame(422, $response->status(), 'status');
    assertSame('{"ok":false,"error":{"code":"validation_error","message":"Некорректные данные"}}', $response->body(), 'body');
};

$tests['content type cannot be overridden'] = static function (): void {
    $response = JsonResponse::success(['a' => 1])->withHeader('Content-Type', 'text/html');
    assertSame('application/json; charset=utf-8', $response->headers()['Content-Type'], 'content type');
};

$tests['request path normalisation'] = static function (): void {
    $cases = [
        '/api/health' => '/health',
        '/api/health/' => '/health',
        '/api/health?verbose=1' => '/health',
        '/api/index.php/health' => '/health',
        '/api/' => '/',
        '/api' => '/',
        '/api/%68ealth' => '/health',
    ];
    foreach ($cases as $uri => $expected) {
        assertSame($expected, Request::create('GET', $uri, '/api')->path(), $uri);
    }
};

$tests['malformed requests are rejected with 400'] = static function (): void {
    [$app] = app(probe());
    $bad = [
        server('G E T', '/api/health'),
        server('GET', 'health'),
        server('GET', '//evil.example/api/health'),
        server('GET', '/api/%00health'),
        server('GET', '/api/%FF'),
        server('GET', '/api/' . str_repeat('a', 3000)),
        ['REQUEST_URI' => '/api/health'],
    ];
    foreach ($bad as $index => $serverVars) {
        $response = $app->handle($serverVars, 'req' . $index);
        assertSame(400, $response->status(), 'status #' . $index);
        assertSame('bad_request', decode($response)['error']['code'], 'code #' . $index);
    }
};

$tests['unknown route returns 404'] = static function (): void {
    [$app] = app(probe());
    $response = $app->handle(server('GET', '/api/clients'), 'req-404');
    assertSame(404, $response->status(), 'status');
    assertSame(['ok' => false, 'error' => ['code' => 'not_found', 'message' => 'Resource not found']], decode($response), 'body');
    assertSame('req-404', $response->headers()['X-Request-Id'], 'request id header');
};

$tests['unsupported method returns 405 with Allow'] = static function (): void {
    [$app] = app(probe());
    $response = $app->handle(server('POST', '/api/health'), 'req-405');
    assertSame(405, $response->status(), 'status');
    assertSame('method_not_allowed', decode($response)['error']['code'], 'code');
    assertSame('GET, HEAD', $response->headers()['Allow'], 'allow header');
};

$tests['router matches exact paths only'] = static function (): void {
    $router = new Router();
    $router->get('/health', static fn (): JsonResponse => JsonResponse::success(['x' => 1]));
    assertSame(200, $router->dispatch(Request::create('GET', '/api/health', '/api'))->status(), 'exact');
    assertSame(200, $router->dispatch(Request::create('HEAD', '/api/health', '/api'))->status(), 'head');

    try {
        $router->dispatch(Request::create('GET', '/api/health/extra', '/api'));
        throw new AssertionFailed('expected 404');
    } catch (ApiException $exception) {
        assertSame(404, $exception->status(), 'nested path status');
    }
};

$tests['health ok when database answers'] = static function (): void {
    $probe = probe();
    [$app] = app($probe);
    $response = $app->handle(server('GET', '/api/health'), 'req-ok');
    assertSame(200, $response->status(), 'status');
    assertSame(['ok' => true, 'data' => ['status' => 'ok', 'db' => true]], decode($response), 'body');
    assertSame(1, $probe->calls, 'ping calls');
};

$tests['health 503 configuration_error hides details'] = static function (): void {
    [$app, $logFile] = app(probe(new ConfigException('Configuration file is missing: /home/secret/config/local.php')));
    $response = $app->handle(server('GET', '/api/health'), 'req-cfg');
    assertSame(503, $response->status(), 'status');
    assertSame(['ok' => false, 'error' => ['code' => 'configuration_error', 'message' => 'Service is not configured']], decode($response), 'body');
    assertTrue(!str_contains($response->body(), '/home/secret'), 'no path in response');
    assertTrue(str_contains((string) file_get_contents($logFile), '"request_id":"req-cfg"'), 'logged with request id');
};

$tests['health 503 db_unavailable hides and redacts secrets'] = static function (): void {
    [$app, $logFile] = app(probe(new DatabaseUnavailableException('failed with password=hunter2 for mysql:host=db')));
    $response = $app->handle(server('GET', '/api/health'), 'req-db');
    assertSame(503, $response->status(), 'status');
    assertSame('db_unavailable', decode($response)['error']['code'], 'code');
    assertTrue(!str_contains($response->body(), 'hunter2') && !str_contains($response->body(), 'mysql:'), 'nothing leaked in response');

    $log = (string) file_get_contents($logFile);
    assertTrue(str_contains($log, '"code":"db_unavailable"'), 'code logged');
    assertTrue(!str_contains($log, 'hunter2'), 'password redacted in log');
};

$tests['unexpected exception returns generic 500'] = static function (): void {
    [$app, $logFile] = app(probe(new LogicException('SELECT * FROM secret_table')));
    $response = $app->handle(server('GET', '/api/health'), 'req-500');
    assertSame(500, $response->status(), 'status');
    assertSame(['ok' => false, 'error' => ['code' => 'internal_error', 'message' => 'Internal server error']], decode($response), 'body');
    assertTrue(!str_contains($response->body(), 'SELECT'), 'no SQL in response');
    assertTrue(str_contains((string) file_get_contents($logFile), 'LogicException'), 'details logged server-side');
};

$tests['db does not load config or connect on construction'] = static function (): void {
    $loads = 0;
    $db = new Db(static function () use (&$loads): Config {
        $loads++;
        throw new ConfigException('not configured');
    });
    assertSame(0, $loads, 'no config load on construct');

    try {
        $db->ping();
        throw new AssertionFailed('expected ConfigException');
    } catch (ConfigException) {
        assertSame(1, $loads, 'config loaded on first use');
    }
};

$tests['config validation'] = static function (): void {
    $valid = ['db' => ['host' => 'localhost', 'port' => 3306, 'name' => 'crm', 'user' => 'crm_user', 'password' => 'x']];
    assertSame('mysql:host=localhost;port=3306;dbname=crm;charset=utf8mb4', Config::fromArray($valid)->dbDsn(), 'dsn');

    $secret = 'S3cret-Value';
    $invalid = [
        'missing section' => [],
        'missing user' => ['db' => ['host' => 'h', 'name' => 'n', 'password' => $secret]],
        'empty name' => ['db' => ['host' => 'h', 'name' => ' ', 'user' => 'u', 'password' => $secret]],
        'bad port' => ['db' => ['host' => 'h', 'port' => '3306', 'name' => 'n', 'user' => 'u', 'password' => $secret]],
        'placeholder' => ['db' => ['host' => 'h', 'name' => 'n', 'user' => 'u', 'password' => Config::PLACEHOLDER]],
    ];
    foreach ($invalid as $label => $values) {
        try {
            Config::fromArray($values);
            throw new AssertionFailed('expected ConfigException: ' . $label);
        } catch (ConfigException $exception) {
            assertTrue(!str_contains($exception->getMessage(), $secret), 'no secret in message: ' . $label);
        }
    }
};

$tests['config file errors'] = static function (): void {
    foreach ([sys_get_temp_dir() . '/devis-crm-missing-' . bin2hex(random_bytes(4)) . '.php', __DIR__ . '/../../config/local.php.example'] as $path) {
        try {
            Config::fromFile($path);
            throw new AssertionFailed('expected ConfigException for ' . basename($path));
        } catch (ConfigException) {
            // Missing file and untouched example (placeholders) are both rejected.
        }
    }
};

$tests['logger falls back to error_log when the log directory is unusable'] = static function (): void {
    // A regular file where the log directory should be makes mkdir() fail.
    $blocker = sys_get_temp_dir() . '/devis-crm-api-blocker-' . bin2hex(random_bytes(4));
    file_put_contents($blocker, '');
    $fallback = sys_get_temp_dir() . '/devis-crm-api-fallback-' . bin2hex(random_bytes(4)) . '.log';
    $previous = ini_set('error_log', $fallback);
    try {
        (new Logger($blocker . '/logs/api.log'))->log('req-fallback', 'internal_error', 'message');
    } finally {
        if ($previous !== false) {
            ini_set('error_log', $previous);
        }
    }
    assertTrue(str_contains((string) @file_get_contents($fallback), 'req-fallback'), 'written to PHP error_log');
};

$tests['send() prints the body, except for HEAD'] = static function (): void {
    // Each call gets its own process: once this runner (or a previous send()) has printed
    // anything, headers count as sent and http_response_code() warns.
    // Only stdout is observable in CLI; HTTP status and headers are not checked here.
    $send = static fn (bool $includeBody): array => runPhpCode(sprintf(
        'set_error_handler(static function (int $severity, string $message): bool { throw new ErrorException($message, 0, $severity); });'
        . ' require %s;'
        . ' DevisCrm\Api\Http\JsonResponse::success(["status" => "ok"])->send(%s);',
        var_export(dirname(__DIR__) . '/src/bootstrap.php', true),
        $includeBody ? 'true' : 'false',
    ));

    $get = $send(true);
    assertSame(0, $get['exitCode'], 'GET exit code (stderr: ' . trim($get['stderr']) . ')');
    assertSame('', $get['stderr'], 'GET stderr');
    assertSame('{"ok":true,"data":{"status":"ok"}}', $get['stdout'], 'GET body');

    $head = $send(false);
    assertSame(0, $head['exitCode'], 'HEAD exit code (stderr: ' . trim($head['stderr']) . ')');
    assertSame('', $head['stderr'], 'HEAD stderr');
    assertSame('', $head['stdout'], 'HEAD body');
};

$tests['config rejects values that would inject DSN options'] = static function (): void {
    foreach (['host' => 'localhost;unix_socket=/tmp/x', 'name' => "crm\n"] as $key => $value) {
        $db = ['host' => 'localhost', 'name' => 'crm', 'user' => 'u', 'password' => 'p'];
        $db[$key] = $value;
        try {
            Config::fromArray(['db' => $db]);
            throw new AssertionFailed('expected ConfigException for db.' . $key);
        } catch (ConfigException $exception) {
            assertTrue(!str_contains($exception->getMessage(), 'unix_socket'), 'value not echoed for db.' . $key);
        }
    }
};

$tests['db connection failure maps to DatabaseUnavailableException without the user name'] = static function (): void {
    if (!extension_loaded('pdo_mysql')) {
        throw new SkipTest('pdo_mysql is not loaded');
    }

    // Port 1 on loopback is closed: the connection is refused locally, no remote host is contacted.
    $config = Config::fromArray(['db' => ['host' => '127.0.0.1', 'port' => 1, 'name' => 'crm', 'user' => 'audit_marker_user', 'password' => 'audit_marker_password']]);
    $db = new Db(static fn (): Config => $config);

    try {
        $db->ping();
        throw new AssertionFailed('expected DatabaseUnavailableException');
    } catch (DatabaseUnavailableException $exception) {
        assertTrue(!str_contains($exception->getMessage(), 'audit_marker'), 'no credentials in message');
    }
};

$tests['front controller answers JSON through run()'] = static function (): void {
    $output = runFrontController('GET', '/api/unknown?x=1', dirname(__DIR__) . '/public');
    assertSame('{"ok":false,"error":{"code":"not_found","message":"Resource not found"}}', $output, 'body');
};

$tests['front controller sends no body for HEAD'] = static function (): void {
    assertSame('', runFrontController('HEAD', '/api/unknown', dirname(__DIR__) . '/public'), 'body');
};

$tests['front controller refuses private code inside the document root'] = static function (): void {
    // Simulates uploading the whole repository into the site directory.
    $output = runFrontController('GET', '/api/unknown', dirname(__DIR__, 2));
    assertSame('{"ok":false,"error":{"code":"configuration_error","message":"Service is not configured"}}', $output, 'body');
};

$tests['front controller runs with the REG.RU layout (private code outside the document root)'] = static function (): void {
    $layout = makeDeployLayout();
    try {
        assertSame(NOT_FOUND_BODY, runFrontController('GET', '/api/unknown', $layout['docroot'], $layout['index']), 'body');
    } finally {
        removeTree($layout['home']);
    }
};

$tests['front controller refuses private code placed directly in the document root (M1)'] = static function (): void {
    // api/src uploaded to <docroot>/src: the code itself is public, while its parent is outside.
    $layout = makeDeployLayout(privateCodeInDocroot: true);
    try {
        assertSame(REFUSED_BODY, runFrontController('GET', '/api/unknown', $layout['docroot'], $layout['index']), 'body');
    } finally {
        removeTree($layout['home']);
    }
};

$tests['front controller compares whole directory names, not string prefixes'] = static function (): void {
    // "<home>/devis" is a string prefix of "<home>/devis-crm" but not its parent directory.
    $layout = makeDeployLayout();
    try {
        mkdir($layout['home'] . '/devis');
        assertSame(NOT_FOUND_BODY, runFrontController('GET', '/api/unknown', $layout['home'] . '/devis', $layout['index']), 'body');
    } finally {
        removeTree($layout['home']);
    }
};

$tests['front controller refuses when the document root is empty or cannot be resolved'] = static function (): void {
    $missing = sys_get_temp_dir() . '/devis-crm-missing-docroot-' . bin2hex(random_bytes(6));
    foreach (['' => 'empty', $missing => 'unresolvable'] as $documentRoot => $label) {
        assertSame(REFUSED_BODY, runFrontController('GET', '/api/unknown', (string) $documentRoot), $label);
    }
};

$failed = 0;
$skipped = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "ok   - {$name}\n";
    } catch (SkipTest $skip) {
        $skipped++;
        echo "skip - {$name}: " . $skip->getMessage() . "\n";
    } catch (Throwable $throwable) {
        $failed++;
        echo "FAIL - {$name}: " . $throwable::class . ': ' . $throwable->getMessage() . "\n";
    }
}

echo sprintf("\n%d tests, %d failed, %d skipped\n", count($tests), $failed, $skipped);
exit($failed === 0 ? 0 : 1);
