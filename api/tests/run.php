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
use DevisCrm\Api\Auth\AuthService;
use DevisCrm\Api\Auth\AuthSession;
use DevisCrm\Api\Auth\AuthSettings;
use DevisCrm\Api\Auth\AuthStore;
use DevisCrm\Api\Auth\Clock;
use DevisCrm\Api\Auth\PdoAuthStore;
use DevisCrm\Api\Auth\RequestGuard;
use DevisCrm\Api\Auth\UserRecord;
use DevisCrm\Api\Clients\ClientDeletion;
use DevisCrm\Api\Clients\ClientStore;
use DevisCrm\Api\Clients\ClientValidator;
use DevisCrm\Api\Clients\PdoClientStore;
use DevisCrm\Api\Config;
use DevisCrm\Api\DatabaseProbe;
use DevisCrm\Api\Db;
use DevisCrm\Api\Exceptions\ApiException;
use DevisCrm\Api\Exceptions\ConfigException;
use DevisCrm\Api\Exceptions\DatabaseUnavailableException;
use DevisCrm\Api\Http\JsonResponse;
use DevisCrm\Api\Http\Request;
use DevisCrm\Api\Http\Router;
use DevisCrm\Api\Import\BackupAnalyzer;
use DevisCrm\Api\Import\BackupImporter;
use DevisCrm\Api\Import\PdoImportStore;
use DevisCrm\Api\Logging\Logger;
use DevisCrm\Api\Orders\Money;
use DevisCrm\Api\Orders\OrderValidator;
use DevisCrm\Api\Orders\PdoOrderStore;
use DevisCrm\Api\Validation\Fields;
use DevisCrm\Tools\OwnerPassword\ToolError;

use function DevisCrm\Tools\OwnerPassword\chooseAlgorithm;
use function DevisCrm\Tools\OwnerPassword\createHash;
use function DevisCrm\Tools\OwnerPassword\insertStatement;
use function DevisCrm\Tools\OwnerPassword\newUuid;
use function DevisCrm\Tools\OwnerPassword\validateLogin;
use function DevisCrm\Tools\OwnerPassword\validatePassword;

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
    $router->get('/health', static fn (): JsonResponse => JsonResponse::success(['x' => 1]), public: true);
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

const OWNER_PASSWORD_TOOL = __DIR__ . '/../bin/hash-owner-password.php';

/**
 * Runs a command with piped stdin (never a terminal) and captures its output.
 *
 * @param list<string> $command
 * @return array{stdout: string, stderr: string, exitCode: int}
 */
function runCommand(array $command, string $stdin): array
{
    if (!function_exists('proc_open') || PHP_BINARY === '') {
        throw new SkipTest('proc_open or PHP_BINARY is unavailable');
    }

    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new AssertionFailed('could not start a subprocess');
    }
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => proc_close($process)];
}

function loadOwnerPasswordTool(): void
{
    if (!defined('DEVIS_OWNER_PASSWORD_NO_MAIN')) {
        define('DEVIS_OWNER_PASSWORD_NO_MAIN', true);
    }
    require_once OWNER_PASSWORD_TOOL;
}

$tests['migration 002_auth defines the auth tables, keys and indexes'] = static function (): void {
    $sql = (string) file_get_contents(dirname(__DIR__) . '/migrations/002_auth.sql');
    $code = (string) preg_replace('/^\s*--.*$/m', '', $sql);

    foreach ([
        'users table' => '/CREATE TABLE `users` \(/',
        'unique login' => '/UNIQUE KEY `uq_users_login` \(`login`\)/',
        'password hash column' => '/`password_hash` VARCHAR\(255\)/',
        'sessions table' => '/CREATE TABLE `sessions` \(/',
        'unique token hash' => '/UNIQUE KEY `uq_sessions_token_hash` \(`token_hash`\)/',
        'csrf token column' => '/`csrf_token` CHAR\(64\)/',
        'session timestamps' => '/`created_at` DATETIME\(3\) NOT NULL,\s+`last_seen_at` DATETIME\(3\) NOT NULL,\s+`expires_at` DATETIME\(3\) NOT NULL/',
        'expired sessions index' => '/KEY `idx_sessions_expires_at` \(`expires_at`\)/',
        'sessions cascade with the user' => '/FOREIGN KEY \(`user_id`\) REFERENCES `users` \(`id`\)\s+ON DELETE CASCADE/',
        'login_attempts table' => '/CREATE TABLE `login_attempts` \(/',
        'attempts by login and time' => '/KEY `idx_login_attempts_login_time` \(`login_normalized`, `attempted_at`\)/',
        'attempts by IP and time' => '/KEY `idx_login_attempts_ip_time` \(`ip_address`, `attempted_at`\)/',
        'attempts by time' => '/KEY `idx_login_attempts_time` \(`attempted_at`\)/',
        'attempt result' => '/`succeeded` TINYINT\(1\) NOT NULL/',
        'migration recorded' => "/INSERT INTO `schema_migrations` \\(`version`\\) VALUES \\('002_auth'\\);/",
    ] as $label => $pattern) {
        assertTrue(preg_match($pattern, $code) === 1, $label);
    }

    assertTrue(preg_match('/^\s*(DROP|TRUNCATE|DELETE|ALTER|UPDATE|GRANT|REPLACE)\b/im', $code) === 0, 'no destructive statements');
    assertTrue(preg_match('/IF NOT EXISTS/i', $code) === 0, 'a second import fails instead of being skipped');
    assertSame(1, preg_match_all('/\bINSERT INTO\b/i', $code), 'the only INSERT records the migration (no users, hashes or tokens)');
    assertTrue(preg_match('/CREATE TABLE `(schema_migrations|clients|orders|order_items|payments|app_meta)`/', $code) === 0, '001_init tables are not recreated');
};

$tests['read-only check of 002_auth covers every column, index and key of the migration'] = static function (): void {
    $migration = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(dirname(__DIR__) . '/migrations/002_auth.sql'));
    $check = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(dirname(__DIR__) . '/migrations/checks/002_auth_check.sql'));

    assertSame(1, preg_match_all('/;/', $check), 'a single statement');
    assertTrue(preg_match('/^\s*SELECT\b/', $check) === 1, 'the statement is a SELECT');
    $statements = (string) preg_replace("/'[^']*'/", "''", $check);
    assertTrue(preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|TRUNCATE|GRANT|SET)\b/i', $statements) === 0, 'nothing that writes or changes the session');
    assertTrue(preg_match('/password_hash`?\s*(FROM|,)|FROM\s+`?(users|sessions|login_attempts)`?/i', $check) === 0, 'reads no rows of the auth tables');

    preg_match_all('/CREATE TABLE `(\w+)` \((.*?)\) ENGINE/s', $migration, $tables, PREG_SET_ORDER);
    assertSame(3, count($tables), 'three tables in the migration');
    foreach ($tables as [, $table, $body]) {
        preg_match_all('/^\s+`(\w+)` [A-Z]/m', $body, $columns);
        foreach ($columns[1] as $column) {
            assertTrue(str_contains($check, "'{$table}.{$column}'"), "column {$table}.{$column} is checked");
        }
        preg_match_all('/KEY `(\w+)`/', $body, $indexes);
        foreach ($indexes[1] as $index) {
            assertTrue(str_contains($check, "'index {$table}.{$index}'"), "index {$table}.{$index} is checked");
        }
        assertTrue(str_contains($check, "'index {$table}.PRIMARY'"), "primary key of {$table} is checked");
    }
    assertSame(substr_count($migration, 'CHECK ('), (int) (preg_match("/'CHECK constraints on the three tables', '(\\d+)'/", $check, $m) === 1 ? $m[1] : -1), 'number of CHECK constraints');
    assertTrue(str_contains($check, "'foreign key fk_sessions_user'"), 'foreign key is checked');
};

function loadMigrationRunner(): void
{
    if (!function_exists('DevisCrm\Tools\Migrate002\classify')) {
        define('DEVIS_MIGRATE_002_NO_MAIN', true);
        require dirname(__DIR__) . '/bin/migrate-002-auth.php';
    }
}

$tests['migration runner pins the reviewed 002_auth files and their statements'] = static function (): void {
    loadMigrationRunner();
    $migration = (string) file_get_contents(dirname(__DIR__) . '/migrations/002_auth.sql');
    $check = (string) file_get_contents(dirname(__DIR__) . '/migrations/checks/002_auth_check.sql');
    assertSame(\DevisCrm\Tools\Migrate002\MIGRATION_SHA256, \DevisCrm\Tools\Migrate002\normalizedHash($migration), 'pinned hash of 002_auth.sql');
    assertSame(\DevisCrm\Tools\Migrate002\CHECK_SHA256, \DevisCrm\Tools\Migrate002\normalizedHash($check), 'pinned hash of the check');
    assertSame(\DevisCrm\Tools\Migrate002\normalizedHash($migration), \DevisCrm\Tools\Migrate002\normalizedHash(str_replace("\n", "\r\n", str_replace("\r\n", "\n", $migration))), 'CRLF and LF copies hash alike');

    $statements = \DevisCrm\Tools\Migrate002\migrationStatements($migration);
    assertSame('SET NAMES utf8mb4', $statements['set'], 'SET NAMES');
    assertSame(3, count($statements['creates']), 'three CREATE TABLE');
    assertTrue(str_starts_with($statements['creates'][2], 'CREATE TABLE `login_attempts`') && str_ends_with($statements['creates'][2], 'COLLATE=utf8mb4_unicode_ci'), 'last CREATE complete');
    assertSame("INSERT INTO `schema_migrations` (`version`) VALUES ('002_auth')", $statements['record'], 'record statement');
    foreach (['an extra statement' => $migration . "\nDELETE FROM clients;\n", 'a missing table' => str_replace('CREATE TABLE `sessions`', 'CREATE TABLE `other`', $migration)] as $label => $changed) {
        try {
            \DevisCrm\Tools\Migrate002\migrationStatements($changed);
            throw new AssertionFailed($label . ' accepted');
        } catch (\DevisCrm\Tools\Migrate002\RunnerError) {
        }
    }
};

$tests['migration runner applies only from a clean state and judges the schema check'] = static function (): void {
    loadMigrationRunner();
    assertSame('NOT_APPLIED', \DevisCrm\Tools\Migrate002\classify(true, false, 0), 'clean');
    assertSame('APPLIED', \DevisCrm\Tools\Migrate002\classify(true, true, 3), 'applied');
    assertSame('NO_001_INIT', \DevisCrm\Tools\Migrate002\classify(false, false, 0), '001_init missing');
    foreach ([[true, false, 1], [true, false, 3], [true, true, 2], [true, true, 0]] as [$has001, $has002, $tables]) {
        assertSame('PARTIAL', \DevisCrm\Tools\Migrate002\classify($has001, $has002, $tables), "partial $tables tables");
    }

    $rows = [
        ['check_name' => 'state', 'status' => 'MISMATCH'],
        ['check_name' => 'migration 002_auth recorded', 'status' => 'MISMATCH'],
        ['check_name' => 'users.id', 'status' => 'OK'],
        ['check_name' => 'CHECK constraints on the three tables', 'status' => 'MISMATCH'],
    ];
    assertSame(['CHECK constraints on the three tables'], \DevisCrm\Tools\Migrate002\failedChecks($rows, false, '8.0.36'), 'before the record only the schema counts');
    assertSame([], \DevisCrm\Tools\Migrate002\failedChecks($rows, false, '8.0.15-log'), 'CHECK constraints are not listed before 8.0.16');
    assertSame(['state', 'migration 002_auth recorded', 'CHECK constraints on the three tables'], \DevisCrm\Tools\Migrate002\failedChecks($rows, true, '8.4.2'), 'after the record every row counts');
    assertSame(['users.id'], \DevisCrm\Tools\Migrate002\failedChecks([['check_name' => 'users.id', 'status' => 'MISMATCH']], false, '8.0.36'), 'a column mismatch fails');
};

$tests['migration runner refuses to run over HTTP or with unknown options'] = static function (): void {
    $runner = dirname(__DIR__) . '/bin/migrate-002-auth.php';
    $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php-cgi.exe' : 'php-cgi');
    if (is_file($cgi)) {
        $result = runCommand([$cgi, '-q', $runner], '');
        assertSame('', trim($result['stdout']), 'nothing printed over CGI');
    }
    $root = sys_get_temp_dir() . '/devis-crm-migrate-' . bin2hex(random_bytes(6));
    $result = runCommand([PHP_BINARY, $runner, '--force'], '');
    assertSame(1, $result['exitCode'], 'unknown option refused');
    assertTrue(str_contains($result['stdout'], 'refused: the only option is --apply'), 'refusal message');
    assertTrue(!is_dir($root), 'no files created');
};

$tests['owner password tool refuses to run outside the command line'] = static function (): void {
    $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php-cgi.exe' : 'php-cgi');
    if (!is_file($cgi)) {
        throw new SkipTest('php-cgi is not available next to PHP_BINARY');
    }

    $result = runCommand([$cgi, '-q', OWNER_PASSWORD_TOOL], "owner\n");
    assertTrue(str_contains($result['stdout'], 'This tool runs only from the command line.'), 'refusal message');
    assertTrue(!str_contains($result['stdout'] . $result['stderr'], 'Password'), 'no password prompt');
    assertTrue(!str_contains($result['stdout'], 'password_hash'), 'no hash output');
};

$tests['owner password tool takes no arguments and never reads a password from a pipe'] = static function (): void {
    $marker = 'test-password-' . bin2hex(random_bytes(8));

    $withArgument = runCommand([PHP_BINARY, OWNER_PASSWORD_TOOL, $marker], '');
    assertSame(1, $withArgument['exitCode'], 'argument: exit code');
    assertTrue(str_contains($withArgument['stderr'], 'takes no arguments'), 'argument: message');
    assertTrue(!str_contains($withArgument['stdout'] . $withArgument['stderr'], $marker), 'argument: value is not echoed');

    foreach ([[], ['--bcrypt']] as $options) {
        $piped = runCommand([PHP_BINARY, OWNER_PASSWORD_TOOL, ...$options], "owner\n{$marker}\n{$marker}\n");
        assertSame(1, $piped['exitCode'], 'pipe: exit code');
        assertTrue(str_contains($piped['stderr'], 'not an interactive terminal'), 'pipe: refuses without a terminal');
        assertTrue(!str_contains($piped['stdout'] . $piped['stderr'], $marker), 'pipe: password is not echoed');
        assertSame('', $piped['stdout'], 'pipe: no hash printed');
    }

    $twoOptions = runCommand([PHP_BINARY, OWNER_PASSWORD_TOOL, '--bcrypt', '--x'], '');
    assertTrue(str_contains($twoOptions['stderr'], 'takes no arguments'), 'only --bcrypt is accepted');
};

$tests['owner password tool rejects an empty or too short password'] = static function (): void {
    loadOwnerPasswordTool();
    $algorithm = chooseAlgorithm();

    assertSame('password must not be empty', validatePassword('', $algorithm), 'empty');
    assertTrue(validatePassword('short', $algorithm) !== null, 'too short');
    assertTrue(validatePassword(str_repeat('a', 73), PASSWORD_BCRYPT) !== null, 'bcrypt would truncate after 72 bytes');
    try {
        createHash('', $algorithm);
        throw new AssertionFailed('an empty password was hashed');
    } catch (ToolError) {
    }

    assertSame(null, validateLogin('owner'), 'valid login');
    assertTrue(validateLogin("own'er") !== null, 'quote in login rejected');
    assertTrue(validateLogin('ab') !== null, 'too short login rejected');
};

$tests['owner password tool hash verifies with password_verify'] = static function (): void {
    loadOwnerPasswordTool();
    $password = 'test-' . bin2hex(random_bytes(16));
    $algorithm = chooseAlgorithm();

    $hash = createHash($password, $algorithm);
    assertTrue(password_verify($password, $hash), 'the password verifies');
    assertTrue(!password_verify($password . 'x', $hash), 'another password does not verify');
    $expected = defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true) ? 'argon2id' : 'bcrypt';
    assertSame($expected, password_get_info($hash)['algoName'], 'algorithm');
    $bcryptHash = createHash($password, chooseAlgorithm(true));
    assertSame('bcrypt', password_get_info($bcryptHash)['algoName'], '--bcrypt algorithm');
    assertTrue(password_verify($password, $bcryptHash), '--bcrypt hash verifies');

    $sql = insertStatement(newUuid(), 'owner', $hash);
    assertTrue(str_contains($sql, "'owner'") && str_contains($sql, $hash), 'INSERT contains the login and the hash');
    assertTrue(!str_contains($sql, $password), 'INSERT does not contain the password');
};

// ---------------------------------------------------------------------------------------------
// Owner authentication (stage 4.2). Logic runs against an in-memory store; the SQL of
// PdoAuthStore runs against SQLite when pdo_sqlite is loaded. MySQL itself is not used here.

final class MutableClock implements Clock
{
    public function __construct(public DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify('+' . $seconds . ' seconds');
    }
}

class InMemoryAuthStore implements AuthStore
{
    /** @var array<string, UserRecord> */
    public array $users = [];
    /** @var array<string, array{session: AuthSession, tokenHash: string}> */
    public array $sessions = [];
    /** @var list<array{login: string, ip: string, at: DateTimeImmutable, succeeded: bool}> */
    public array $attempts = [];
    public int $touches = 0;

    public function findUserByLogin(string $normalizedLogin): ?UserRecord
    {
        foreach ($this->users as $user) {
            if ($user->login === $normalizedLogin) {
                return $user;
            }
        }

        return null;
    }

    public function createSession(AuthSession $session, string $tokenHash): void
    {
        $this->sessions[$session->id] = ['session' => clone $session, 'tokenHash' => $tokenHash];
    }

    public function findSessionByTokenHash(string $tokenHash): ?AuthSession
    {
        foreach ($this->sessions as $row) {
            if ($row['tokenHash'] === $tokenHash) {
                return clone $row['session'];
            }
        }

        return null;
    }

    public function touchSession(string $sessionId, DateTimeImmutable $lastSeenAt): void
    {
        $this->touches++;
        if (isset($this->sessions[$sessionId])) {
            $this->sessions[$sessionId]['session']->lastSeenAt = $lastSeenAt;
        }
    }

    public function deleteSession(string $sessionId): void
    {
        unset($this->sessions[$sessionId]);
    }

    public function deleteExpiredSessions(DateTimeImmutable $now, DateTimeImmutable $idleCutoff): void
    {
        foreach ($this->sessions as $id => $row) {
            if ($row['session']->expiresAt <= $now || $row['session']->lastSeenAt <= $idleCutoff) {
                unset($this->sessions[$id]);
            }
        }
    }

    public function recordAttempt(string $normalizedLogin, string $ipAddress, DateTimeImmutable $at, bool $succeeded): void
    {
        $this->attempts[] = ['login' => $normalizedLogin, 'ip' => $ipAddress, 'at' => $at, 'succeeded' => $succeeded];
    }

    public function failureTimesForLogin(string $normalizedLogin, DateTimeImmutable $since): array
    {
        return $this->failures('login', $normalizedLogin, $since);
    }

    public function failureTimesForIp(string $ipAddress, DateTimeImmutable $since): array
    {
        return $this->failures('ip', $ipAddress, $since);
    }

    public function purgeAttemptsBefore(DateTimeImmutable $cutoff): void
    {
        $this->attempts = array_values(array_filter($this->attempts, static fn (array $a): bool => $a['at'] >= $cutoff));
    }

    /** @return list<DateTimeImmutable> */
    private function failures(string $key, string $value, DateTimeImmutable $since): array
    {
        $times = [];
        foreach ($this->attempts as $attempt) {
            if (!$attempt['succeeded'] && $attempt[$key] === $value && $attempt['at'] >= $since) {
                $times[] = $attempt['at'];
            }
        }
        sort($times);

        return $times;
    }
}

/**
 * @return array{app: Application, store: InMemoryAuthStore, clock: MutableClock, auth: AuthService, password: string, hash: string, log: string}
 */
function authContext(): array
{
    $store = new InMemoryAuthStore();
    $clock = new MutableClock(new DateTimeImmutable('2026-10-26 10:00:00.000', new DateTimeZone('UTC')));
    $password = 'test-' . bin2hex(random_bytes(12));
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $store->users['owner'] = new UserRecord('00000000-0000-4000-8000-000000000001', 'owner', $hash);
    $auth = new AuthService($store, $clock);
    $log = tempLogFile();

    return [
        'app' => new Application(Application::routes(probe(), $auth), new Logger($log)),
        'store' => $store,
        'clock' => $clock,
        'auth' => $auth,
        'password' => $password,
        'hash' => $hash,
        'log' => $log,
    ];
}

/**
 * @param array<string, string> $headers
 */
function authRequest(Application $app, string $method, string $uri, array $headers = [], string $body = '', string $remote = '127.0.0.1'): JsonResponse
{
    $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => $remote];
    foreach ($headers as $name => $value) {
        $key = strtoupper(str_replace('-', '_', $name));
        $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_' . $key] = $value;
    }

    return $app->handle($server, 'req-' . bin2hex(random_bytes(3)), static fn (int $max): string => substr($body, 0, $max));
}

/**
 * @param array<string, string> $extraHeaders
 */
function loginRequest(array $ctx, string $login, string $password, string $remote = '127.0.0.1', array $extraHeaders = []): JsonResponse
{
    $headers = ['Origin' => AuthSettings::ALLOWED_ORIGIN, 'Content-Type' => 'application/json'] + $extraHeaders;

    return authRequest($ctx['app'], 'POST', '/api/auth/login', $headers, json_encode(['login' => $login, 'password' => $password]), $remote);
}

/** @return list<string> */
function setCookies(JsonResponse $response): array
{
    $values = [];
    foreach ($response->headerLines() as [$name, $value]) {
        if (strcasecmp($name, 'Set-Cookie') === 0) {
            $values[] = $value;
        }
    }

    return $values;
}

function sessionTokenFrom(JsonResponse $response): string
{
    $cookies = setCookies($response);
    assertSame(1, count($cookies), 'exactly one Set-Cookie');
    assertTrue(preg_match('/^' . preg_quote(AuthSettings::COOKIE_NAME, '/') . '=([A-Za-z0-9_-]{43});/', $cookies[0], $match) === 1, 'session token in cookie');

    return $match[1];
}

/**
 * @return array<string, string>
 */
function sessionHeaders(string $token, ?string $csrf = null, ?string $origin = AuthSettings::ALLOWED_ORIGIN): array
{
    $headers = ['Cookie' => 'other=1; ' . AuthSettings::COOKIE_NAME . '=' . $token];
    if ($origin !== null) {
        $headers['Origin'] = $origin;
    }
    if ($csrf !== null) {
        $headers['X-CSRF-Token'] = $csrf;
    }

    return $headers;
}

$tests['request exposes headers, cookies and the client address'] = static function (): void {
    $request = Request::fromServer([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/auth/logout',
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_ORIGIN' => 'https://crm.devisd.online',
        'HTTP_X_CSRF_TOKEN' => 'abc',
        'HTTP_COOKIE' => 'a=1; __Host-devis_session=tok; b=2',
        'HTTP_X_FORWARDED_FOR' => '8.8.8.8',
        'CONTENT_TYPE' => 'application/json',
    ], '/api');

    assertSame('POST', $request->method(), 'method');
    assertSame('/auth/logout', $request->path(), 'path');
    assertSame('https://crm.devisd.online', $request->header('Origin'), 'origin (case-insensitive)');
    assertSame('abc', $request->header('x-csrf-token'), 'csrf header');
    assertSame('application/json', $request->header('Content-Type'), 'content type');
    assertSame('tok', $request->cookie('__Host-devis_session'), 'cookie');
    assertSame(null, $request->cookie('missing'), 'missing cookie');
    assertSame('127.0.0.1', $request->remoteAddress(), 'REMOTE_ADDR, X-Forwarded-For ignored');
    assertSame('', Request::fromServer(['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => 'not-an-ip'], '/api')->remoteAddress(), 'invalid address');
};

$tests['request parses a JSON object body'] = static function (): void {
    $request = Request::create('POST', '/api/auth/login', '/api', ['Content-Type' => 'application/json; charset=utf-8'], '', static fn (int $max): string => '{"login":"owner","n":1}');
    assertSame(['login' => 'owner', 'n' => 1], $request->jsonBody(), 'decoded');
};

$tests['request rejects missing, malformed, non-object and oversized bodies'] = static function (): void {
    $json = ['Content-Type' => 'application/json'];
    $cases = [
        'empty body' => [$json, '', 400, 'invalid_json'],
        'malformed' => [$json, '{"login":', 400, 'invalid_json'],
        'array' => [$json, '[1,2]', 400, 'invalid_json'],
        'string' => [$json, '"text"', 400, 'invalid_json'],
        'wrong content type' => [['Content-Type' => 'text/plain'], '{}', 415, 'unsupported_media_type'],
        'no content type' => [[], '{}', 415, 'unsupported_media_type'],
        'declared too large' => [$json + ['Content-Length' => '999999'], '{}', 413, 'payload_too_large'],
        'actually too large' => [$json, '{"a":"' . str_repeat('x', Request::MAX_BODY_BYTES) . '"}', 413, 'payload_too_large'],
    ];
    foreach ($cases as $label => [$headers, $body, $status, $code]) {
        $request = Request::create('POST', '/api/auth/login', '/api', $headers, '', static fn (int $max): string => substr($body, 0, $max));
        try {
            $request->jsonBody();
            throw new AssertionFailed($label . ': expected an error');
        } catch (ApiException $exception) {
            assertSame($status, $exception->status(), $label . ' status');
            assertSame($code, $exception->errorCode(), $label . ' code');
        }
    }
};

$tests['response keeps repeated Set-Cookie headers when sending'] = static function (): void {
    $response = JsonResponse::success([])->withAddedHeader('Set-Cookie', 'a=1')->withAddedHeader('Set-Cookie', 'b=2');
    assertSame(['a=1', 'b=2'], setCookies($response), 'both header lines kept');

    $result = runPhpCode(sprintf(
        'require %s; $lines = []; ob_start();'
        . ' DevisCrm\Api\Http\JsonResponse::success([])->withAddedHeader("Set-Cookie", "a=1")->withAddedHeader("Set-Cookie", "b=2")'
        . '->send(true, static function (string $line, bool $replace) use (&$lines): void { $lines[] = [$line, $replace]; });'
        . ' $body = ob_get_clean(); echo json_encode(["lines" => $lines, "body" => $body]);',
        var_export(dirname(__DIR__) . '/src/bootstrap.php', true),
    ));
    assertSame('', $result['stderr'], 'no warnings');
    $sent = json_decode($result['stdout'], true, 8, JSON_THROW_ON_ERROR);
    assertTrue(in_array(['Set-Cookie: a=1', true], $sent['lines'], true), 'first Set-Cookie replaces defaults');
    assertTrue(in_array(['Set-Cookie: b=2', false], $sent['lines'], true), 'second Set-Cookie is added, not replacing');
    assertTrue(in_array(['Content-Type: application/json; charset=utf-8', true], $sent['lines'], true), 'content type sent');
    assertSame('{"ok":true,"data":{}}', $sent['body'], 'body');

    try {
        JsonResponse::success([])->withAddedHeader('Set-Cookie', "a=1\r\nX-Injected: 1");
        throw new AssertionFailed('header injection accepted');
    } catch (InvalidArgumentException) {
    }
};

$tests['routes are protected unless public'] = static function (): void {
    $ctx = authContext();
    $router = new Router(new RequestGuard($ctx['auth']));
    $router->get('/open', static fn (): JsonResponse => JsonResponse::success(['open' => true]), public: true);
    $router->get('/secret', static fn (): JsonResponse => JsonResponse::success(['secret' => true]));

    assertSame(200, $router->dispatch(Request::create('GET', '/api/open', '/api'))->status(), 'public route');
    foreach ([['GET', '/api/secret', 401], ['GET', '/api/missing', 404], ['POST', '/api/open', 405]] as [$method, $uri, $status]) {
        try {
            $router->dispatch(Request::create($method, $uri, '/api'));
            throw new AssertionFailed("$method $uri: expected $status");
        } catch (ApiException $exception) {
            assertSame($status, $exception->status(), "$method $uri");
        }
    }

    $unguarded = new Router();
    $unguarded->get('/secret', static fn (): JsonResponse => JsonResponse::success([]));
    try {
        $unguarded->dispatch(Request::create('GET', '/api/secret', '/api'));
        throw new AssertionFailed('protected route ran without a guard');
    } catch (LogicException) {
    }
};

$tests['login rejects wrong credentials with one identical response'] = static function (): void {
    $ctx = authContext();
    $unknown = loginRequest($ctx, 'nobody', $ctx['password']);
    $wrongPassword = loginRequest($ctx, 'owner', 'wrong-' . $ctx['password']);

    assertSame(401, $unknown->status(), 'unknown login status');
    assertSame(401, $wrongPassword->status(), 'wrong password status');
    assertSame($unknown->body(), $wrongPassword->body(), 'identical bodies');
    assertSame(['ok' => false, 'error' => ['code' => 'invalid_credentials', 'message' => 'Invalid login or password']], decode($unknown), 'body');
    assertSame([], setCookies($unknown), 'no cookie (unknown)');
    assertSame([], setCookies($wrongPassword), 'no cookie (wrong password)');
    assertSame(2, count($ctx['store']->attempts), 'both attempts recorded');
    assertSame([false, false], array_column($ctx['store']->attempts, 'succeeded'), 'recorded as failures');
    assertSame([], $ctx['store']->sessions, 'no session');
};

$tests['login validates input before counting attempts'] = static function (): void {
    $ctx = authContext();
    $headers = ['Origin' => AuthSettings::ALLOWED_ORIGIN, 'Content-Type' => 'application/json'];
    $bodies = [
        'missing password' => '{"login":"owner"}',
        'non-string login' => '{"login":5,"password":"x"}',
        'blank login' => '{"login":"   ","password":"x"}',
        'empty password' => '{"login":"owner","password":""}',
        'long login' => json_encode(['login' => str_repeat('a', 65), 'password' => 'x']),
        'long password' => json_encode(['login' => 'owner', 'password' => str_repeat('p', 1025)]),
    ];
    foreach ($bodies as $label => $body) {
        $response = authRequest($ctx['app'], 'POST', '/api/auth/login', $headers, $body);
        assertSame(400, $response->status(), $label);
        assertSame('validation_error', decode($response)['error']['code'], $label . ' code');
    }
    assertSame([], $ctx['store']->attempts, 'invalid input is not an attempt');
};

$tests['login requires POST and the exact CRM origin'] = static function (): void {
    $ctx = authContext();
    foreach ([null, 'https://crm.devisd.online.evil.example', 'http://crm.devisd.online', 'https://evil.example', 'null'] as $origin) {
        $headers = ['Content-Type' => 'application/json'];
        if ($origin !== null) {
            $headers['Origin'] = $origin;
        }
        $response = authRequest($ctx['app'], 'POST', '/api/auth/login', $headers, json_encode(['login' => 'owner', 'password' => $ctx['password']]));
        assertSame(403, $response->status(), 'origin ' . var_export($origin, true));
        assertSame('origin_not_allowed', decode($response)['error']['code'], 'code for ' . var_export($origin, true));
        assertSame([], setCookies($response), 'no cookie');
    }
    assertSame(405, authRequest($ctx['app'], 'GET', '/api/auth/login')->status(), 'GET login');
    assertSame([], $ctx['store']->attempts, 'rejected requests are not attempts');
};

$tests['successful login creates a server session and a hardened cookie'] = static function (): void {
    $ctx = authContext();
    $response = loginRequest($ctx, '  OWNER ', $ctx['password']);
    assertSame(200, $response->status(), 'status');

    $data = decode($response)['data'];
    assertSame(['login' => 'owner'], $data['user'], 'user');
    assertTrue(preg_match('/^[0-9a-f]{64}$/', $data['csrfToken']) === 1, 'csrf token');

    $cookie = setCookies($response)[0];
    $token = sessionTokenFrom($response);
    foreach (['; Path=/', '; Secure', '; HttpOnly', '; SameSite=Strict', '; Max-Age=' . AuthSettings::ABSOLUTE_LIFETIME_SECONDS . ';'] as $attribute) {
        assertTrue(str_contains($cookie, $attribute), 'cookie attribute ' . $attribute);
    }
    assertTrue(stripos($cookie, 'domain=') === false, 'no Domain attribute');

    assertSame(1, count($ctx['store']->sessions), 'one session');
    $row = array_values($ctx['store']->sessions)[0];
    assertSame(hash('sha256', $token), $row['tokenHash'], 'only the SHA-256 of the token is stored');
    assertTrue(!str_contains(serialize($ctx['store']->sessions), $token), 'raw token not stored');
    assertSame($data['csrfToken'], $row['session']->csrfToken, 'csrf token stored with the session');

    foreach ([$token, $ctx['password'], $ctx['hash']] as $secret) {
        assertTrue(!str_contains($response->body(), $secret), 'no token, password or hash in the body');
    }
    assertSame([true], array_column($ctx['store']->attempts, 'succeeded'), 'success recorded');
};

$tests['/auth/me needs a valid session and returns only public fields'] = static function (): void {
    $ctx = authContext();
    $none = authRequest($ctx['app'], 'GET', '/api/auth/me');
    assertSame(401, $none->status(), 'no cookie');
    assertSame('unauthorized', decode($none)['error']['code'], 'code');

    $garbage = authRequest($ctx['app'], 'GET', '/api/auth/me', ['Cookie' => AuthSettings::COOKIE_NAME . '=' . str_repeat('A', 43)]);
    assertSame(401, $garbage->status(), 'unknown token');
    assertTrue(str_contains(setCookies($garbage)[0] ?? '', 'Max-Age=0'), 'stale cookie cleared');

    $login = loginRequest($ctx, 'owner', $ctx['password']);
    $token = sessionTokenFrom($login);
    $me = authRequest($ctx['app'], 'GET', '/api/auth/me', ['Cookie' => AuthSettings::COOKIE_NAME . '=' . $token]);
    assertSame(200, $me->status(), 'valid session');
    assertSame(['ok' => true, 'data' => ['user' => ['login' => 'owner'], 'csrfToken' => decode($login)['data']['csrfToken']]], decode($me), 'body');
    foreach ([$token, $ctx['hash'], 'password'] as $secret) {
        assertTrue(!str_contains($me->body(), $secret), 'no internals in /me');
    }
};

$tests['logout requires the origin and CSRF token, then ends the session'] = static function (): void {
    $ctx = authContext();
    $login = loginRequest($ctx, 'owner', $ctx['password']);
    $token = sessionTokenFrom($login);
    $csrf = decode($login)['data']['csrfToken'];

    $rejected = [
        'no origin' => [sessionHeaders($token, $csrf, null), 'origin_not_allowed'],
        'foreign origin' => [sessionHeaders($token, $csrf, 'https://evil.example'), 'origin_not_allowed'],
        'no csrf token' => [sessionHeaders($token), 'csrf_token_invalid'],
        'wrong csrf token' => [sessionHeaders($token, str_repeat('0', 64)), 'csrf_token_invalid'],
    ];
    foreach ($rejected as $label => [$headers, $code]) {
        $response = authRequest($ctx['app'], 'POST', '/api/auth/logout', $headers);
        assertSame(403, $response->status(), $label);
        assertSame($code, decode($response)['error']['code'], $label . ' code');
        assertSame(1, count($ctx['store']->sessions), $label . ': session kept');
    }

    $logout = authRequest($ctx['app'], 'POST', '/api/auth/logout', sessionHeaders($token, $csrf));
    assertSame(200, $logout->status(), 'logout');
    assertTrue(str_contains(setCookies($logout)[0] ?? '', AuthSettings::COOKIE_NAME . '=; Max-Age=0;'), 'cookie cleared');
    foreach (['; Path=/', '; Secure', '; HttpOnly', '; SameSite=Strict'] as $attribute) {
        assertTrue(str_contains(setCookies($logout)[0], $attribute), 'clearing cookie keeps ' . $attribute);
    }
    assertSame([], $ctx['store']->sessions, 'session deleted on the server');

    assertSame(401, authRequest($ctx['app'], 'GET', '/api/auth/me', sessionHeaders($token))->status(), 'old cookie no longer works');
    assertSame(401, authRequest($ctx['app'], 'POST', '/api/auth/logout', sessionHeaders($token, $csrf))->status(), 'second logout is 401');
    assertSame(405, authRequest($ctx['app'], 'GET', '/api/auth/logout', sessionHeaders($token, $csrf))->status(), 'logout via GET');
};

$tests['a new login replaces the previous session'] = static function (): void {
    $ctx = authContext();
    $first = sessionTokenFrom(loginRequest($ctx, 'owner', $ctx['password']));
    $second = sessionTokenFrom(loginRequest($ctx, 'owner', $ctx['password'], '127.0.0.1', ['Cookie' => AuthSettings::COOKIE_NAME . '=' . $first]));

    assertTrue($first !== $second, 'new token');
    assertSame(1, count($ctx['store']->sessions), 'previous session deleted');
    assertSame(401, authRequest($ctx['app'], 'GET', '/api/auth/me', sessionHeaders($first))->status(), 'old token rejected');
    assertSame(200, authRequest($ctx['app'], 'GET', '/api/auth/me', sessionHeaders($second))->status(), 'new token accepted');
};

$tests['sessions end after the idle timeout and the absolute lifetime'] = static function (): void {
    $ctx = authContext();
    $token = sessionTokenFrom(loginRequest($ctx, 'owner', $ctx['password']));
    $me = static fn (): int => authRequest($ctx['app'], 'GET', '/api/auth/me', sessionHeaders($token))->status();

    $ctx['clock']->advance(60);
    assertSame(200, $me(), 'active after a minute');
    assertSame(0, $ctx['store']->touches, 'last_seen_at not written within the touch interval');
    $ctx['clock']->advance(AuthSettings::TOUCH_INTERVAL_SECONDS);
    assertSame(200, $me(), 'active after the touch interval');
    assertSame(1, $ctx['store']->touches, 'last_seen_at written once');

    $ctx['clock']->advance(AuthSettings::IDLE_TIMEOUT_SECONDS);
    assertSame(401, $me(), 'idle timeout');
    assertSame([], $ctx['store']->sessions, 'idle session deleted');

    $ctx = authContext();
    $token = sessionTokenFrom(loginRequest($ctx, 'owner', $ctx['password']));
    $days = intdiv(AuthSettings::ABSOLUTE_LIFETIME_SECONDS, 86400);
    for ($day = 1; $day < $days; $day++) {
        $ctx['clock']->advance(86400);
        assertSame(200, authRequest($ctx['app'], 'GET', '/api/auth/me', sessionHeaders($token))->status(), 'active on day ' . $day);
    }
    $ctx['clock']->advance(86400);
    assertSame(401, authRequest($ctx['app'], 'GET', '/api/auth/me', sessionHeaders($token))->status(), 'absolute lifetime');
    assertSame([], $ctx['store']->sessions, 'expired session deleted');
};

$tests['failed logins are throttled per login with 429 and Retry-After'] = static function (): void {
    $ctx = authContext();
    for ($i = 0; $i < AuthSettings::MAX_FAILED_ATTEMPTS; $i++) {
        assertSame(401, loginRequest($ctx, 'owner', 'wrong-password-' . $i)->status(), 'failure ' . ($i + 1));
        $ctx['clock']->advance(10);
    }

    $blocked = loginRequest($ctx, 'owner', $ctx['password']);
    assertSame(429, $blocked->status(), 'blocked even with the right password');
    assertSame('too_many_attempts', decode($blocked)['error']['code'], 'code');
    $expected = AuthSettings::ATTEMPT_WINDOW_SECONDS - 10 * AuthSettings::MAX_FAILED_ATTEMPTS;
    assertSame((string) $expected, $blocked->headers()['Retry-After'] ?? null, 'Retry-After');
    assertSame(AuthSettings::MAX_FAILED_ATTEMPTS, count($ctx['store']->attempts), 'blocked attempts are not recorded');

    $ctx['clock']->advance($expected);
    assertSame(200, loginRequest($ctx, 'owner', $ctx['password'])->status(), 'allowed after Retry-After');

    $ctx = authContext();
    for ($i = 0; $i < AuthSettings::MAX_FAILED_ATTEMPTS - 1; $i++) {
        loginRequest($ctx, 'owner', 'wrong-password-' . $i);
    }
    assertSame(200, loginRequest($ctx, 'owner', $ctx['password'])->status(), 'success');
    assertSame(401, loginRequest($ctx, 'owner', 'wrong-again')->status(), 'a success does not count as a failure');
};

$tests['per-IP throttling applies only to public client addresses'] = static function (): void {
    $ctx = authContext();
    for ($i = 0; $i < AuthSettings::MAX_FAILED_ATTEMPTS; $i++) {
        loginRequest($ctx, 'user' . $i, 'wrong', '8.8.8.8');
    }
    assertSame(429, loginRequest($ctx, 'owner', $ctx['password'], '8.8.8.8')->status(), 'public IP blocked across logins');

    $ctx = authContext();
    for ($i = 0; $i < AuthSettings::MAX_FAILED_ATTEMPTS; $i++) {
        loginRequest($ctx, 'user' . $i, 'wrong', '127.0.0.1');
    }
    assertSame(200, loginRequest($ctx, 'owner', $ctx['password'], '127.0.0.1')->status(), 'proxy/private address is not a shared lock');
    assertSame(true, AuthService::ipLimitApplies('8.8.8.8'), 'public address');
    assertSame(false, AuthService::ipLimitApplies('10.0.0.5'), 'private address');
    assertSame(false, AuthService::ipLimitApplies(''), 'unknown address');
};

$tests['auth responses and logs never contain passwords, hashes or tokens'] = static function (): void {
    $ctx = authContext();
    $responses = [loginRequest($ctx, 'owner', 'wrong-' . $ctx['password']), loginRequest($ctx, 'nobody', $ctx['password'])];
    $login = loginRequest($ctx, 'owner', $ctx['password']);
    $token = sessionTokenFrom($login);
    $csrf = decode($login)['data']['csrfToken'];
    $responses[] = $login;
    $responses[] = authRequest($ctx['app'], 'GET', '/api/auth/me', sessionHeaders($token));
    $responses[] = authRequest($ctx['app'], 'POST', '/api/auth/logout', sessionHeaders($token, $csrf));

    foreach ($responses as $index => $response) {
        foreach ([$ctx['password'], $ctx['hash'], $token] as $secret) {
            assertTrue(!str_contains($response->body(), $secret), 'response body #' . $index);
        }
    }
    $log = is_file($ctx['log']) ? (string) file_get_contents($ctx['log']) : '';
    foreach ([$ctx['password'], $ctx['hash'], $token, $csrf] as $secret) {
        assertTrue(!str_contains($log, $secret), 'log');
    }

    $failingStore = new class () extends InMemoryAuthStore {
        public function findUserByLogin(string $normalizedLogin): ?UserRecord
        {
            throw new DatabaseUnavailableException('Auth query failed: SQLSTATE HY000, driver code 2002');
        }
    };
    $log = tempLogFile();
    $app = new Application(Application::routes(probe(), new AuthService($failingStore, $ctx['clock'])), new Logger($log));
    $response = authRequest($app, 'POST', '/api/auth/login', ['Origin' => AuthSettings::ALLOWED_ORIGIN, 'Content-Type' => 'application/json'], json_encode(['login' => 'owner', 'password' => $ctx['password']]));
    assertSame(503, $response->status(), 'storage failure');
    assertSame('db_unavailable', decode($response)['error']['code'], 'code');
    assertTrue(!str_contains((string) file_get_contents($log), $ctx['password']), 'password not logged on failure');
};

$tests['dummy hash is valid and never matches'] = static function (): void {
    $dummy = AuthService::dummyHash();
    assertTrue(password_get_info($dummy)['algo'] !== null, 'recognised hash');
    assertTrue(!password_verify('', $dummy) && !password_verify(bin2hex(random_bytes(8)), $dummy), 'does not match');
};

$tests['PdoAuthStore SQL works against SQLite'] = static function (): void {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new SkipTest('pdo_sqlite is not loaded (run with -d extension=pdo_sqlite)');
    }

    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE users (id TEXT PRIMARY KEY, login TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE sessions (id TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES users (id) ON DELETE CASCADE, token_hash TEXT NOT NULL UNIQUE, csrf_token TEXT NOT NULL, created_at TEXT NOT NULL, last_seen_at TEXT NOT NULL, expires_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, login_normalized TEXT NOT NULL, ip_address TEXT NOT NULL, attempted_at TEXT NOT NULL, succeeded INTEGER NOT NULL DEFAULT 0)');
    $password = 'test-' . bin2hex(random_bytes(12));
    $pdo->prepare("INSERT INTO users VALUES ('00000000-0000-4000-8000-000000000001', 'owner', ?, '2026-10-26 10:00:00.000', '2026-10-26 10:00:00.000')")
        ->execute([password_hash($password, PASSWORD_BCRYPT)]);

    $store = new PdoAuthStore(static fn (): PDO => $pdo);
    $clock = new MutableClock(new DateTimeImmutable('2026-10-26 10:00:00.000', new DateTimeZone('UTC')));
    $auth = new AuthService($store, $clock);

    try {
        $auth->login('owner', 'wrong', '8.8.8.8');
        throw new AssertionFailed('wrong password accepted');
    } catch (ApiException $exception) {
        assertSame(401, $exception->status(), 'wrong password');
    }
    assertSame(1, count($store->failureTimesForLogin('owner', $clock->now()->modify('-1 hour'))), 'failure stored and found by login');
    assertSame(1, count($store->failureTimesForIp('8.8.8.8', $clock->now()->modify('-1 hour'))), 'failure found by IP');

    $clock->advance(1);
    $result = $auth->login('owner', $password, '8.8.8.8');
    $row = $pdo->query('SELECT token_hash, csrf_token, expires_at FROM sessions')->fetch(PDO::FETCH_ASSOC);
    assertSame(hash('sha256', $result->token), $row['token_hash'], 'token hash stored');
    assertTrue(!str_contains(json_encode($pdo->query('SELECT * FROM sessions')->fetchAll()), $result->token), 'raw token not stored');
    assertSame('2026-10-26 10:00:01.000', $pdo->query("SELECT attempted_at FROM login_attempts WHERE succeeded = 1")->fetchColumn(), 'success recorded with milliseconds');

    $clock->advance(AuthSettings::TOUCH_INTERVAL_SECONDS);
    $session = $auth->authenticate($result->token);
    assertTrue($session !== null && $session->login === 'owner', 'session found via JOIN');
    assertSame($clock->now()->format('Y-m-d H:i:s.v'), $pdo->query('SELECT last_seen_at FROM sessions')->fetchColumn(), 'last_seen_at updated');

    $auth->logout($session);
    assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn(), 'logout deletes the row');

    $store->purgeAttemptsBefore($clock->now()->modify('+1 day'));
    assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn(), 'purge');
};

// ---------------------------------------------------------------------------------------------
// Clients (stage 5.1). HTTP behaviour runs against an in-memory store; the SQL of
// PdoClientStore runs against SQLite when pdo_sqlite is loaded. MySQL itself is not used here.

class InMemoryClientStore implements ClientStore
{
    /** @var array<string, array<string, mixed>> */
    public array $clients = [];
    /** @var array<string, true> Ids of clients that have orders. */
    public array $withOrders = [];
    public int $lookups = 0;
    public int $writes = 0;

    public function all(): array
    {
        $this->lookups++;
        $all = array_values($this->clients);
        usort($all, static fn (array $a, array $b): int => [$a['name'], $a['id']] <=> [$b['name'], $b['id']]);

        return $all;
    }

    public function find(string $id): ?array
    {
        $this->lookups++;

        return $this->clients[$id] ?? null;
    }

    public function insert(array $client): void
    {
        $this->writes++;
        $this->clients[$client['id']] = $client;
    }

    public function update(array $client): ?array
    {
        $this->writes++;
        if (!isset($this->clients[$client['id']])) {
            return null;
        }
        $client['createdAt'] = $this->clients[$client['id']]['createdAt'];

        return $this->clients[$client['id']] = $client;
    }

    public function deleteIfNoOrders(string $id): ClientDeletion
    {
        $this->writes++;
        if (!isset($this->clients[$id])) {
            return ClientDeletion::NotFound;
        }
        if (isset($this->withOrders[$id])) {
            return ClientDeletion::HasOrders;
        }
        unset($this->clients[$id]);

        return ClientDeletion::Deleted;
    }
}

/**
 * Logged-in owner with the client routes registered.
 *
 * @return array<string, mixed>
 */
function clientsContext(?ClientStore $store = null): array
{
    $ctx = authContext();
    $ctx['clients'] = $store ?? new InMemoryClientStore();
    $ctx['log'] = tempLogFile();
    $ctx['app'] = new Application(Application::routes(probe(), $ctx['auth'], $ctx['clients'], $ctx['clock']), new Logger($ctx['log']));
    $login = loginRequest($ctx, 'owner', $ctx['password']);
    $ctx['token'] = sessionTokenFrom($login);
    $ctx['csrf'] = decode($login)['data']['csrfToken'];

    return $ctx;
}

/**
 * Request as the logged-in owner's browser sends it; $headers replaces the session headers.
 *
 * @param array<string, mixed>|string|null $body Arrays are JSON-encoded.
 * @param array<string, string>|null $headers
 */
function clientCall(array $ctx, string $method, string $uri, array|string|null $body = null, ?array $headers = null): JsonResponse
{
    $headers ??= sessionHeaders($ctx['token'], $method === 'GET' ? null : $ctx['csrf']);
    if ($body !== null) {
        $headers += ['Content-Type' => 'application/json'];
        $body = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    return authRequest($ctx['app'], $method, $uri, $headers, $body ?? '');
}

function expectApiError(callable $action, int $status, string $code, string $label): ApiException
{
    try {
        $action();
    } catch (ApiException $exception) {
        assertSame($status, $exception->status(), $label . ' status');
        assertSame($code, $exception->errorCode(), $label . ' code');

        return $exception;
    }
    throw new AssertionFailed($label . ': expected ' . $status . ' ' . $code);
}

function assertError(JsonResponse $response, int $status, string $code, string $label): void
{
    assertSame($status, $response->status(), $label . ' status');
    assertSame($code, decode($response)['error']['code'] ?? null, $label . ' code');
}

/** SQLite copy of the `clients` table of 001_init.sql plus the order link it protects. */
function sqliteClientsPdo(): PDO
{
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new SkipTest('pdo_sqlite is not loaded (run with -d extension=pdo_sqlite)');
    }
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE clients (id TEXT PRIMARY KEY, name TEXT NOT NULL, contact_name TEXT NULL, phone TEXT NULL, email TEXT NULL,'
        . ' telegram TEXT NULL, max_contact TEXT NULL, other_contact TEXT NULL, source TEXT NULL, notes TEXT NULL, tags TEXT NULL,'
        . ' is_archived INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE orders (id TEXT PRIMARY KEY, client_id TEXT NOT NULL REFERENCES clients (id) ON DELETE RESTRICT)');

    return $pdo;
}

const CLIENT_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

$tests['router matches path parameters after exact routes'] = static function (): void {
    $router = new Router();
    $router->get('/items', static fn (): JsonResponse => JsonResponse::success(['list' => true]), public: true);
    $router->get('/items/new', static fn (): JsonResponse => JsonResponse::success(['new' => true]), public: true);
    $router->get('/items/{id}', static fn (Request $r, $s, array $params): JsonResponse => JsonResponse::success(['params' => $params]), public: true);
    $data = static fn (string $uri): array => decode($router->dispatch(Request::create('GET', $uri, '/api')))['data'];

    assertSame(['list' => true], $data('/api/items/'), 'exact route');
    assertSame(['new' => true], $data('/api/items/new'), 'exact route wins over a pattern');
    assertSame(['params' => ['id' => 'a b']], $data('/api/items/a%20b'), 'one decoded segment');
    assertSame(['params' => ['id' => '{id}']], $data('/api/items/%7Bid%7D'), 'literal braces are only a value');
    foreach (['/api/items/a/b', '/api/itemsx', '/api/other/1'] as $uri) {
        expectApiError(static fn () => $router->dispatch(Request::create('GET', $uri, '/api')), 404, 'not_found', $uri);
    }
    $exception = expectApiError(static fn () => $router->dispatch(Request::create('DELETE', '/api/items/1', '/api')), 405, 'method_not_allowed', 'pattern 405');
    assertSame(['Allow' => 'GET, HEAD'], $exception->headers(), 'Allow');

    try {
        (new Router())->get('/items/{bad-name}', static fn (): JsonResponse => JsonResponse::success([]));
        throw new AssertionFailed('invalid pattern accepted');
    } catch (LogicException) {
    }

    $unguarded = new Router();
    $unguarded->get('/items/{id}', static fn (): JsonResponse => JsonResponse::success([]));
    try {
        $unguarded->dispatch(Request::create('GET', '/api/items/1', '/api'));
        throw new AssertionFailed('protected pattern route ran without a guard');
    } catch (LogicException) {
    }
    assertSame(404, authRequest(authContext()['app'], 'GET', '/api/clients')->status(), 'no client routes without a client store');
};

$tests['client validator mirrors the frontend client model'] = static function (): void {
    $created = ClientValidator::forCreate([
        'name' => "  \u{00A0}Acme\u{3000}\n",
        'phone' => "  \t",
        'email' => ' a@b.co ',
        'notes' => "\n line \n",
        'tags' => [' x ', 'y'],
    ]);
    assertSame([
        'name' => 'Acme', 'phone' => null, 'email' => 'a@b.co', 'notes' => 'line', 'tags' => ['x', 'y'],
        'contactName' => null, 'telegram' => null, 'maxContact' => null, 'otherContact' => null, 'source' => null,
        'isArchived' => false,
    ], $created, 'create: JS trim, blank -> null, defaults');
    assertSame(['phone' => null, 'email' => null], ClientValidator::forPatch(['phone' => null, 'email' => '']), 'patch clears fields');
    assertSame([], ClientValidator::forPatch([]), 'empty patch');
    assertSame(['isArchived' => true], ClientValidator::forPatch(['isArchived' => true]), 'archive');
    assertSame(null, ClientValidator::trim("\xff"), 'invalid UTF-8');

    $ok = [
        'name of 255 characters' => ['name' => str_repeat('я', 255)],
        'name of 255 emoji' => ['name' => str_repeat("\u{1F600}", 255)],
        'notes of 20000 characters' => ['name' => 'a', 'notes' => str_repeat("\u{1F600}", 20000)],
        'valid e-mail' => ['name' => 'a', 'email' => "o'brien+tag@mail.example.co.uk"],
        'empty tags' => ['name' => 'a', 'tags' => []],
    ];
    foreach ($ok as $label => $body) {
        ClientValidator::forCreate($body);
    }

    $invalid = [
        'missing name' => [],
        'blank name' => ['name' => " \u{00A0} "],
        'null name' => ['name' => null],
        'numeric name' => ['name' => 5],
        'array name' => ['name' => ['x']],
        'name too long' => ['name' => str_repeat('я', 256)],
        'contact too long' => ['name' => 'a', 'contactName' => str_repeat('a', 256)],
        'notes too long' => ['name' => 'a', 'notes' => str_repeat('a', 20001)],
        'other contact too long' => ['name' => 'a', 'otherContact' => str_repeat('a', 20001)],
        'numeric phone' => ['name' => 'a', 'phone' => 79001234567],
        'boolean phone' => ['name' => 'a', 'phone' => true],
        'array phone' => ['name' => 'a', 'phone' => []],
        'bad e-mail' => ['name' => 'a', 'email' => 'a@b'],
        'e-mail with spaces' => ['name' => 'a', 'email' => 'a b@c.co'],
        'string isArchived' => ['name' => 'a', 'isArchived' => 'true'],
        'numeric isArchived' => ['name' => 'a', 'isArchived' => 1],
        'null isArchived' => ['name' => 'a', 'isArchived' => null],
        'string tags' => ['name' => 'a', 'tags' => 'a'],
        'numeric tag' => ['name' => 'a', 'tags' => [1]],
        'blank tag' => ['name' => 'a', 'tags' => [' ']],
        'tag map' => ['name' => 'a', 'tags' => ['a' => 'b']],
        'id' => ['name' => 'a', 'id' => '00000000-0000-4000-8000-000000000001'],
        'createdAt' => ['name' => 'a', 'createdAt' => '2020-01-01T00:00:00.000Z'],
        'updatedAt' => ['name' => 'a', 'updatedAt' => '2020-01-01T00:00:00.000Z'],
        'unknown field' => ['name' => 'a', 'isAdmin' => true],
        'column name' => ['name' => 'a', 'contact_name' => 'x'],
        'unknown field without name' => ['role' => 'x'],
    ];
    foreach ($invalid as $label => $body) {
        expectApiError(static fn () => ClientValidator::forCreate($body), 400, 'validation_error', 'create: ' . $label);
    }
    expectApiError(static fn () => ClientValidator::forPatch(['name' => null]), 400, 'validation_error', 'patch: null name');
    expectApiError(static fn () => ClientValidator::forPatch(['id' => 'x']), 400, 'validation_error', 'patch: id');
};

$tests['clients CRUD round trip'] = static function (): void {
    $ctx = clientsContext();

    $created = clientCall($ctx, 'POST', '/api/clients', ['name' => '  ООО Ромашка ', 'phone' => '+7 900', 'email' => '', 'tags' => []]);
    assertSame(201, $created->status(), 'create status');
    $client = decode($created)['data']['client'];
    assertTrue(preg_match(CLIENT_ID_PATTERN, $client['id']) === 1, 'server-generated UUID v4');
    assertSame([
        'id' => $client['id'], 'name' => 'ООО Ромашка', 'phone' => '+7 900', 'tags' => [], 'isArchived' => false,
        'createdAt' => '2026-10-26T10:00:00.000Z', 'updatedAt' => '2026-10-26T10:00:00.000Z',
    ], $client, 'created client in the frontend shape (absent fields omitted)');
    $id = $client['id'];

    assertSame(['clients' => [$client]], decode(clientCall($ctx, 'GET', '/api/clients'))['data'], 'list');
    assertSame(['client' => $client], decode(clientCall($ctx, 'GET', '/api/clients/' . $id))['data'], 'show');

    $ctx['clock']->advance(5);
    $updated = clientCall($ctx, 'PATCH', '/api/clients/' . $id, ['phone' => null, 'notes' => ' заметка ', 'isArchived' => true]);
    assertSame(200, $updated->status(), 'update status');
    assertSame([
        'id' => $id, 'name' => 'ООО Ромашка', 'notes' => 'заметка', 'tags' => [], 'isArchived' => true,
        'createdAt' => '2026-10-26T10:00:00.000Z', 'updatedAt' => '2026-10-26T10:00:05.000Z',
    ], decode($updated)['data']['client'], 'patched: phone cleared, createdAt kept, updatedAt moved');
    assertSame(decode($updated)['data']['client'], decode(clientCall($ctx, 'GET', '/api/clients/' . $id))['data']['client'], 'update stored');

    $deleted = clientCall($ctx, 'DELETE', '/api/clients/' . $id);
    assertSame(200, $deleted->status(), 'delete status');
    assertSame('{"ok":true,"data":{}}', $deleted->body(), 'delete body');
    assertError(clientCall($ctx, 'GET', '/api/clients/' . $id), 404, 'not_found', 'deleted client');
    assertError(clientCall($ctx, 'DELETE', '/api/clients/' . $id), 404, 'not_found', 'second delete');
    assertSame(['clients' => []], decode(clientCall($ctx, 'GET', '/api/clients'))['data'], 'empty list');
};

$tests['clients with orders are not deleted'] = static function (): void {
    $ctx = clientsContext();
    $id = decode(clientCall($ctx, 'POST', '/api/clients', ['name' => 'С заказами']))['data']['client']['id'];
    $ctx['clients']->withOrders[$id] = true;

    assertError(clientCall($ctx, 'DELETE', '/api/clients/' . $id), 409, 'client_has_orders', 'delete with orders');
    assertSame(200, clientCall($ctx, 'GET', '/api/clients/' . $id)->status(), 'client kept');
    assertSame(true, decode(clientCall($ctx, 'PATCH', '/api/clients/' . $id, ['isArchived' => true]))['data']['client']['isArchived'], 'archiving still works');
};

$tests['missing clients and malformed ids are 404 without a database lookup'] = static function (): void {
    $ctx = clientsContext();
    $missing = '00000000-0000-4000-8000-0000000000ff';
    assertError(clientCall($ctx, 'GET', '/api/clients/' . $missing), 404, 'not_found', 'GET missing');
    assertError(clientCall($ctx, 'PATCH', '/api/clients/' . $missing, ['name' => 'x']), 404, 'not_found', 'PATCH missing');
    assertError(clientCall($ctx, 'DELETE', '/api/clients/' . $missing), 404, 'not_found', 'DELETE missing');
    assertSame([], $ctx['clients']->clients, 'PATCH of a missing client creates nothing');

    $lookups = $ctx['clients']->lookups;
    $writes = $ctx['clients']->writes;
    $malformed = ['abc', rawurlencode("1' OR '1'='1"), rawurlencode('1; DROP TABLE clients;--'), '00000000-0000-4000-8000-00000000000g', rawurlencode($missing . ' ')];
    foreach ($malformed as $segment) {
        foreach (['GET', 'PATCH', 'DELETE'] as $method) {
            $response = clientCall($ctx, $method, '/api/clients/' . $segment, $method === 'PATCH' ? ['name' => 'x'] : null);
            assertError($response, 404, 'not_found', $method . ' ' . $segment);
            assertSame('Client not found', decode($response)['error']['message'], 'message does not echo the id');
        }
    }
    assertSame($lookups, $ctx['clients']->lookups, 'no lookups for malformed ids');
    assertSame($writes, $ctx['clients']->writes, 'no writes for malformed ids');
};

$tests['client updates cannot change the id or set unlisted fields'] = static function (): void {
    $ctx = clientsContext();
    $client = decode(clientCall($ctx, 'POST', '/api/clients', ['name' => 'Клиент']))['data']['client'];
    $id = $client['id'];
    $writes = $ctx['clients']->writes;

    $bodies = [
        'other id' => ['id' => '00000000-0000-4000-8000-000000000002'],
        'same id' => ['id' => $id, 'name' => 'Новое имя'],
        'createdAt' => ['createdAt' => '2020-01-01T00:00:00.000Z'],
        'updatedAt' => ['updatedAt' => '2020-01-01T00:00:00.000Z'],
        'unknown field' => ['isAdmin' => true],
        'unknown with valid field' => ['name' => 'x', 'ownerId' => '1'],
        'column name' => ['is_archived' => 1],
    ];
    foreach ($bodies as $label => $body) {
        assertError(clientCall($ctx, 'PATCH', '/api/clients/' . $id, $body), 400, 'validation_error', 'PATCH ' . $label);
    }
    assertError(clientCall($ctx, 'POST', '/api/clients', ['name' => 'x', 'id' => '00000000-0000-4000-8000-000000000003']), 400, 'validation_error', 'POST with id');
    assertError(clientCall($ctx, 'POST', '/api/clients', ['name' => 'x', 'createdAt' => '2020-01-01T00:00:00.000Z']), 400, 'validation_error', 'POST with createdAt');
    assertSame($writes, $ctx['clients']->writes, 'nothing written');
    assertSame(['client' => $client], decode(clientCall($ctx, 'GET', '/api/clients/' . $id))['data'], 'client unchanged');
    assertSame(1, count($ctx['clients']->clients), 'no extra client');
};

$tests['client routes require a valid session'] = static function (): void {
    $ctx = clientsContext();
    $id = decode(clientCall($ctx, 'POST', '/api/clients', ['name' => 'Клиент']))['data']['client']['id'];
    $writes = $ctx['clients']->writes;
    $calls = [
        ['GET', '/api/clients', null], ['GET', '/api/clients/' . $id, null], ['POST', '/api/clients', ['name' => 'x']],
        ['PATCH', '/api/clients/' . $id, ['name' => 'x']], ['DELETE', '/api/clients/' . $id, null],
    ];
    $origin = ['Origin' => AuthSettings::ALLOWED_ORIGIN, 'X-CSRF-Token' => $ctx['csrf']];
    $unknownToken = sessionHeaders(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), $ctx['csrf']);
    foreach ($calls as [$method, $uri, $body]) {
        assertError(clientCall($ctx, $method, $uri, $body, $origin), 401, 'unauthorized', "$method $uri without a cookie");
        assertError(clientCall($ctx, $method, $uri, $body, $unknownToken), 401, 'unauthorized', "$method $uri with an unknown token");
    }

    $ctx['clock']->advance(AuthSettings::IDLE_TIMEOUT_SECONDS + 1);
    foreach ($calls as [$method, $uri, $body]) {
        assertError(clientCall($ctx, $method, $uri, $body), 401, 'unauthorized', "$method $uri with an expired session");
    }
    assertSame($writes, $ctx['clients']->writes, 'nothing written without a session');

    $ctx = clientsContext();
    assertSame(200, authRequest($ctx['app'], 'POST', '/api/auth/logout', sessionHeaders($ctx['token'], $ctx['csrf']))->status(), 'logout');
    foreach ($calls as [$method, $uri, $body]) {
        assertError(clientCall($ctx, $method, $uri, $body), 401, 'unauthorized', "$method $uri after logout");
    }
};

$tests['client changes require the CSRF token of the session'] = static function (): void {
    $ctx = clientsContext();
    $id = decode(clientCall($ctx, 'POST', '/api/clients', ['name' => 'Клиент']))['data']['client']['id'];
    $writes = $ctx['clients']->writes;
    $other = loginRequest($ctx, 'owner', $ctx['password']);
    $otherCsrf = decode($other)['data']['csrfToken'];

    foreach ([null, '', str_repeat('0', 64), $otherCsrf] as $csrf) {
        $headers = sessionHeaders($ctx['token'], $csrf);
        $label = $csrf === null ? 'missing' : ($csrf === $otherCsrf ? 'another session' : 'wrong');
        assertError(clientCall($ctx, 'POST', '/api/clients', ['name' => 'x'], $headers), 403, 'csrf_token_invalid', 'POST ' . $label);
        assertError(clientCall($ctx, 'PATCH', '/api/clients/' . $id, ['name' => 'x'], $headers), 403, 'csrf_token_invalid', 'PATCH ' . $label);
        assertError(clientCall($ctx, 'DELETE', '/api/clients/' . $id, null, $headers), 403, 'csrf_token_invalid', 'DELETE ' . $label);
    }
    assertSame($writes, $ctx['clients']->writes, 'nothing written');
    assertSame(200, clientCall($ctx, 'GET', '/api/clients/' . $id, null, sessionHeaders($ctx['token']))->status(), 'reads need no CSRF token');
};

$tests['client changes require the exact CRM origin'] = static function (): void {
    $ctx = clientsContext();
    $id = decode(clientCall($ctx, 'POST', '/api/clients', ['name' => 'Клиент']))['data']['client']['id'];
    $writes = $ctx['clients']->writes;

    foreach ([null, 'https://crm.devisd.online.evil.example', 'http://crm.devisd.online', 'https://evil.example', 'null'] as $origin) {
        $headers = sessionHeaders($ctx['token'], $ctx['csrf'], $origin);
        $label = $origin ?? 'no origin';
        assertError(clientCall($ctx, 'POST', '/api/clients', ['name' => 'x'], $headers), 403, 'origin_not_allowed', 'POST ' . $label);
        assertError(clientCall($ctx, 'PATCH', '/api/clients/' . $id, ['name' => 'x'], $headers), 403, 'origin_not_allowed', 'PATCH ' . $label);
        assertError(clientCall($ctx, 'DELETE', '/api/clients/' . $id, null, $headers), 403, 'origin_not_allowed', 'DELETE ' . $label);
    }
    assertSame($writes, $ctx['clients']->writes, 'nothing written');
};

$tests['client bodies: content type, JSON and size limits'] = static function (): void {
    $ctx = clientsContext();
    $headers = sessionHeaders($ctx['token'], $ctx['csrf']);
    assertError(clientCall($ctx, 'POST', '/api/clients', '{"name":"x"}', $headers + ['Content-Type' => 'text/plain']), 415, 'unsupported_media_type', 'content type');
    assertError(clientCall($ctx, 'POST', '/api/clients', '{"name":'), 400, 'invalid_json', 'malformed JSON');
    assertError(clientCall($ctx, 'POST', '/api/clients', '["x"]'), 400, 'invalid_json', 'JSON array');
    assertError(clientCall($ctx, 'POST', '/api/clients', '{"name":"' . str_repeat('x', ClientValidator::MAX_BODY_BYTES) . '"}'), 413, 'payload_too_large', 'too large');

    // Largest legitimate client: both long fields full of characters JSON escapes as \u00XX.
    $largest = ['name' => str_repeat('я', 255), 'otherContact' => str_repeat("\u{1}", 20000), 'notes' => str_repeat("\u{1}", 20000)];
    $body = json_encode($largest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    assertTrue(strlen($body) > Request::MAX_BODY_BYTES, 'bigger than the default limit');
    assertSame(201, clientCall($ctx, 'POST', '/api/clients', $body)->status(), 'largest valid client accepted');
};

$tests['client fields and ids carry SQL text only as data'] = static function (): void {
    $pdo = sqliteClientsPdo();
    $ctx = clientsContext(new PdoClientStore(static fn (): PDO => $pdo));
    $hostile = ['name' => "Robert'); DROP TABLE clients;--", 'notes' => "' OR '1'='1' --", 'source' => '"; DELETE FROM clients; --', 'tags' => ["') OR 1=1 --"]];

    $client = decode(clientCall($ctx, 'POST', '/api/clients', $hostile))['data']['client'];
    foreach ($hostile as $field => $value) {
        assertSame($value, $client[$field], 'returned ' . $field);
    }
    $stored = clientCall($ctx, 'GET', '/api/clients/' . $client['id']);
    assertSame($client, decode($stored)['data']['client'], 'stored literally');

    foreach (["x' OR '1'='1", $client['id'] . "' OR '1'='1", '1 UNION SELECT * FROM clients'] as $id) {
        assertError(clientCall($ctx, 'GET', '/api/clients/' . rawurlencode($id)), 404, 'not_found', 'GET ' . $id);
        assertError(clientCall($ctx, 'DELETE', '/api/clients/' . rawurlencode($id)), 404, 'not_found', 'DELETE ' . $id);
    }
    assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn(), 'table intact, client kept');
};

$tests['client storage errors are reported without internals'] = static function (): void {
    $failing = new class () extends InMemoryClientStore {
        public function all(): array
        {
            throw new RuntimeException('SELECT secret_column FROM clients failed at /home/user/private/path');
        }

        public function insert(array $client): void
        {
            throw new DatabaseUnavailableException('Client query failed: SQLSTATE HY000, driver code 2006');
        }
    };
    $ctx = clientsContext($failing);
    $internal = clientCall($ctx, 'GET', '/api/clients');
    assertSame('{"ok":false,"error":{"code":"internal_error","message":"Internal server error"}}', $internal->body(), 'unexpected error');
    assertSame(500, $internal->status(), 'unexpected error status');
    $unavailable = clientCall($ctx, 'POST', '/api/clients', ['name' => 'Тайное имя']);
    assertSame('{"ok":false,"error":{"code":"db_unavailable","message":"Database is unavailable"}}', $unavailable->body(), 'storage error');
    assertSame(503, $unavailable->status(), 'storage error status');
    assertTrue(!str_contains((string) file_get_contents($ctx['log']), 'Тайное имя'), 'client data not logged');

    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new SkipTest('SQLite part needs pdo_sqlite (run with -d extension=pdo_sqlite)');
    }
    $empty = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $ctx = clientsContext(new PdoClientStore(static fn (): PDO => $empty));
    foreach ([['GET', '/api/clients', null], ['POST', '/api/clients', ['name' => 'Тайное имя']], ['DELETE', '/api/clients/00000000-0000-4000-8000-000000000001', null]] as [$method, $uri, $body]) {
        $response = clientCall($ctx, $method, $uri, $body);
        assertSame('{"ok":false,"error":{"code":"db_unavailable","message":"Database is unavailable"}}', $response->body(), $method . ' without tables');
    }
    $log = (string) file_get_contents($ctx['log']);
    assertTrue(str_contains($log, 'SQLSTATE HY000'), 'log keeps the SQLSTATE');
    foreach (['SELECT', 'INSERT', 'no such table', 'Тайное имя'] as $secret) {
        assertTrue(!str_contains($log, $secret), 'log has no ' . $secret);
    }
};

$tests['PdoClientStore SQL works against SQLite'] = static function (): void {
    $pdo = sqliteClientsPdo();
    $store = new PdoClientStore(static fn (): PDO => $pdo);
    $client = static fn (string $id, string $name, array $extra = []): array => array_merge([
        'id' => $id, 'name' => $name, 'contactName' => null, 'phone' => null, 'email' => null, 'telegram' => null,
        'maxContact' => null, 'otherContact' => null, 'source' => null, 'notes' => null, 'tags' => null,
        'isArchived' => false, 'createdAt' => '2026-10-26T10:00:00.000Z', 'updatedAt' => '2026-10-26T10:00:00.000Z',
    ], $extra);
    $a = $client('00000000-0000-4000-8000-00000000000a', 'b', ['tags' => ['тег', 'emoji 😀'], 'phone' => '+7']);
    $b = $client('00000000-0000-4000-8000-00000000000b', 'A', ['isArchived' => true, 'createdAt' => '2026-10-26T10:00:00.123Z']);
    $c = $client('00000000-0000-4000-8000-00000000000c', 'a');
    foreach ([$a, $b, $c] as $row) {
        $store->insert($row);
    }

    assertSame($a, $store->find($a['id']), 'round trip: tags, nulls, booleans, timestamps');
    assertSame($b, $store->find($b['id']), 'archived flag and milliseconds');
    assertSame(null, $store->find('00000000-0000-4000-8000-0000000000ff'), 'missing');
    assertSame(['A', 'a', 'b'], array_column($store->all(), 'name'), 'ordered by name (SQLite binary collation)');
    assertSame('2026-10-26 10:00:00.123', $pdo->query("SELECT created_at FROM clients WHERE name = 'A'")->fetchColumn(), 'DATETIME(3) text in UTC');
    assertSame('["тег","emoji 😀"]', $pdo->query("SELECT tags FROM clients WHERE name = 'b'")->fetchColumn(), 'tags as JSON');

    $changed = array_merge($a, ['name' => 'B', 'phone' => null, 'tags' => [], 'updatedAt' => '2026-10-26T11:00:00.000Z', 'createdAt' => '2000-01-01T00:00:00.000Z']);
    $saved = $store->update($changed);
    assertSame(array_merge($changed, ['createdAt' => $a['createdAt']]), $saved, 'update keeps created_at and id');
    assertSame(null, $store->update($client('00000000-0000-4000-8000-0000000000ff', 'x')), 'update of a missing client');
    assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn(), 'update creates nothing');

    $pdo->exec("INSERT INTO orders VALUES ('order-1', '" . $a['id'] . "')");
    assertSame(ClientDeletion::HasOrders, $store->deleteIfNoOrders($a['id']), 'client with orders');
    assertTrue($store->find($a['id']) !== null, 'client with orders kept');
    $pdo->exec("CREATE TRIGGER block_delete BEFORE DELETE ON clients WHEN OLD.name = 'a' BEGIN SELECT RAISE(ABORT, 'FOREIGN KEY constraint failed'); END");
    assertSame(ClientDeletion::HasOrders, $store->deleteIfNoOrders($c['id']), 'integrity error while deleting');
    assertSame(ClientDeletion::Deleted, $store->deleteIfNoOrders($b['id']), 'client without orders');
    assertSame(ClientDeletion::NotFound, $store->deleteIfNoOrders($b['id']), 'already deleted');
};

// ---------------------------------------------------------------------------------------------
// Orders, items and payments (stage 5.3). Money and validation rules run without a database;
// the HTTP scenarios run against SQLite with the tables, keys and checks of 001_init.sql.
// MySQL row locks (FOR UPDATE / FOR SHARE) are not exercised here: SQLite locks the whole
// database for each writing transaction instead.

const ORDER_CLIENT_ID = '00000000-0000-4000-8000-0000000000c1';

/** SQLite copy of clients, orders, order_items, payments and app_meta of 001_init.sql. */
function sqliteOrdersPdo(string $dsn = 'sqlite::memory:', bool $seedClient = true): PDO
{
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new SkipTest('pdo_sqlite is not loaded (run with -d extension=pdo_sqlite)');
    }
    $pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 0]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE IF NOT EXISTS clients (id TEXT PRIMARY KEY, name TEXT NOT NULL, contact_name TEXT NULL, phone TEXT NULL, email TEXT NULL,'
        . ' telegram TEXT NULL, max_contact TEXT NULL, other_contact TEXT NULL, source TEXT NULL, notes TEXT NULL, tags TEXT NULL,'
        . ' is_archived INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (id TEXT PRIMARY KEY, number INTEGER NOT NULL UNIQUE CHECK (number > 0),"
        . ' client_id TEXT NOT NULL REFERENCES clients (id) ON DELETE RESTRICT, title TEXT NOT NULL, description TEXT NULL,'
        . " status TEXT NOT NULL CHECK (status IN ('new', 'in_progress', 'waiting', 'completed', 'cancelled')),"
        . ' order_date TEXT NOT NULL, due_date TEXT NULL, completed_at TEXT NULL,'
        . ' discount_minor INTEGER NOT NULL CHECK (discount_minor >= 0), total_minor INTEGER NOT NULL CHECK (total_minor >= 0),'
        . ' paid_minor INTEGER NOT NULL CHECK (paid_minor >= 0 AND paid_minor <= total_minor), notes TEXT NULL,'
        . ' is_archived INTEGER NOT NULL CHECK (is_archived IN (0, 1)), created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS order_items (id TEXT PRIMARY KEY, order_id TEXT NOT NULL REFERENCES orders (id) ON DELETE CASCADE,'
        . ' name TEXT NOT NULL, description TEXT NULL, quantity REAL NOT NULL CHECK (quantity > 0), unit TEXT NOT NULL,'
        . ' unit_price_minor INTEGER NOT NULL CHECK (unit_price_minor >= 0), line_total_minor INTEGER NOT NULL CHECK (line_total_minor >= 0),'
        . ' sort_order INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (id TEXT PRIMARY KEY, order_id TEXT NOT NULL REFERENCES orders (id) ON DELETE RESTRICT,"
        . " amount_minor INTEGER NOT NULL CHECK (amount_minor > 0), payment_date TEXT NOT NULL,"
        . " method TEXT NOT NULL CHECK (method IN ('cash', 'bank_transfer', 'other')), comment TEXT NULL, created_at TEXT NOT NULL)");
    $pdo->exec('CREATE TABLE IF NOT EXISTS app_meta (meta_key TEXT PRIMARY KEY, meta_value TEXT NOT NULL)');
    if ($seedClient) {
        $pdo->exec("INSERT OR IGNORE INTO clients (id, name, created_at, updated_at) VALUES ('" . ORDER_CLIENT_ID . "', 'Клиент', '2026-10-01 00:00:00.000', '2026-10-01 00:00:00.000')");
    }

    return $pdo;
}

/**
 * Logged-in owner with client and order routes on one SQLite database.
 *
 * @return array<string, mixed>
 */
function ordersContext(?PDO $pdo = null): array
{
    $pdo ??= sqliteOrdersPdo();
    $ctx = authContext();
    $ctx['pdo'] = $pdo;
    $ctx['log'] = tempLogFile();
    $connect = static fn (): PDO => $pdo;
    $ctx['app'] = new Application(
        Application::routes(probe(), $ctx['auth'], new PdoClientStore($connect), $ctx['clock'], new PdoOrderStore($connect)),
        new Logger($ctx['log']),
    );
    $login = loginRequest($ctx, 'owner', $ctx['password']);
    $ctx['token'] = sessionTokenFrom($login);
    $ctx['csrf'] = decode($login)['data']['csrfToken'];

    return $ctx;
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function orderBody(array $overrides = []): array
{
    return array_merge([
        'clientId' => ORDER_CLIENT_ID,
        'title' => 'Сайт',
        'orderDate' => '2026-10-20T09:00:00.000Z',
        'items' => [
            ['name' => 'Дизайн', 'quantity' => 3, 'unit' => 'шт.', 'unitPriceMinor' => 3333],
            ['name' => 'Вёрстка', 'quantity' => 1.5, 'unit' => 'ч', 'unitPriceMinor' => 333],
        ],
    ], $overrides);
}

/** @return array<string, mixed> data of a successful response */
function okData(JsonResponse $response, int $status, string $label): array
{
    assertSame($status, $response->status(), $label . ' status (' . $response->body() . ')');

    return decode($response)['data'];
}

/** @return array<string, int> Row counts of the order tables. */
function orderTableCounts(PDO $pdo): array
{
    $counts = [];
    foreach (['orders', 'order_items', 'payments'] as $table) {
        $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
    $counts['nextOrderNumber'] = (int) ($pdo->query("SELECT meta_value FROM app_meta WHERE meta_key = 'nextOrderNumber'")->fetchColumn() ?: 1);

    return $counts;
}

$tests['order money: the cases shared with the frontend'] = static function (): void {
    // src/lib/services/money.test.ts checks calcLineTotalMinor / recalculateOrderMoney on the same file.
    $cases = json_decode((string) file_get_contents(__DIR__ . '/../../src/lib/services/money.cases.json'), true, 16, JSON_THROW_ON_ERROR);
    $items = static fn (array $pairs): array => array_map(static fn (array $pair): array => ['quantity' => $pair[0], 'unitPriceMinor' => $pair[1]], $pairs);
    $rejected = static function (callable $action, string $label): void {
        try {
            $action();
        } catch (ApiException $exception) {
            assertSame(400, $exception->status(), $label);

            return;
        }
        throw new AssertionFailed($label . ': expected a rejection');
    };

    foreach ($cases['lineTotals'] as ['quantity' => $quantity, 'unitPriceMinor' => $price, 'expected' => $expected]) {
        assertSame($expected, Money::lineTotal($quantity, $price), "$quantity × $price");
    }
    foreach ($cases['lineTotalErrors'] as ['quantity' => $quantity, 'unitPriceMinor' => $price]) {
        $rejected(static fn () => Money::lineTotal($quantity, $price), "$quantity × $price");
    }
    foreach ($cases['orderTotals'] as $index => $case) {
        assertSame($case['expected'], Money::totals($items($case['items']), $case['discountMinor']), 'order totals #' . $index);
    }
    foreach ($cases['orderTotalErrors'] as $index => $case) {
        $rejected(static fn () => Money::totals($items($case['items']), $case['discountMinor']), 'order total error #' . $index);
    }

    foreach ([NAN, INF, -INF] as $quantity) {
        $rejected(static fn () => Money::lineTotal($quantity, 100), 'quantity ' . $quantity);
    }
    expectApiError(static fn () => Money::totals([['quantity' => 1, 'unitPriceMinor' => 700]], 701), 400, 'discount_exceeds_subtotal', 'discount code');
    expectApiError(static fn () => Money::lineTotal(2, Fields::MAX_SAFE_INTEGER), 400, 'amount_out_of_range', 'overflow code');
};

$tests['order validator accepts only the model fields'] = static function (): void {
    $created = OrderValidator::forCreate(orderBody(['title' => "  Сайт\u{00A0}", 'dueDate' => '', 'notes' => '  ']));
    assertSame(['Сайт', 'new', null, 0, null, null], [$created['title'], $created['status'], $created['dueDate'], $created['discountMinor'], $created['notes'], $created['description']], 'defaults and trimming');
    assertSame([0, 1], array_column($created['items'], 'sortOrder'), 'sortOrder defaults to the position');
    assertSame([null, null], array_column($created['items'], 'id'), 'new items have no id');

    $item = ['name' => 'x', 'quantity' => 1, 'unit' => 'шт.', 'unitPriceMinor' => 100];
    $invalid = [
        'missing clientId' => array_diff_key(orderBody(), ['clientId' => 1]),
        'missing items' => array_diff_key(orderBody(), ['items' => 1]),
        'bad clientId' => orderBody(['clientId' => 'client-1']),
        'blank title' => orderBody(['title' => ' ']),
        'long title' => orderBody(['title' => str_repeat('я', 256)]),
        'long notes' => orderBody(['notes' => str_repeat('a', 20001)]),
        'unknown status' => orderBody(['status' => 'done']),
        'date only' => orderBody(['orderDate' => '2026-10-20']),
        'impossible date' => orderBody(['orderDate' => '2026-02-30T00:00:00.000Z']),
        'year before 1000' => orderBody(['orderDate' => '0999-01-01T00:00:00.000Z']),
        'bad due date' => orderBody(['dueDate' => 'tomorrow']),
        'negative discount' => orderBody(['discountMinor' => -1]),
        'fractional discount' => orderBody(['discountMinor' => 0.5]),
        'string discount' => orderBody(['discountMinor' => '100']),
        'no items' => orderBody(['items' => []]),
        'items object' => orderBody(['items' => ['a' => $item]]),
        'item list' => orderBody(['items' => [['x']]]),
        'zero quantity' => orderBody(['items' => [['quantity' => 0] + $item]]),
        'negative quantity' => orderBody(['items' => [['quantity' => -1] + $item]]),
        'string quantity' => orderBody(['items' => [['quantity' => '1'] + $item]]),
        'fractional price' => orderBody(['items' => [['unitPriceMinor' => 1.5] + $item]]),
        'negative price' => orderBody(['items' => [['unitPriceMinor' => -1] + $item]]),
        'price above 2^53' => orderBody(['items' => [['unitPriceMinor' => Fields::MAX_SAFE_INTEGER + 1] + $item]]),
        'long unit' => orderBody(['items' => [['unit' => str_repeat('u', 65)] + $item]]),
        'missing unit' => orderBody(['items' => [array_diff_key($item, ['unit' => 1])]]),
        'sortOrder above uint32' => orderBody(['items' => [['sortOrder' => 4294967296] + $item]]),
        'item id on create' => orderBody(['items' => [['id' => '00000000-0000-4000-8000-000000000001'] + $item]]),
        'item lineTotalMinor' => orderBody(['items' => [['lineTotalMinor' => 1] + $item]]),
        'item orderId' => orderBody(['items' => [['orderId' => ORDER_CLIENT_ID] + $item]]),
        'unknown item field' => orderBody(['items' => [['discount' => 1] + $item]]),
    ];
    foreach (['id', 'number', 'totalMinor', 'paidMinor', 'completedAt', 'createdAt', 'updatedAt', 'isArchived', 'ownerId'] as $field) {
        $invalid['field ' . $field] = orderBody([$field => 1]);
    }
    foreach ($invalid as $label => $body) {
        expectApiError(static fn () => OrderValidator::forCreate($body), 400, 'validation_error', 'create: ' . $label);
    }

    $patch = OrderValidator::forPatch(['items' => [['id' => '00000000-0000-4000-8000-000000000001'] + $item], 'isArchived' => true]);
    assertSame('00000000-0000-4000-8000-000000000001', $patch['items'][0]['id'], 'item id kept on patch');
    assertSame([], OrderValidator::forPatch([]), 'empty patch');
    foreach ([
        'repeated item id' => ['items' => [['id' => '00000000-0000-4000-8000-000000000001'] + $item, ['id' => '00000000-0000-4000-8000-000000000001'] + $item]],
        'completedAt' => ['completedAt' => '2026-10-20T09:00:00.000Z'],
        'number' => ['number' => 7],
        'paidMinor' => ['paidMinor' => 0],
        'null title' => ['title' => null],
        'string isArchived' => ['isArchived' => 'true'],
    ] as $label => $body) {
        expectApiError(static fn () => OrderValidator::forPatch($body), 400, 'validation_error', 'patch: ' . $label);
    }

    $payment = ['amountMinor' => 100, 'paymentDate' => '2026-10-21T09:00:00.000Z', 'method' => 'cash'];
    assertSame($payment + ['comment' => null], OrderValidator::payment($payment + ['comment' => ' ']), 'payment');
    foreach ([
        'zero amount' => ['amountMinor' => 0] + $payment,
        'fractional amount' => ['amountMinor' => 10.5] + $payment,
        'unknown method' => ['method' => 'card'] + $payment,
        'missing date' => array_diff_key($payment, ['paymentDate' => 1]),
        'long comment' => ['comment' => str_repeat('a', 20001)] + $payment,
        'orderId' => ['orderId' => ORDER_CLIENT_ID] + $payment,
        'id' => ['id' => ORDER_CLIENT_ID] + $payment,
    ] as $label => $body) {
        expectApiError(static fn () => OrderValidator::payment($body), 400, 'validation_error', 'payment: ' . $label);
    }
};

$tests['orders: create, read and update with server-side totals'] = static function (): void {
    $ctx = ordersContext();

    $created = okData(clientCall($ctx, 'POST', '/api/orders', orderBody(['discountMinor' => 499, 'description' => ' Лендинг '])), 201, 'create');
    $order = $created['order'];
    assertTrue(preg_match(CLIENT_ID_PATTERN, $order['id']) === 1, 'server order id');
    assertSame([
        'id' => $order['id'], 'number' => 1, 'clientId' => ORDER_CLIENT_ID, 'title' => 'Сайт', 'description' => 'Лендинг',
        'status' => 'new', 'orderDate' => '2026-10-20T09:00:00.000Z', 'discountMinor' => 499, 'totalMinor' => 10000,
        'paidMinor' => 0, 'isArchived' => false, 'createdAt' => '2026-10-26T10:00:00.000Z', 'updatedAt' => '2026-10-26T10:00:00.000Z',
    ], $order, 'created order');
    assertSame([[9999, 0, 3], [500, 1, 1.5]], array_map(static fn (array $i): array => [$i['lineTotalMinor'], $i['sortOrder'], $i['quantity']], $created['items']), 'line totals computed by the server');
    foreach ($created['items'] as $item) {
        assertTrue(preg_match(CLIENT_ID_PATTERN, $item['id']) === 1 && $item['orderId'] === $order['id'], 'server item ids');
    }

    $second = okData(clientCall($ctx, 'POST', '/api/orders', orderBody(['orderDate' => '2026-10-25T09:00:00.000Z'])), 201, 'second create')['order'];
    assertSame(2, $second['number'], 'next number');
    assertSame([$second['id'], $order['id']], array_column(okData(clientCall($ctx, 'GET', '/api/orders'), 200, 'list')['orders'], 'id'), 'list, newest order date first');

    $shown = okData(clientCall($ctx, 'GET', '/api/orders/' . $order['id']), 200, 'show');
    assertSame(['order' => $order, 'items' => $created['items'], 'payments' => []], $shown, 'show returns order, items, payments');

    // Full replacement: the first item is kept by id and changed, the second is dropped, one is new.
    $ctx['clock']->advance(60);
    $kept = $created['items'][0];
    $updated = okData(clientCall($ctx, 'PATCH', '/api/orders/' . $order['id'], [
        'title' => 'Сайт v2',
        'description' => null,
        'items' => [
            ['id' => $kept['id'], 'name' => 'Дизайн', 'quantity' => 2, 'unit' => 'шт.', 'unitPriceMinor' => 3333, 'sortOrder' => 5],
            ['name' => 'Поддержка', 'quantity' => 0.25, 'unit' => 'мес.', 'unitPriceMinor' => 100002],
        ],
    ]), 200, 'update');
    assertSame(['Сайт v2', false, 1, 499, 6666 + 25001 - 499], [$updated['order']['title'], array_key_exists('description', $updated['order']), $updated['order']['number'], $updated['order']['discountMinor'], $updated['order']['totalMinor']], 'updated order');
    assertSame(['2026-10-26T10:00:00.000Z', '2026-10-26T10:01:00.000Z'], [$updated['order']['createdAt'], $updated['order']['updatedAt']], 'timestamps');
    assertSame(['Поддержка', 'Дизайн'], array_column($updated['items'], 'name'), 'items replaced, ordered by sortOrder');
    assertSame($kept['id'], $updated['items'][1]['id'], 'kept item id');
    assertSame(2, (int) $ctx['pdo']->query("SELECT COUNT(*) FROM order_items WHERE order_id = '" . $order['id'] . "'")->fetchColumn(), 'dropped item deleted');

    $discounted = okData(clientCall($ctx, 'PATCH', '/api/orders/' . $order['id'], ['discountMinor' => 0]), 200, 'discount only');
    assertSame(6666 + 25001, $discounted['order']['totalMinor'], 'total recomputed without sending items');
    assertSame(array_column($updated['items'], 'id'), array_column($discounted['items'], 'id'), 'items kept when not sent');

    assertError(clientCall($ctx, 'PATCH', '/api/orders/' . $order['id'], ['discountMinor' => 6666 + 25001 + 1]), 400, 'discount_exceeds_subtotal', 'discount above subtotal');
    assertError(clientCall($ctx, 'PATCH', '/api/orders/' . $order['id'], ['items' => [['id' => $second['id'], 'name' => 'x', 'quantity' => 1, 'unit' => 'шт.', 'unitPriceMinor' => 1]]]), 400, 'validation_error', 'foreign item id');
    assertError(clientCall($ctx, 'POST', '/api/orders', orderBody(['totalMinor' => 1])), 400, 'validation_error', 'client total rejected');
    assertSame($discounted, okData(clientCall($ctx, 'PATCH', '/api/orders/' . $order['id'], []), 200, 'empty patch'), 'empty patch keeps the data');
};

$tests['orders: numbers are allocated once, in the creating transaction'] = static function (): void {
    $ctx = ordersContext();
    $ctx['pdo']->exec("INSERT INTO app_meta VALUES ('nextOrderNumber', '41')");
    assertSame(41, okData(clientCall($ctx, 'POST', '/api/orders', orderBody()), 201, 'from counter')['order']['number'], 'counter value used');
    assertError(clientCall($ctx, 'POST', '/api/orders', orderBody(['clientId' => '00000000-0000-4000-8000-0000000000ff'])), 409, 'client_not_found', 'failed create');
    assertError(clientCall($ctx, 'POST', '/api/orders', orderBody(['number' => 7])), 400, 'validation_error', 'number from the browser');
    assertSame(42, okData(clientCall($ctx, 'POST', '/api/orders', orderBody()), 201, 'after failure')['order']['number'], 'failed create returned its number');
    assertSame('43', $ctx['pdo']->query("SELECT meta_value FROM app_meta WHERE meta_key = 'nextOrderNumber'")->fetchColumn(), 'counter advanced');

    $ctx['pdo']->exec("UPDATE app_meta SET meta_value = '42' WHERE meta_key = 'nextOrderNumber'");
    assertError(clientCall($ctx, 'POST', '/api/orders', orderBody()), 409, 'conflict', 'counter behind an existing number');
    $ctx['pdo']->exec("UPDATE app_meta SET meta_value = '\"x\"' WHERE meta_key = 'nextOrderNumber'");
    assertError(clientCall($ctx, 'POST', '/api/orders', orderBody()), 503, 'db_unavailable', 'unusable counter');
    assertSame(2, (int) $ctx['pdo']->query('SELECT COUNT(*) FROM orders')->fetchColumn(), 'no order from failed creates');

    // Two connections: while one transaction holds a fresh number, the other cannot take any.
    $file = sys_get_temp_dir() . '/devis-crm-orders-' . bin2hex(random_bytes(6)) . '.sqlite';
    try {
        sqliteOrdersPdo('sqlite:' . $file);
        $first = new PdoOrderStore(static fn (): PDO => sqliteConnection($file, 'first'));
        $second = new PdoOrderStore(static fn (): PDO => sqliteConnection($file, 'second'));
        $held = $first->transaction(static function () use ($first, $second): int {
            $number = $first->allocateOrderNumber();
            try {
                $second->transaction(static fn (): int => $second->allocateOrderNumber());
                throw new AssertionFailed('a second number was allocated concurrently');
            } catch (DatabaseUnavailableException) {
            }

            return $number;
        });
        assertSame([1, 2], [$held, $second->transaction(static fn (): int => $second->allocateOrderNumber())], 'numbers in sequence, none repeated');
    } finally {
        sqliteConnection($file, 'close');
        @unlink($file);
    }
};

/** One shared PDO per name for the file database of the concurrency tests; "close" drops them. */
function sqliteConnection(string $file, string $name): ?PDO
{
    static $connections = [];
    if ($name === 'close') {
        $connections = [];

        return null;
    }

    return $connections[$file . $name] ??= new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 0]);
}

$tests['orders: a failing item write rolls back the whole order'] = static function (): void {
    $ctx = ordersContext();
    $order = okData(clientCall($ctx, 'POST', '/api/orders', orderBody()), 201, 'create');
    $ctx['pdo']->exec("CREATE TRIGGER fail_item BEFORE INSERT ON order_items WHEN NEW.name = 'boom' BEGIN SELECT RAISE(ABORT, 'item rejected'); END");
    $before = orderTableCounts($ctx['pdo']);

    $items = orderBody()['items'];
    $items[] = ['name' => 'boom', 'quantity' => 1, 'unit' => 'шт.', 'unitPriceMinor' => 1];
    assertError(clientCall($ctx, 'POST', '/api/orders', orderBody(['items' => $items])), 409, 'conflict', 'create');
    assertSame($before, orderTableCounts($ctx['pdo']), 'no order, no items, number not consumed');

    assertError(clientCall($ctx, 'PATCH', '/api/orders/' . $order['order']['id'], ['title' => 'Новое', 'items' => $items]), 409, 'conflict', 'update');
    assertSame(['order' => $order['order'], 'items' => $order['items'], 'payments' => []], okData(clientCall($ctx, 'GET', '/api/orders/' . $order['order']['id']), 200, 'show'), 'order and items unchanged');
};

$tests['orders: the client must exist and keeps its orders'] = static function (): void {
    $ctx = ordersContext();
    $missing = '00000000-0000-4000-8000-0000000000ff';
    assertError(clientCall($ctx, 'POST', '/api/orders', orderBody(['clientId' => $missing])), 409, 'client_not_found', 'create');
    $order = okData(clientCall($ctx, 'POST', '/api/orders', orderBody()), 201, 'create')['order'];
    assertError(clientCall($ctx, 'PATCH', '/api/orders/' . $order['id'], ['clientId' => $missing]), 409, 'client_not_found', 'update');
    assertSame(ORDER_CLIENT_ID, okData(clientCall($ctx, 'GET', '/api/orders/' . $order['id']), 200, 'show')['order']['clientId'], 'client unchanged');
    assertError(clientCall($ctx, 'DELETE', '/api/clients/' . ORDER_CLIENT_ID), 409, 'client_has_orders', 'client with orders');
};

$tests['orders: payments never exceed the total'] = static function (): void {
    $ctx = ordersContext();
    $order = okData(clientCall($ctx, 'POST', '/api/orders', orderBody(['items' => [['name' => 'Работа', 'quantity' => 1, 'unit' => 'шт.', 'unitPriceMinor' => 1000]]])), 201, 'create')['order'];
    $uri = '/api/orders/' . $order['id'] . '/payments';
    $pay = static fn (int $amount, string $date): JsonResponse => clientCall($ctx, 'POST', $uri, ['amountMinor' => $amount, 'paymentDate' => $date, 'method' => 'cash', 'comment' => ' аванс ']);

    $first = okData($pay(600, '2026-10-21T09:00:00.000Z'), 201, 'first payment');
    assertTrue(preg_match(CLIENT_ID_PATTERN, $first['payment']['id']) === 1, 'server payment id');
    assertSame(['amountMinor' => 600, 'paymentDate' => '2026-10-21T09:00:00.000Z', 'method' => 'cash', 'comment' => 'аванс', 'createdAt' => '2026-10-26T10:00:00.000Z'], array_diff_key($first['payment'], ['id' => 1, 'orderId' => 1]), 'payment');
    assertSame(600, $first['order']['paidMinor'], 'paid amount');
    assertError($pay(401, '2026-10-22T09:00:00.000Z'), 409, 'payment_exceeds_remaining', 'above remaining');
    okData($pay(400, '2026-10-22T09:00:00.000Z'), 201, 'exact remaining');
    assertError($pay(1, '2026-10-23T09:00:00.000Z'), 409, 'payment_exceeds_remaining', 'fully paid');

    $payments = okData(clientCall($ctx, 'GET', $uri), 200, 'payments')['payments'];
    assertSame([400, 600], array_column($payments, 'amountMinor'), 'newest payment date first');
    assertSame($payments, okData(clientCall($ctx, 'GET', '/api/orders/' . $order['id']), 200, 'show')['payments'], 'payments in the order');
    assertSame(1000, okData(clientCall($ctx, 'GET', '/api/orders/' . $order['id']), 200, 'show')['order']['paidMinor'], 'paid equals the payments');
    assertError(clientCall($ctx, 'POST', '/api/orders/00000000-0000-4000-8000-0000000000ff/payments', ['amountMinor' => 1, 'paymentDate' => '2026-10-21T09:00:00.000Z', 'method' => 'cash']), 404, 'not_found', 'payment for a missing order');
    assertSame(2, (int) $ctx['pdo']->query('SELECT COUNT(*) FROM payments')->fetchColumn(), 'rejected payments not stored');
};

$tests['orders: an edit cannot bring the total below the payments'] = static function (): void {
    $ctx = ordersContext();
    $created = okData(clientCall($ctx, 'POST', '/api/orders', orderBody(['items' => [['name' => 'Работа', 'quantity' => 1, 'unit' => 'шт.', 'unitPriceMinor' => 1000]]])), 201, 'create');
    $id = $created['order']['id'];
    okData(clientCall($ctx, 'POST', '/api/orders/' . $id . '/payments', ['amountMinor' => 600, 'paymentDate' => '2026-10-21T09:00:00.000Z', 'method' => 'bank_transfer']), 201, 'payment');
    $before = okData(clientCall($ctx, 'GET', '/api/orders/' . $id), 200, 'before');

    $cheaper = [['id' => $created['items'][0]['id'], 'name' => 'Работа', 'quantity' => 1, 'unit' => 'шт.', 'unitPriceMinor' => 599]];
    assertError(clientCall($ctx, 'PATCH', '/api/orders/' . $id, ['items' => $cheaper]), 409, 'paid_exceeds_total', 'items below paid');
    assertError(clientCall($ctx, 'PATCH', '/api/orders/' . $id, ['discountMinor' => 401]), 409, 'paid_exceeds_total', 'discount below paid');
    assertSame($before, okData(clientCall($ctx, 'GET', '/api/orders/' . $id), 200, 'after'), 'order, items and payments unchanged');

    $exact = okData(clientCall($ctx, 'PATCH', '/api/orders/' . $id, ['discountMinor' => 400]), 200, 'total equal to paid')['order'];
    assertSame([600, 600], [$exact['totalMinor'], $exact['paidMinor']], 'paid kept, total recomputed');
};

$tests['orders: status sets completedAt, archiving keeps it, nothing is deleted'] = static function (): void {
    $ctx = ordersContext();
    $order = okData(clientCall($ctx, 'POST', '/api/orders', orderBody(['status' => 'completed'])), 201, 'create completed')['order'];
    assertSame('2026-10-26T10:00:00.000Z', $order['completedAt'], 'completed on creation: server time');
    $uri = '/api/orders/' . $order['id'];

    $ctx['clock']->advance(3600);
    $edited = okData(clientCall($ctx, 'PATCH', $uri, ['title' => 'Новое', 'status' => 'completed']), 200, 'edit while completed')['order'];
    assertSame('2026-10-26T10:00:00.000Z', $edited['completedAt'], 'kept while completed');
    $archived = okData(clientCall($ctx, 'PATCH', $uri, ['isArchived' => true]), 200, 'archive')['order'];
    assertSame([true, 'completed', '2026-10-26T10:00:00.000Z'], [$archived['isArchived'], $archived['status'], $archived['completedAt']], 'archiving changes nothing else');
    $reopened = okData(clientCall($ctx, 'PATCH', $uri, ['status' => 'in_progress']), 200, 'reopen')['order'];
    assertTrue(!array_key_exists('completedAt', $reopened), 'cleared when not completed');
    $ctx['clock']->advance(60);
    assertSame('2026-10-26T11:01:00.000Z', okData(clientCall($ctx, 'PATCH', $uri, ['status' => 'completed']), 200, 'complete again')['order']['completedAt'], 'set again on completion');
    assertSame(false, okData(clientCall($ctx, 'PATCH', $uri, ['isArchived' => false]), 200, 'unarchive')['order']['isArchived'], 'unarchived');

    assertError(clientCall($ctx, 'PATCH', $uri, ['completedAt' => '2020-01-01T00:00:00.000Z']), 400, 'validation_error', 'completedAt from the browser');
    assertError(clientCall($ctx, 'POST', '/api/orders', orderBody(['isArchived' => true])), 400, 'validation_error', 'archived on creation');
    $delete = clientCall($ctx, 'DELETE', $uri);
    assertError($delete, 405, 'method_not_allowed', 'orders are not deleted');
    assertSame('GET, PATCH, HEAD', $delete->headers()['Allow'] ?? null, 'Allow');
    assertError(clientCall($ctx, 'DELETE', $uri . '/payments'), 405, 'method_not_allowed', 'payments are not deleted');
    assertSame(1, (int) $ctx['pdo']->query('SELECT COUNT(*) FROM orders')->fetchColumn(), 'order kept');
};

$tests['order routes require a session, the CSRF token and the CRM origin'] = static function (): void {
    $ctx = ordersContext();
    $id = okData(clientCall($ctx, 'POST', '/api/orders', orderBody()), 201, 'setup')['order']['id'];
    $before = orderTableCounts($ctx['pdo']);
    $payment = ['amountMinor' => 1, 'paymentDate' => '2026-10-21T09:00:00.000Z', 'method' => 'cash'];
    $calls = [
        ['GET', '/api/orders', null], ['GET', '/api/orders/' . $id, null], ['GET', '/api/orders/' . $id . '/payments', null],
        ['POST', '/api/orders', orderBody()], ['PATCH', '/api/orders/' . $id, ['title' => 'x']], ['POST', '/api/orders/' . $id . '/payments', $payment],
    ];
    foreach ($calls as [$method, $uri, $body]) {
        assertError(clientCall($ctx, $method, $uri, $body, ['Origin' => AuthSettings::ALLOWED_ORIGIN, 'X-CSRF-Token' => $ctx['csrf']]), 401, 'unauthorized', "$method $uri without a session");
        if ($method === 'GET') {
            continue;
        }
        foreach ([null, str_repeat('0', 64)] as $csrf) {
            assertError(clientCall($ctx, $method, $uri, $body, sessionHeaders($ctx['token'], $csrf)), 403, 'csrf_token_invalid', "$method $uri CSRF");
        }
        foreach ([null, 'https://crm.devisd.online.evil.example', 'http://crm.devisd.online'] as $origin) {
            assertError(clientCall($ctx, $method, $uri, $body, sessionHeaders($ctx['token'], $ctx['csrf'], $origin)), 403, 'origin_not_allowed', "$method $uri origin");
        }
    }
    $ctx['clock']->advance(AuthSettings::IDLE_TIMEOUT_SECONDS + 1);
    assertError(clientCall($ctx, 'GET', '/api/orders'), 401, 'unauthorized', 'expired session');
    assertSame($before, orderTableCounts($ctx['pdo']), 'nothing written');
};

$tests['order ids, texts and errors never reach SQL or the response'] = static function (): void {
    $ctx = ordersContext();
    $hostile = "x'); DROP TABLE orders;--";
    $created = okData(clientCall($ctx, 'POST', '/api/orders', orderBody(['title' => $hostile, 'notes' => "' OR '1'='1"])), 201, 'create');
    assertSame($hostile, okData(clientCall($ctx, 'GET', '/api/orders/' . $created['order']['id']), 200, 'show')['order']['title'], 'stored literally');
    foreach (['abc', rawurlencode("1' OR '1'='1"), rawurlencode($created['order']['id'] . "' --")] as $segment) {
        assertError(clientCall($ctx, 'GET', '/api/orders/' . $segment), 404, 'not_found', 'GET ' . $segment);
        assertError(clientCall($ctx, 'PATCH', '/api/orders/' . $segment, ['title' => 'x']), 404, 'not_found', 'PATCH ' . $segment);
        assertError(clientCall($ctx, 'POST', '/api/orders/' . $segment . '/payments', ['amountMinor' => 1, 'paymentDate' => '2026-10-21T09:00:00.000Z', 'method' => 'cash']), 404, 'not_found', 'payment ' . $segment);
    }
    assertSame(1, (int) $ctx['pdo']->query('SELECT COUNT(*) FROM orders')->fetchColumn(), 'table intact');

    $ctx = ordersContext(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
    foreach ([['GET', '/api/orders', null], ['POST', '/api/orders', orderBody(['title' => 'Тайный заказ'])], ['GET', '/api/orders/' . ORDER_CLIENT_ID, null]] as [$method, $uri, $body]) {
        assertSame('{"ok":false,"error":{"code":"db_unavailable","message":"Database is unavailable"}}', clientCall($ctx, $method, $uri, $body)->body(), $method . ' ' . $uri . ' without tables');
    }
    $log = (string) file_get_contents($ctx['log']);
    assertTrue(str_contains($log, 'SQLSTATE HY000'), 'log keeps the SQLSTATE');
    foreach (['SELECT', 'INSERT', 'no such table', 'Тайный заказ'] as $secret) {
        assertTrue(!str_contains($log, $secret), 'log has no ' . $secret);
    }
};

// ---------------------------------------------------------------------------------------------
// Backup import (stage 5.3.2). The fixture is the app's export format; src/lib/services/backup.test.ts
// checks the same file with the frontend validateBackupPayload. Imports run against SQLite.

/** @return array<string, mixed> */
function backupFixture(): array
{
    return json_decode((string) file_get_contents(__DIR__ . '/../../src/lib/services/backup.fixture.json'), true, 32, JSON_THROW_ON_ERROR);
}

/**
 * Logged-in owner, all routes, on an empty SQLite database.
 *
 * @return array<string, mixed>
 */
function importContext(?PDO $pdo = null): array
{
    $pdo ??= sqliteOrdersPdo(seedClient: false);
    $ctx = authContext();
    $ctx['pdo'] = $pdo;
    $ctx['log'] = tempLogFile();
    $connect = static fn (): PDO => $pdo;
    $clients = new PdoClientStore($connect);
    $orders = new PdoOrderStore($connect);
    $ctx['app'] = new Application(
        Application::routes(probe(), $ctx['auth'], $clients, $ctx['clock'], $orders, new BackupImporter(new PdoImportStore($connect), $clients, $orders, $ctx['clock'])),
        new Logger($ctx['log']),
    );
    $login = loginRequest($ctx, 'owner', $ctx['password']);
    $ctx['token'] = sessionTokenFrom($login);
    $ctx['csrf'] = decode($login)['data']['csrfToken'];

    return $ctx;
}

/** @return array<string, int> */
function importTableCounts(PDO $pdo): array
{
    $counts = [];
    foreach (['clients', 'orders', 'order_items', 'payments', 'app_meta'] as $table) {
        $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    return $counts;
}

/**
 * One client and, per case, an order with one item; stored sums as given.
 *
 * @param list<array{quantity: int|float, unitPriceMinor: int, lineTotalMinor: int}> $lines
 * @return array<string, mixed>
 */
function backupWithLines(array $lines): array
{
    $backup = backupFixture();
    $client = $backup['data']['clients'][0];
    $backup['data'] = ['clients' => [$client], 'orders' => [], 'orderItems' => [], 'payments' => []];
    foreach ($lines as $index => $line) {
        $orderId = sprintf('e5e5e5e5-0000-4000-8000-%012d', $index + 1);
        $backup['data']['orders'][] = [
            'id' => $orderId, 'number' => $index + 1, 'clientId' => $client['id'], 'title' => 'Заказ', 'status' => 'new',
            'orderDate' => '2026-03-01T09:00:00.000Z', 'discountMinor' => 0, 'totalMinor' => $line['lineTotalMinor'], 'paidMinor' => 0,
            'isArchived' => false, 'createdAt' => '2026-03-01T09:00:00.000Z', 'updatedAt' => '2026-03-01T09:00:00.000Z',
        ];
        $backup['data']['orderItems'][] = [
            'id' => sprintf('f6f6f6f6-0000-4000-8000-%012d', $index + 1), 'orderId' => $orderId, 'name' => 'Работа',
            'quantity' => $line['quantity'], 'unit' => 'шт.', 'unitPriceMinor' => $line['unitPriceMinor'],
            'lineTotalMinor' => $line['lineTotalMinor'], 'sortOrder' => 0,
        ];
    }

    return $backup;
}

$tests['backup analyzer accepts the exported format as it is'] = static function (): void {
    $plan = BackupAnalyzer::analyze(backupFixture());
    assertSame([[], 0], [$plan['errors'], $plan['errorCount']], 'no errors');
    assertSame(['clients' => 2, 'orders' => 2, 'orderItems' => 3, 'payments' => 3], $plan['counts'], 'counts');
    assertSame(8, $plan['nextOrderNumber'], 'next number after the highest one (7), as the app restores it');
    assertSame([], $plan['moneyChanges'], 'no money changes');
    $fixture = backupFixture()['data'];
    assertSame(array_column($fixture['orders'], 'id'), array_column($plan['orders'], 'id'), 'order ids kept');
    assertSame([3, 7], array_column($plan['orders'], 'number'), 'numbers kept');
    assertSame(['2026-02-12T15:00:00.000Z', null], array_column($plan['orders'], 'completedAt'), 'completedAt kept, absent stays absent');
    assertSame([true, false], array_column($plan['orders'], 'isArchived'), 'archive flags kept');
    assertSame(['vip', 'опт'], $plan['clients'][0]['tags'], 'tags kept');
};

$tests['backup analyzer recalculates sums with the shared money cases'] = static function (): void {
    $cases = json_decode((string) file_get_contents(__DIR__ . '/../../src/lib/services/money.cases.json'), true, 16, JSON_THROW_ON_ERROR);
    $lines = array_map(static fn (array $case): array => ['quantity' => $case['quantity'], 'unitPriceMinor' => $case['unitPriceMinor'], 'lineTotalMinor' => $case['expected']], $cases['lineTotals']);
    $plan = BackupAnalyzer::analyze(backupWithLines($lines));
    assertSame(0, $plan['errorCount'], 'all shared cases import: ' . json_encode($plan['errors']));
    assertSame([], $plan['moneyChanges'], 'sums already follow the rule');
    assertSame(array_column($cases['lineTotals'], 'expected'), array_column($plan['orderItems'], 'lineTotalMinor'), 'line totals');

    // Sums stored by the old binary rule: 1.005 × 100 = 100 and 0.145 × 100 = 14.
    $plan = BackupAnalyzer::analyze(backupWithLines([
        ['quantity' => 1.005, 'unitPriceMinor' => 100, 'lineTotalMinor' => 100],
        ['quantity' => 0.145, 'unitPriceMinor' => 100, 'lineTotalMinor' => 14],
        ['quantity' => 3, 'unitPriceMinor' => 3333, 'lineTotalMinor' => 9999],
    ]));
    assertSame(0, $plan['errorCount'], 'differences are reported, not errors');
    assertSame([
        ['orderId' => 'e5e5e5e5-0000-4000-8000-000000000001', 'number' => 1, 'totalBefore' => 100, 'totalAfter' => 101,
            'items' => [['itemId' => 'f6f6f6f6-0000-4000-8000-000000000001', 'lineTotalBefore' => 100, 'lineTotalAfter' => 101]]],
        ['orderId' => 'e5e5e5e5-0000-4000-8000-000000000002', 'number' => 2, 'totalBefore' => 14, 'totalAfter' => 15,
            'items' => [['itemId' => 'f6f6f6f6-0000-4000-8000-000000000002', 'lineTotalBefore' => 14, 'lineTotalAfter' => 15]]],
    ], $plan['moneyChanges'], 'changed orders with before and after');
    assertSame([101, 15, 9999], array_column($plan['orderItems'], 'lineTotalMinor'), 'recalculated lines imported');
    assertSame([101, 15, 9999], array_column($plan['orders'], 'totalMinor'), 'recalculated totals imported');
};

$tests['backup analyzer refuses payments above the recalculated total'] = static function (): void {
    // Stored line 200 for 1 × 100: the recalculated total is 100.
    $backup = backupWithLines([['quantity' => 1, 'unitPriceMinor' => 100, 'lineTotalMinor' => 200]]);
    $order = $backup['data']['orders'][0];
    $payment = ['id' => 'd4d4d4d4-0000-4000-8000-0000000000aa', 'orderId' => $order['id'], 'amountMinor' => 101, 'paymentDate' => '2026-03-02T09:00:00.000Z', 'method' => 'cash', 'createdAt' => '2026-03-02T09:00:00.000Z'];
    $backup['data']['payments'] = [$payment];
    $backup['data']['orders'][0]['paidMinor'] = 101;
    $plan = BackupAnalyzer::analyze($backup);
    assertSame(1, $plan['errorCount'], 'one kopeck over the total blocks the import');
    assertSame('data.orders[0]', $plan['errors'][0]['path'], 'error path');

    $backup['data']['payments'][0]['amountMinor'] = 100;
    $backup['data']['orders'][0]['paidMinor'] = 100;
    $plan = BackupAnalyzer::analyze($backup);
    assertSame(0, $plan['errorCount'], 'payments equal to the recalculated total are allowed');
    assertSame([100, 100], [$plan['orders'][0]['totalMinor'], $plan['orders'][0]['paidMinor']], 'payments unchanged');
};

$tests['backup analyzer blocks every integrity problem'] = static function (): void {
    $secret = 'Секретное имя ' . str_repeat('я', 250);
    $mutations = [
        'wrong app' => static fn (array &$b) => $b['app'] = 'Other',
        'other version' => static fn (array &$b) => $b['backupVersion'] = 2,
        'unknown top-level field' => static fn (array &$b) => $b['extra'] = 1,
        'data is a list' => static fn (array &$b) => $b['data'] = [],
        'orders not a list' => static fn (array &$b) => $b['data']['orders'] = 'x',
        'missing payments list' => static function (array &$b) { unset($b['data']['payments']); },
        'client missing for an order' => static fn (array &$b) => $b['data']['orders'][0]['clientId'] = '00000000-0000-4000-8000-0000000000ff',
        'order missing for an item' => static fn (array &$b) => $b['data']['orderItems'][2]['orderId'] = '00000000-0000-4000-8000-0000000000ff',
        'order missing for a payment' => static fn (array &$b) => $b['data']['payments'][2]['orderId'] = '00000000-0000-4000-8000-0000000000ff',
        'duplicate client id' => static fn (array &$b) => $b['data']['clients'][1]['id'] = $b['data']['clients'][0]['id'],
        'duplicate order id' => static fn (array &$b) => $b['data']['orders'][1]['id'] = $b['data']['orders'][0]['id'],
        'duplicate item id' => static fn (array &$b) => $b['data']['orderItems'][1]['id'] = $b['data']['orderItems'][0]['id'],
        'duplicate payment id' => static fn (array &$b) => $b['data']['payments'][1]['id'] = $b['data']['payments'][0]['id'],
        'duplicate order number' => static fn (array &$b) => $b['data']['orders'][1]['number'] = 3,
        'zero order number' => static fn (array &$b) => $b['data']['orders'][0]['number'] = 0,
        'unknown status' => static fn (array &$b) => $b['data']['orders'][0]['status'] = 'done',
        'unknown payment method' => static fn (array &$b) => $b['data']['payments'][0]['method'] = 'card',
        'impossible date' => static fn (array &$b) => $b['data']['orders'][0]['orderDate'] = '2026-02-30T09:00:00.000Z',
        'date without time' => static fn (array &$b) => $b['data']['payments'][0]['paymentDate'] = '2026-02-05',
        'client name too long' => static fn (array &$b) => $b['data']['clients'][0]['name'] = $secret,
        'bad client email' => static fn (array &$b) => $b['data']['clients'][0]['email'] = 'anna@',
        'missing isArchived' => static function (array &$b) { unset($b['data']['clients'][0]['isArchived']); },
        'unknown order field' => static fn (array &$b) => $b['data']['orders'][0]['ownerId'] = 'x',
        'order without items' => static fn (array &$b) => $b['data']['orderItems'] = array_slice($b['data']['orderItems'], 0, 2),
        'paidMinor not the payments' => static fn (array &$b) => $b['data']['orders'][1]['paidMinor'] = 4000,
        'discount above the items' => static fn (array &$b) => $b['data']['orders'][0]['discountMinor'] = 10500,
        'zero quantity' => static fn (array &$b) => $b['data']['orderItems'][0]['quantity'] = 0,
        'quantity as text' => static fn (array &$b) => $b['data']['orderItems'][0]['quantity'] = '3',
        'fractional price' => static fn (array &$b) => $b['data']['orderItems'][0]['unitPriceMinor'] = 33.5,
        'missing sortOrder' => static function (array &$b) { unset($b['data']['orderItems'][0]['sortOrder']); },
        'zero payment' => static fn (array &$b) => $b['data']['payments'][0]['amountMinor'] = 0,
        'blank tag' => static fn (array &$b) => $b['data']['clients'][0]['tags'] = [' '],
        'highest possible number' => static fn (array &$b) => $b['data']['orders'][1]['number'] = Fields::MAX_UINT32,
    ];
    foreach ($mutations as $label => $mutate) {
        $backup = backupFixture();
        $mutate($backup);
        $plan = BackupAnalyzer::analyze($backup);
        assertTrue($plan['errorCount'] > 0, $label . ' is reported');
        assertTrue(!str_contains(json_encode($plan['errors'], JSON_UNESCAPED_UNICODE), 'Секретное'), $label . ': report has no field values');
    }
};

$tests['backup import: the preview writes nothing, the import keeps ids, numbers and dates'] = static function (): void {
    $ctx = importContext();
    $preview = okData(clientCall($ctx, 'POST', '/api/import/preview', backupFixture()), 200, 'preview');
    assertSame([true, true, ['empty' => true, 'alreadyImported' => false], 8, 0, []], [$preview['valid'], $preview['canImport'], $preview['database'], $preview['nextOrderNumber'], $preview['errorCount'], $preview['errors']], 'preview');
    assertSame(['clients' => 0, 'orders' => 0, 'order_items' => 0, 'payments' => 0, 'app_meta' => 0], importTableCounts($ctx['pdo']), 'preview wrote nothing');

    $imported = okData(clientCall($ctx, 'POST', '/api/import', backupFixture()), 201, 'import')['import'];
    assertSame(['clients' => 2, 'orders' => 2, 'orderItems' => 3, 'payments' => 3], $imported['counts'], 'import counts');
    assertSame(['clients' => 2, 'orders' => 2, 'order_items' => 3, 'payments' => 3, 'app_meta' => 2], importTableCounts($ctx['pdo']), 'rows, counter and marker');
    assertSame('8', $ctx['pdo']->query("SELECT meta_value FROM app_meta WHERE meta_key = 'nextOrderNumber'")->fetchColumn(), 'order counter');

    $fixture = backupFixture()['data'];
    $orders = okData(clientCall($ctx, 'GET', '/api/orders'), 200, 'orders')['orders'];
    assertSame([$fixture['orders'][1], $fixture['orders'][0]], $orders, 'orders read back exactly as exported');
    $clients = okData(clientCall($ctx, 'GET', '/api/clients'), 200, 'clients')['clients'];
    assertSame([$fixture['clients'][1], $fixture['clients'][0]], $clients, 'clients read back exactly as exported');
    $shown = okData(clientCall($ctx, 'GET', '/api/orders/' . $fixture['orders'][0]['id']), 200, 'order');
    assertSame([$fixture['orderItems'][0], $fixture['orderItems'][1]], $shown['items'], 'items with ids and order');
    assertSame([$fixture['payments'][1], $fixture['payments'][0]], $shown['payments'], 'payments with ids and dates');

    $next = okData(clientCall($ctx, 'POST', '/api/orders', orderBody(['clientId' => $fixture['clients'][0]['id']])), 201, 'new order after import');
    assertSame(8, $next['order']['number'], 'numbering continues after the imported numbers');
};

$tests['backup import is all or nothing and only once'] = static function (): void {
    // A database with data is refused, nothing is marked.
    $ctx = importContext(sqliteOrdersPdo());
    assertError(clientCall($ctx, 'POST', '/api/import', backupFixture()), 409, 'database_not_empty', 'non-empty database');
    assertSame(0, (int) $ctx['pdo']->query("SELECT COUNT(*) FROM app_meta")->fetchColumn(), 'no marker, no counter');
    assertSame(false, okData(clientCall($ctx, 'POST', '/api/import/preview', backupFixture()), 200, 'preview')['canImport'], 'preview says no');

    // A failing write rolls back every row, the counter and the marker.
    $ctx = importContext();
    $ctx['pdo']->exec("CREATE TRIGGER fail_payment BEFORE INSERT ON payments WHEN NEW.amount_minor = 5000 BEGIN SELECT RAISE(ABORT, 'payment rejected'); END");
    assertError(clientCall($ctx, 'POST', '/api/import', backupFixture()), 409, 'import_conflict', 'write failure');
    assertSame(['clients' => 0, 'orders' => 0, 'order_items' => 0, 'payments' => 0, 'app_meta' => 0], importTableCounts($ctx['pdo']), 'nothing left behind');
    $ctx['pdo']->exec('DROP TRIGGER fail_payment');

    // An invalid file is refused by the import itself, whatever a preview said.
    $invalid = backupFixture();
    $invalid['data']['orders'][0]['status'] = 'done';
    assertError(clientCall($ctx, 'POST', '/api/import', $invalid), 400, 'import_invalid', 'invalid backup');
    assertSame(0, importTableCounts($ctx['pdo'])['clients'], 'invalid backup wrote nothing');

    okData(clientCall($ctx, 'POST', '/api/import', backupFixture()), 201, 'import');
    assertError(clientCall($ctx, 'POST', '/api/import', backupFixture()), 409, 'import_already_done', 'second import');
    foreach (['payments', 'order_items', 'orders', 'clients'] as $table) {
        $ctx['pdo']->exec('DELETE FROM ' . $table);
    }
    assertError(clientCall($ctx, 'POST', '/api/import', backupFixture()), 409, 'import_already_done', 'still refused with empty tables');
    assertSame(['empty' => true, 'alreadyImported' => true], okData(clientCall($ctx, 'POST', '/api/import/preview', backupFixture()), 200, 'preview')['database'], 'preview reports the marker');
};

$tests['backup imports cannot run concurrently'] = static function (): void {
    $file = sys_get_temp_dir() . '/devis-crm-import-' . bin2hex(random_bytes(6)) . '.sqlite';
    sqliteOrdersPdo('sqlite:' . $file, seedClient: false);
    try {
        $first = static fn (): PDO => sqliteConnection($file, 'first');
        $second = static fn (): PDO => sqliteConnection($file, 'second');
        $clock = new MutableClock(new DateTimeImmutable('2026-10-26 10:00:00.000', new DateTimeZone('UTC')));
        $firstOrders = new PdoOrderStore($first);
        $firstImport = new PdoImportStore($first);
        $secondImporter = new BackupImporter(new PdoImportStore($second), new PdoClientStore($second), new PdoOrderStore($second), $clock);

        $firstOrders->transaction(static function () use ($firstImport, $secondImporter): void {
            $firstImport->markImported(['test' => true]);
            try {
                $secondImporter->import(backupFixture());
                throw new AssertionFailed('a second import ran while the first was open');
            } catch (DatabaseUnavailableException) {
            }
        });
        expectApiError(static fn () => $secondImporter->import(backupFixture()), 409, 'import_already_done', 'after the first committed');
        assertSame(0, (int) sqliteConnection($file, 'second')->query('SELECT COUNT(*) FROM clients')->fetchColumn(), 'the second import wrote nothing');
    } finally {
        sqliteConnection($file, 'close');
        @unlink($file);
    }
};

$tests['import routes and GET /api/payments require a session, CSRF and the CRM origin'] = static function (): void {
    $ctx = importContext();
    foreach (['/api/import/preview', '/api/import'] as $uri) {
        assertError(clientCall($ctx, 'POST', $uri, backupFixture(), ['Origin' => AuthSettings::ALLOWED_ORIGIN, 'X-CSRF-Token' => $ctx['csrf']]), 401, 'unauthorized', $uri . ' without a session');
        assertError(clientCall($ctx, 'POST', $uri, backupFixture(), sessionHeaders($ctx['token'])), 403, 'csrf_token_invalid', $uri . ' without CSRF');
        assertError(clientCall($ctx, 'POST', $uri, backupFixture(), sessionHeaders($ctx['token'], str_repeat('0', 64))), 403, 'csrf_token_invalid', $uri . ' wrong CSRF');
        foreach ([null, 'https://crm.devisd.online.evil.example', 'http://crm.devisd.online'] as $origin) {
            assertError(clientCall($ctx, 'POST', $uri, backupFixture(), sessionHeaders($ctx['token'], $ctx['csrf'], $origin)), 403, 'origin_not_allowed', $uri . ' origin');
        }
        assertError(clientCall($ctx, 'POST', $uri, '{"app":"' . str_repeat('x', BackupAnalyzer::MAX_BODY_BYTES) . '"}'), 413, 'payload_too_large', $uri . ' too large');
        assertError(clientCall($ctx, 'GET', $uri), 405, 'method_not_allowed', $uri . ' GET');
    }
    assertError(clientCall($ctx, 'GET', '/api/payments', null, ['Origin' => AuthSettings::ALLOWED_ORIGIN]), 401, 'unauthorized', 'payments without a session');
    assertError(clientCall($ctx, 'POST', '/api/payments', ['amountMinor' => 1]), 405, 'method_not_allowed', 'payments are read-only');
    assertSame(['clients' => 0, 'orders' => 0, 'order_items' => 0, 'payments' => 0, 'app_meta' => 0], importTableCounts($ctx['pdo']), 'nothing written');
};

$tests['GET /api/payments lists every payment, newest first'] = static function (): void {
    $ctx = importContext();
    assertSame(['payments' => []], okData(clientCall($ctx, 'GET', '/api/payments'), 200, 'empty'), 'empty list');
    okData(clientCall($ctx, 'POST', '/api/import', backupFixture()), 201, 'import');
    $fixture = backupFixture()['data']['payments'];

    $payments = okData(clientCall($ctx, 'GET', '/api/payments'), 200, 'payments')['payments'];
    assertSame([$fixture[2], $fixture[1], $fixture[0]], $payments, 'newest date first, equal dates by id descending, same fields');
    $perOrder = okData(clientCall($ctx, 'GET', '/api/orders/' . $fixture[0]['orderId'] . '/payments'), 200, 'per order')['payments'];
    assertSame([$fixture[1], $fixture[0]], $perOrder, 'same shape as the per-order list');
};

$tests['import errors and reports reveal no internals or field values'] = static function (): void {
    sqliteOrdersPdo();
    $ctx = importContext(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
    foreach (['/api/import/preview', '/api/import'] as $uri) {
        assertSame('{"ok":false,"error":{"code":"db_unavailable","message":"Database is unavailable"}}', clientCall($ctx, 'POST', $uri, backupFixture())->body(), $uri . ' without tables');
    }
    assertSame('{"ok":false,"error":{"code":"db_unavailable","message":"Database is unavailable"}}', clientCall($ctx, 'GET', '/api/payments')->body(), 'payments without tables');
    $log = (string) file_get_contents($ctx['log']);
    foreach (['SELECT', 'INSERT', 'no such table', 'Студия Ромашка', 'anna@example.com'] as $secret) {
        assertTrue(!str_contains($log, $secret), 'log has no ' . $secret);
    }

    $ctx = importContext();
    $backup = backupFixture();
    $backup['data']['clients'][0]['email'] = 'secret-address@';
    $backup['data']['orders'][0]['title'] = str_repeat('Тайна', 60);
    $report = clientCall($ctx, 'POST', '/api/import/preview', $backup)->body();
    assertTrue(!str_contains($report, 'secret-address') && !str_contains($report, 'Тайна'), 'report names fields, not their values');
    assertSame(2, okData(clientCall($ctx, 'POST', '/api/import/preview', $backup), 200, 'preview')['errorCount'], 'both problems reported');
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
