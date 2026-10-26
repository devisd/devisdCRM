<?php

declare(strict_types=1);

namespace DevisCrm\Api\Auth;

use DevisCrm\Api\Exceptions\DatabaseUnavailableException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * AuthStore on the 002_auth tables. Prepared statements only; DATETIME(3) values in UTC.
 * Storage errors keep only SQLSTATE and the driver code (no SQL, no parameters).
 */
final class PdoAuthStore implements AuthStore
{
    private const DB_TIME_FORMAT = 'Y-m-d H:i:s.v';

    /** @var \Closure(): PDO */
    private \Closure $pdo;

    /**
     * @param callable(): PDO $pdo Usually fn () => $db->pdo().
     */
    public function __construct(callable $pdo)
    {
        $this->pdo = \Closure::fromCallable($pdo);
    }

    public function findUserByLogin(string $normalizedLogin): ?UserRecord
    {
        $row = $this->fetchOne('SELECT id, login, password_hash FROM users WHERE login = ? LIMIT 1', [$normalizedLogin]);

        return $row === null ? null : new UserRecord((string) $row['id'], (string) $row['login'], (string) $row['password_hash']);
    }

    public function createSession(AuthSession $session, string $tokenHash): void
    {
        $this->execute(
            'INSERT INTO sessions (id, user_id, token_hash, csrf_token, created_at, last_seen_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $session->id,
                $session->userId,
                $tokenHash,
                $session->csrfToken,
                self::toDb($session->createdAt),
                self::toDb($session->lastSeenAt),
                self::toDb($session->expiresAt),
            ],
        );
    }

    public function findSessionByTokenHash(string $tokenHash): ?AuthSession
    {
        $row = $this->fetchOne(
            'SELECT s.id, s.user_id, s.csrf_token, s.created_at, s.last_seen_at, s.expires_at, u.login'
            . ' FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = ? LIMIT 1',
            [$tokenHash],
        );
        if ($row === null) {
            return null;
        }

        return new AuthSession(
            (string) $row['id'],
            (string) $row['user_id'],
            (string) $row['login'],
            (string) $row['csrf_token'],
            self::fromDb((string) $row['created_at']),
            self::fromDb((string) $row['last_seen_at']),
            self::fromDb((string) $row['expires_at']),
        );
    }

    public function touchSession(string $sessionId, \DateTimeImmutable $lastSeenAt): void
    {
        $this->execute('UPDATE sessions SET last_seen_at = ? WHERE id = ?', [self::toDb($lastSeenAt), $sessionId]);
    }

    public function deleteSession(string $sessionId): void
    {
        $this->execute('DELETE FROM sessions WHERE id = ?', [$sessionId]);
    }

    public function deleteExpiredSessions(\DateTimeImmutable $now, \DateTimeImmutable $idleCutoff): void
    {
        $this->execute('DELETE FROM sessions WHERE expires_at <= ? OR last_seen_at <= ?', [self::toDb($now), self::toDb($idleCutoff)]);
    }

    public function recordAttempt(string $normalizedLogin, string $ipAddress, \DateTimeImmutable $at, bool $succeeded): void
    {
        $this->execute(
            'INSERT INTO login_attempts (login_normalized, ip_address, attempted_at, succeeded) VALUES (?, ?, ?, ?)',
            [$normalizedLogin, $ipAddress, self::toDb($at), $succeeded ? 1 : 0],
        );
    }

    public function failureTimesForLogin(string $normalizedLogin, \DateTimeImmutable $since): array
    {
        return $this->failureTimes('login_normalized', $normalizedLogin, $since);
    }

    public function failureTimesForIp(string $ipAddress, \DateTimeImmutable $since): array
    {
        return $this->failureTimes('ip_address', $ipAddress, $since);
    }

    public function purgeAttemptsBefore(\DateTimeImmutable $cutoff): void
    {
        $this->execute('DELETE FROM login_attempts WHERE attempted_at < ?', [self::toDb($cutoff)]);
    }

    /**
     * @param 'login_normalized'|'ip_address' $column Fixed by the callers above, never user input.
     * @return list<\DateTimeImmutable>
     */
    private function failureTimes(string $column, string $value, \DateTimeImmutable $since): array
    {
        $statement = $this->run(
            'SELECT attempted_at FROM login_attempts WHERE ' . $column . ' = ? AND succeeded = 0 AND attempted_at >= ?'
            . ' ORDER BY attempted_at ASC LIMIT 100',
            [$value, self::toDb($since)],
        );
        $times = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $attemptedAt) {
            $times[] = self::fromDb((string) $attemptedAt);
        }

        return $times;
    }

    /**
     * @param list<string|int> $params
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $params): ?array
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<string|int> $params
     */
    private function execute(string $sql, array $params): void
    {
        $this->run($sql, $params);
    }

    /**
     * @param list<string|int> $params
     */
    private function run(string $sql, array $params): PDOStatement
    {
        $pdo = ($this->pdo)();
        try {
            $statement = $pdo->prepare($sql);
            $statement->execute($params);

            return $statement;
        } catch (PDOException | \ErrorException $exception) {
            $driverCode = $exception instanceof PDOException && is_array($exception->errorInfo) ? ($exception->errorInfo[1] ?? null) : null;
            throw new DatabaseUnavailableException(sprintf(
                'Auth query failed: SQLSTATE %s, driver code %s',
                (string) $exception->getCode(),
                $driverCode === null ? 'n/a' : (string) $driverCode,
            ));
        }
    }

    private static function toDb(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format(self::DB_TIME_FORMAT);
    }

    private static function fromDb(string $value): \DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        $time = \DateTimeImmutable::createFromFormat('!' . self::DB_TIME_FORMAT, $value, $utc)
            ?: \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $utc);
        if ($time === false) {
            throw new DatabaseUnavailableException('Auth store returned an unreadable timestamp');
        }

        return $time;
    }
}
