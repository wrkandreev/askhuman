<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Database-backed fixed-window rate limiter.
 * Bucket keys are HMACs produced by the caller; never raw IPs.
 */
final class RateLimiter
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Atomically increment a fixed-window bucket.
     * Returns the attempt count within the current window.
     */
    public function hit(string $bucketKey, int $windowSeconds): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rate_limits (bucket_key, attempts, window_started_at, expires_at)
             VALUES (:k, 1, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL :w SECOND))
             ON DUPLICATE KEY UPDATE
                attempts = IF(expires_at < UTC_TIMESTAMP(), 1, attempts + 1),
                window_started_at = IF(expires_at < UTC_TIMESTAMP(), UTC_TIMESTAMP(), window_started_at),
                expires_at = IF(expires_at < UTC_TIMESTAMP(),
                    DATE_ADD(UTC_TIMESTAMP(), INTERVAL :w2 SECOND), expires_at)'
        );
        $stmt->execute([':k' => $bucketKey, ':w' => $windowSeconds, ':w2' => $windowSeconds]);

        if (random_int(0, 99) === 0) {
            $this->cleanup();
        }

        $select = $this->pdo->prepare('SELECT attempts FROM rate_limits WHERE bucket_key = ?');
        $select->execute([$bucketKey]);
        return (int) $select->fetchColumn();
    }

    /**
     * Current count without incrementing (0 when no live bucket).
     */
    public function peek(string $bucketKey): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT attempts FROM rate_limits WHERE bucket_key = ? AND expires_at >= UTC_TIMESTAMP()'
        );
        $stmt->execute([$bucketKey]);
        $value = $stmt->fetchColumn();
        return $value === false ? 0 : (int) $value;
    }

    public function reset(string $bucketKey): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rate_limits WHERE bucket_key = ?');
        $stmt->execute([$bucketKey]);
    }

    public function cleanup(): void
    {
        $this->pdo->exec('DELETE FROM rate_limits WHERE expires_at < UTC_TIMESTAMP()');
    }
}
