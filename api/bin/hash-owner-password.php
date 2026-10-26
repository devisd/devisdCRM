<?php

declare(strict_types=1);

/*
 * Prints a password hash for the CRM owner, for manual insertion into `users`
 * (migration 002_auth) via phpMyAdmin. Local command-line tool: it never connects to a
 * database, never writes files and never prints the password.
 *
 * Usage (from the repository root, in an interactive terminal):
 *   php api/bin/hash-owner-password.php
 *
 * The login is read as normal input; the password is read twice with hidden input and
 * cannot be passed as an argument. If hidden input is impossible (no terminal, no stty
 * or PowerShell), the tool refuses instead of reading the password in plain text.
 *
 * Output: login, hash algorithm, hash and a ready INSERT statement. The hash is not the
 * password, but keep it confidential: it allows offline guessing. Argon2id is used when
 * this PHP supports it; the hosting PHP must support the same algorithm to verify it.
 */

namespace DevisCrm\Tools\OwnerPassword;

use RuntimeException;

const MIN_PASSWORD_LENGTH = 12;
const LOGIN_PATTERN = '/^[a-z0-9][a-z0-9._@-]{2,63}$/';

final class ToolError extends RuntimeException
{
}

function normalizeLogin(string $login): string
{
    return strtolower(trim($login));
}

function validateLogin(string $login): ?string
{
    if (preg_match(LOGIN_PATTERN, $login) !== 1) {
        return 'login must be 3-64 characters: a-z, 0-9 and . _ @ - (starting with a letter or digit)';
    }

    return null;
}

/**
 * Argon2id when available, otherwise PHP's default algorithm (bcrypt). With $bcrypt (option
 * --bcrypt) always bcrypt: every PHP verifies it, while Argon2id hashes made on this computer
 * verify only on a server whose PHP supports Argon2id as well.
 */
function chooseAlgorithm(bool $bcrypt = false): string
{
    if ($bcrypt) {
        return PASSWORD_BCRYPT;
    }
    if (defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true)) {
        return PASSWORD_ARGON2ID;
    }

    return PASSWORD_DEFAULT;
}

function validatePassword(string $password, string $algorithm): ?string
{
    if ($password === '') {
        return 'password must not be empty';
    }
    if (preg_match('//u', $password) !== 1) {
        return 'password is not valid UTF-8';
    }
    if (preg_match_all('/./us', $password) < MIN_PASSWORD_LENGTH) {
        return 'password must be at least ' . MIN_PASSWORD_LENGTH . ' characters long';
    }
    if ($algorithm === PASSWORD_BCRYPT && strlen($password) > 72) {
        return 'password is longer than 72 bytes, which bcrypt would silently truncate';
    }

    return null;
}

function createHash(string $password, string $algorithm): string
{
    $error = validatePassword($password, $algorithm);
    if ($error !== null) {
        throw new ToolError($error);
    }

    try {
        $hash = password_hash($password, $algorithm);
    } catch (\ValueError) {
        throw new ToolError('password_hash() rejected the algorithm');
    }
    if (!password_verify($password, $hash)) {
        throw new ToolError('the generated hash did not verify');
    }

    return $hash;
}

function newUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
}

function insertStatement(string $id, string $login, string $hash): string
{
    // Values are interpolated, so their character sets are checked first (no quotes possible).
    if (preg_match('/^[0-9a-f-]{36}$/', $id) !== 1 || validateLogin($login) !== null || preg_match('#^[A-Za-z0-9$./+=,-]+$#', $hash) !== 1) {
        throw new ToolError('refusing to build an INSERT from unexpected values');
    }

    return sprintf(
        "INSERT INTO `users` (`id`, `login`, `password_hash`, `created_at`, `updated_at`) VALUES ('%s', '%s', '%s', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3));",
        $id,
        $login,
        $hash,
    );
}

function readHiddenLine(string $prompt): string
{
    if (!function_exists('stream_isatty') || !stream_isatty(STDIN)) {
        throw new ToolError('standard input is not an interactive terminal; hidden input is impossible, so the password is not read');
    }
    fwrite(STDERR, $prompt);

    $line = PHP_OS_FAMILY === 'Windows' ? readHiddenWindows() : readHiddenUnix();
    fwrite(STDERR, PHP_EOL);

    return rtrim($line, "\r\n");
}

function readHiddenWindows(): string
{
    // The password travels back only through the stdout pipe, base64-encoded UTF-8.
    $script = '$s = $Host.UI.ReadLineAsSecureString(); '
        . '$b = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($s); '
        . 'try { $p = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($b); '
        . '[Console]::Out.Write([Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($p))) } '
        . 'finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b) }';
    $encoded = base64_encode(asciiToUtf16le($script));

    $process = proc_open(
        ['powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', $encoded],
        [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new ToolError('could not start PowerShell for hidden input');
    }
    $output = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $decoded = base64_decode(trim($output), true);
    if ($exitCode !== 0 || $decoded === false) {
        throw new ToolError('hidden input via PowerShell failed');
    }

    return $decoded;
}

/** UTF-16LE encoding of an ASCII script for -EncodedCommand, without mbstring/iconv. */
function asciiToUtf16le(string $ascii): string
{
    $out = '';
    foreach (str_split($ascii) as $char) {
        $out .= $char . "\0";
    }

    return $out;
}

function readHiddenUnix(): string
{
    $saved = trim((string) @shell_exec('stty -g 2>/dev/null'));
    if ($saved === '') {
        throw new ToolError('stty is unavailable; hidden input is impossible, so the password is not read');
    }
    @shell_exec('stty -echo 2>/dev/null');
    try {
        $line = fgets(STDIN);
    } finally {
        @shell_exec('stty ' . escapeshellarg($saved) . ' 2>/dev/null');
    }
    if ($line === false) {
        throw new ToolError('no password was entered');
    }

    return $line;
}

/** @param list<string> $argv */
function main(array $argv): int
{
    if (PHP_SAPI !== 'cli') {
        if (function_exists('http_response_code')) {
            http_response_code(404);
        }
        echo 'This tool runs only from the command line.';

        return 1;
    }

    $password = '';
    $confirmation = '';
    try {
        $bcrypt = array_slice($argv, 1) === ['--bcrypt'];
        if (count($argv) > 1 && !$bcrypt) {
            throw new ToolError('this tool takes no arguments other than --bcrypt; the password is read interactively with hidden input');
        }
        if (!function_exists('stream_isatty') || !stream_isatty(STDIN)) {
            throw new ToolError('standard input is not an interactive terminal; hidden input is impossible, so nothing is read');
        }

        fwrite(STDERR, 'Owner login: ');
        $login = normalizeLogin((string) fgets(STDIN));
        $loginError = validateLogin($login);
        if ($loginError !== null) {
            throw new ToolError($loginError);
        }

        $algorithm = chooseAlgorithm($bcrypt);
        $password = readHiddenLine('Password (hidden): ');
        $passwordError = validatePassword($password, $algorithm);
        if ($passwordError !== null) {
            throw new ToolError($passwordError);
        }
        $confirmation = readHiddenLine('Repeat password (hidden): ');
        if (!hash_equals($password, $confirmation)) {
            throw new ToolError('the passwords do not match');
        }

        $hash = createHash($password, $algorithm);
    } catch (ToolError $error) {
        fwrite(STDERR, 'Error: ' . $error->getMessage() . PHP_EOL);

        return 1;
    } catch (\Throwable $error) {
        fwrite(STDERR, 'Error: unexpected failure (' . get_class($error) . ')' . PHP_EOL);

        return 1;
    } finally {
        $password = '';
        $confirmation = '';
    }

    $info = password_get_info($hash);
    echo 'login: ', $login, PHP_EOL;
    echo 'algorithm: ', (string) $info['algoName'], PHP_EOL;
    echo 'password_hash: ', $hash, PHP_EOL, PHP_EOL;
    echo '-- phpMyAdmin, CRM database, after migration 002_auth:', PHP_EOL;
    echo insertStatement(newUuid(), $login, $hash), PHP_EOL;

    return 0;
}

if (!defined('DEVIS_OWNER_PASSWORD_NO_MAIN')) {
    exit(main(is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : []));
}
