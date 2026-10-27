<?php

declare(strict_types=1);

/*
 * One-time runner for migration 002_auth on the hosting, instead of an import in phpMyAdmin.
 * Command line only (ISPmanager → Scheduler (cron)); over HTTP it answers 404 and does nothing.
 * The file lives outside the document root, next to the private API code:
 *   <private>/api/bin/migrate-002-auth.php
 *   <private>/api/migrations/002_auth.sql, <private>/api/migrations/checks/002_auth_check.sql
 *   <private>/api/src/ (Config, Db), <private>/config/local.php (read, never changed)
 *
 *   php migrate-002-auth.php           read-only check (default)
 *   php migrate-002-auth.php --apply   apply, only from the state NOT_APPLIED
 *
 * --apply runs exactly the pinned 002_auth.sql: the three CREATE TABLE statements one by one
 * (MySQL commits DDL at once, a transaction cannot undo them), then checks the resulting
 * schema with 002_auth_check.sql, and only then records 002_auth in schema_migrations.
 * Any other state (already applied, partial, 001_init missing) or a changed file is refused.
 * Never creates users, never touches the CRM tables. Output (stdout and
 * <private>/logs/migrate-002-auth.log) holds states and SQLSTATE codes only.
 */

namespace DevisCrm\Tools\Migrate002;

use DevisCrm\Api\Config;
use DevisCrm\Api\Db;

/** SHA-256 of the files with LF line endings, as committed. */
const MIGRATION_SHA256 = '3261f955eb2e89963f177649136ea51d634bf5d7e114c67490fd0ba0de8df2ab';
const CHECK_SHA256 = '1f1b919ef44c5223982842689d663ddcdf72696f5f065d6407eb1d9710e12f51';

const AUTH_TABLES = ['users', 'sessions', 'login_attempts'];
const LOCK_NAME = 'devis_crm_migrate_002_auth';

final class RunnerError extends \RuntimeException
{
}

/** NO_001_INIT | APPLIED | NOT_APPLIED | PARTIAL */
function classify(bool $has001, bool $has002, int $authTables): string
{
    return match (true) {
        !$has001 => 'NO_001_INIT',
        $has002 && $authTables === count(AUTH_TABLES) => 'APPLIED',
        !$has002 && $authTables === 0 => 'NOT_APPLIED',
        default => 'PARTIAL',
    };
}

function normalizedHash(string $content): string
{
    return hash('sha256', str_replace("\r\n", "\n", $content));
}

/**
 * The statements of 002_auth.sql, which must be exactly: SET NAMES, three CREATE TABLE
 * (users, sessions, login_attempts) and the INSERT that records the migration.
 *
 * @return array{set: string, creates: list<string>, record: string}
 */
function migrationStatements(string $sql): array
{
    $code = (string) preg_replace('/^\s*--.*$/m', '', str_replace("\r\n", "\n", $sql));
    $statements = array_values(array_filter(array_map('trim', preg_split('/;\s*(?:\n|$)/', $code) ?: []), static fn (string $s): bool => $s !== ''));
    $patterns = [
        '/\ASET NAMES utf8mb4\z/',
        '/\ACREATE TABLE `users` \(/',
        '/\ACREATE TABLE `sessions` \(/',
        '/\ACREATE TABLE `login_attempts` \(/',
        "/\\AINSERT INTO `schema_migrations` \\(`version`\\) VALUES \\('002_auth'\\)\\z/",
    ];
    if (count($statements) !== count($patterns)) {
        throw new RunnerError('002_auth.sql does not have the expected statements');
    }
    foreach ($patterns as $index => $pattern) {
        if (preg_match($pattern, $statements[$index]) !== 1 || str_contains($statements[$index], ';')) {
            throw new RunnerError('002_auth.sql does not have the expected statements');
        }
    }

    return ['set' => $statements[0], 'creates' => array_slice($statements, 1, 3), 'record' => $statements[4]];
}

/**
 * Names of failed rows of 002_auth_check.sql. Before 002_auth is recorded, the rows that
 * depend on that record are not judged. CHECK constraints are listed only from MySQL 8.0.16.
 *
 * @param list<array{check_name: string, status: string}> $rows
 * @return list<string>
 */
function failedChecks(array $rows, bool $recorded, string $mysqlVersion): array
{
    $failed = [];
    foreach ($rows as $row) {
        if ($row['status'] === 'OK') {
            continue;
        }
        if (!$recorded && in_array($row['check_name'], ['state', 'migration 002_auth recorded'], true)) {
            continue;
        }
        if ($row['check_name'] === 'CHECK constraints on the three tables' && version_compare(preg_replace('/[^0-9.].*$/', '', $mysqlVersion) ?? '', '8.0.16', '<')) {
            continue;
        }
        $failed[] = $row['check_name'];
    }

    return $failed;
}

/** @return array{has001: bool, has002: bool, tables: int} */
function readState(\PDO $pdo): array
{
    $versions = $pdo->query("SELECT version FROM schema_migrations WHERE version IN ('001_init', '002_auth')")->fetchAll(\PDO::FETCH_COLUMN);
    $tables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('users', 'sessions', 'login_attempts')")->fetchColumn();

    return ['has001' => in_array('001_init', $versions, true), 'has002' => in_array('002_auth', $versions, true), 'tables' => $tables];
}

/** @return list<array{check_name: string, status: string}> */
function runCheck(\PDO $pdo, string $checkSql): array
{
    $rows = $pdo->query(rtrim(trim((string) preg_replace('/^\s*--.*$/m', '', $checkSql)), ';'))->fetchAll(\PDO::FETCH_ASSOC);

    return array_map(static fn (array $row): array => ['check_name' => (string) $row['check_name'], 'status' => (string) $row['status']], $rows);
}

function describe(\Throwable $error): string
{
    if ($error instanceof \PDOException) {
        $driverCode = is_array($error->errorInfo) ? ($error->errorInfo[1] ?? 'n/a') : 'n/a';

        return sprintf('database error SQLSTATE %s, driver code %s', (string) $error->getCode(), (string) $driverCode);
    }
    if ($error instanceof RunnerError || $error instanceof \DevisCrm\Api\Exceptions\DatabaseUnavailableException) {
        return $error->getMessage();
    }
    if ($error instanceof \DevisCrm\Api\Exceptions\ConfigException) {
        return 'configuration could not be loaded (config/local.php)';
    }

    return 'unexpected failure (' . $error::class . ')';
}

/** @param list<string> $argv */
function main(array $argv, string $privateRoot): int
{
    if (PHP_SAPI !== 'cli') {
        if (function_exists('http_response_code')) {
            http_response_code(404);
        }

        return 1;
    }

    $logFile = $privateRoot . '/logs/migrate-002-auth.log';
    $say = static function (string $line) use ($logFile): void {
        $stamped = gmdate('Y-m-d\TH:i:s\Z') . ' ' . $line;
        echo $stamped, PHP_EOL;
        @file_put_contents($logFile, $stamped . PHP_EOL, FILE_APPEND | LOCK_EX);
    };

    $options = array_slice($argv, 1);
    if ($options !== [] && $options !== ['--apply']) {
        $say('refused: the only option is --apply');

        return 1;
    }
    $apply = $options === ['--apply'];

    $pdo = null;
    try {
        $migrationSql = (string) @file_get_contents($privateRoot . '/api/migrations/002_auth.sql');
        $checkSql = (string) @file_get_contents($privateRoot . '/api/migrations/checks/002_auth_check.sql');
        if (normalizedHash($migrationSql) !== MIGRATION_SHA256 || normalizedHash($checkSql) !== CHECK_SHA256) {
            throw new RunnerError('002_auth.sql or 002_auth_check.sql is missing or differs from the reviewed version');
        }
        $statements = migrationStatements($migrationSql);

        require_once $privateRoot . '/api/src/bootstrap.php';
        $configFile = $privateRoot . '/config/local.php';
        $pdo = (new Db(static fn (): Config => Config::fromFile($configFile)))->pdo();
        if ((int) $pdo->query("SELECT GET_LOCK('" . LOCK_NAME . "', 0)")->fetchColumn() !== 1) {
            throw new RunnerError('another run holds the migration lock');
        }
        $mysqlVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

        $state = readState($pdo);
        $status = classify($state['has001'], $state['has002'], $state['tables']);
        $say(sprintf('mode=%s state=%s mysql=%s', $apply ? 'apply' : 'check', $status, $mysqlVersion));
        // The check query must work on this server before any table is created.
        runCheck($pdo, $checkSql);
        $say('check query runs on this server');

        if ($status === 'APPLIED') {
            $failed = failedChecks(runCheck($pdo, $checkSql), true, $mysqlVersion);
            $say($failed === [] ? 'result: 002_auth is applied, schema checks OK, nothing to do' : 'result: 002_auth is recorded but checks failed: ' . implode(', ', $failed));

            return $failed === [] ? 0 : 1;
        }
        if ($status !== 'NOT_APPLIED') {
            $say('refused: the database is not in a state where 002_auth can be applied safely');

            return 1;
        }
        if (!$apply) {
            $say('result: 002_auth can be applied; run again with --apply');

            return 0;
        }

        $pdo->exec($statements['set']);
        foreach ($statements['creates'] as $index => $create) {
            $pdo->exec($create);
            $say('created table ' . AUTH_TABLES[$index]);
        }

        $failed = failedChecks(runCheck($pdo, $checkSql), false, $mysqlVersion);
        if ($failed !== []) {
            $say('stopped: tables created but checks failed (' . implode(', ', $failed) . '); 002_auth NOT recorded, state is PARTIAL');

            return 1;
        }

        $pdo->beginTransaction();
        $pdo->exec($statements['record']);
        $pdo->commit();

        $failed = failedChecks(runCheck($pdo, $checkSql), true, $mysqlVersion);
        if ($failed !== []) {
            $say('stopped: 002_auth recorded but final checks failed: ' . implode(', ', $failed));

            return 1;
        }
        $say('result: 002_auth applied and verified');

        return 0;
    } catch (\Throwable $error) {
        if ($pdo !== null && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $say('failed: ' . describe($error) . ($pdo !== null ? '; run the check again before anything else' : ''));

        return 1;
    } finally {
        if ($pdo !== null) {
            try {
                $pdo->query("SELECT RELEASE_LOCK('" . LOCK_NAME . "')");
            } catch (\Throwable) {
            }
        }
    }
}

if (!defined('DEVIS_MIGRATE_002_NO_MAIN')) {
    exit(main(is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [], dirname(__DIR__, 2)));
}
