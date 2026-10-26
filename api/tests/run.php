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

    $piped = runCommand([PHP_BINARY, OWNER_PASSWORD_TOOL], "owner\n{$marker}\n{$marker}\n");
    assertSame(1, $piped['exitCode'], 'pipe: exit code');
    assertTrue(str_contains($piped['stderr'], 'not an interactive terminal'), 'pipe: refuses without a terminal');
    assertTrue(!str_contains($piped['stdout'] . $piped['stderr'], $marker), 'pipe: password is not echoed');
    assertSame('', $piped['stdout'], 'pipe: no hash printed');
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
