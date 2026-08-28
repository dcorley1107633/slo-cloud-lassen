<?php
declare(strict_types=1);

namespace SLOCloud\Core;

use RuntimeException;

/**
 * Brute-force limiter for authentication attempts.
 *
 * Improvements over the original per-IP JSON files:
 *
 * - **Both signals.** An attempt is counted against the client IP *and* the
 *   targeted account, so password spraying (many accounts, one IP) and
 *   distributed guessing (one account, many IPs) are both slowed. Either
 *   bucket alone can block the attempt.
 * - **Atomic.** Every read-modify-write happens under an exclusive `flock` on
 *   the bucket file, so concurrent requests cannot each read the same count and
 *   write back the same increment.
 * - **Progressive.** The lock grows with consecutive failures instead of
 *   applying one flat window, and the failure count is preserved across locks
 *   so an attacker cannot reset the ramp by waiting out a single window.
 * - **Safe reset.** Only a *successful* authentication clears a bucket, and it
 *   clears only the buckets involved in that authentication.
 *
 * Storage lives under the protected application data root, never under the
 * document root.
 *
 * Scope limitation: `flock` coordinates processes on one host with a shared
 * filesystem. A multi-node deployment behind a load balancer needs a shared
 * backend (database or cache) for the counters to be global; the interface
 * here is deliberately backend-agnostic so that swap does not touch callers.
 *
 * @package SLOCloud\Core
 */
final class LoginLimiter
{
    /** Failures allowed before any lock is applied, for interactive login. */
    private const DEFAULT_FREE_ATTEMPTS = 3;

    /** First lock duration in seconds, for interactive login. */
    private const DEFAULT_BASE_LOCK_SECONDS = 60;

    /** Upper bound on a single lock window, for interactive login. */
    private const DEFAULT_MAX_LOCK_SECONDS = 3600;

    /**
     * Default counting window, for interactive login.
     *
     * Long by design: a password guesser must not be able to reset their
     * allowance simply by pausing. Short windows belong on paths where a
     * lockout would hit a shared identifier instead of one guesser.
     */
    private const DEFAULT_WINDOW_SECONDS = 86400;

    /**
     * Hard cap on stored attempts per bucket.
     *
     * Once the allowance is spent the bucket is locked regardless, so the exact
     * count past that point does not matter and the list can be truncated to
     * keep the file bounded no matter how much traffic is thrown at it.
     */
    private const MAX_STORED_ATTEMPTS = 256;

    private string $storagePath;
    private int $freeAttempts;
    private int $baseLockSeconds;
    private int $maxLockSeconds;
    private int $windowSeconds;

    /**
     * @param string $storagePath Directory for the counter files
     * @param int $freeAttempts Failures allowed within the window before a lock applies
     * @param int $baseLockSeconds Duration of the first lock
     * @param int $maxLockSeconds Ceiling for a single lock. Setting this equal
     *        to $baseLockSeconds disables escalation, giving a flat window -
     *        the right shape wherever a lockout would hit a shared identifier
     *        rather than one guesser (see the LTI launch path).
     * @param int $windowSeconds How far back failures are counted. Failures
     *        older than this stop counting, so the allowance means "N within
     *        this window" rather than "N ever".
     */
    public function __construct(
        string $storagePath,
        int $freeAttempts = self::DEFAULT_FREE_ATTEMPTS,
        int $baseLockSeconds = self::DEFAULT_BASE_LOCK_SECONDS,
        int $maxLockSeconds = self::DEFAULT_MAX_LOCK_SECONDS,
        int $windowSeconds = self::DEFAULT_WINDOW_SECONDS
    ) {
        $this->storagePath = rtrim(str_replace('\\', '/', $storagePath), '/');
        $this->freeAttempts = max(0, $freeAttempts);
        $this->baseLockSeconds = max(1, $baseLockSeconds);
        $this->maxLockSeconds = max($this->baseLockSeconds, $maxLockSeconds);
        $this->windowSeconds = max(1, $windowSeconds);

        if (!is_dir($this->storagePath)
            && !mkdir($this->storagePath, 0700, true)
            && !is_dir($this->storagePath)) {
            throw new RuntimeException('Unable to create login limiter storage.');
        }
        @chmod($this->storagePath, 0700);
    }

    /**
     * Build the bucket keys for an attempt.
     *
     * The account key is derived from a normalized identifier so that
     * "User@Example.com" and "user@example.com" share one bucket. Identifiers
     * are hashed: the limiter never stores an address in a filename.
     *
     * @param string $ip Client address (REMOTE_ADDR only - forwarded headers are spoofable)
     * @param string|null $account Submitted account identifier, when known
     * @return string[]
     */
    public static function keysFor(string $ip, ?string $account = null): array
    {
        $keys = [];
        if ($ip !== '') {
            $keys[] = 'ip_' . hash('sha256', $ip);
        }
        $account = $account === null ? '' : strtolower(trim($account));
        if ($account !== '') {
            $keys[] = 'account_' . hash('sha256', $account);
        }
        return $keys;
    }

    /**
     * Seconds the caller must wait before another attempt is accepted.
     *
     * @param string[] $keys
     * @return int 0 when the attempt may proceed
     */
    public function retryAfter(array $keys): int
    {
        $longest = 0;
        foreach ($keys as $key) {
            $bucket = $this->read($key);
            $remaining = (int)$bucket['until'] - time();
            if ($remaining > $longest) {
                $longest = $remaining;
            }
        }
        return max(0, $longest);
    }

    /**
     * Record one failed attempt against every supplied bucket.
     *
     * @param string[] $keys
     * @param string|null $distinctAttempt Optional value identifying *which*
     *        attempt this is. When supplied, a repeat of a value already seen
     *        within the counting window is treated as the same attempt arriving
     *        again and is not counted, so a client retrying one request cannot
     *        drive itself into a lockout.
     *
     *        The value must be something an attacker has to change in order to
     *        make a new guess - for OAuth that is the signature. Keying it on
     *        anything the caller can hold constant while still varying the
     *        guess (a nonce, a timestamp) would let them pin that value and
     *        collapse every guess into one counted failure.
     * @return int The longest lock now in force, in seconds (0 if still under the free allowance)
     */
    public function recordFailure(array $keys, ?string $distinctAttempt = null): int
    {
        $freeAttempts = $this->freeAttempts;
        $baseLockSeconds = $this->baseLockSeconds;
        $maxLockSeconds = $this->maxLockSeconds;

        // Truncated because the value only has to distinguish attempts from one
        // another within a single bucket, not resist collision search.
        $fingerprint = $distinctAttempt === null
            ? null
            : substr(hash('sha256', $distinctAttempt), 0, 16);

        $longest = 0;
        foreach ($keys as $key) {
            $lock = $this->mutate($key, static function (array $bucket) use (
                $freeAttempts,
                $baseLockSeconds,
                $maxLockSeconds,
                $fingerprint
            ): array {
                // $bucket['attempts'] arrives already pruned to the window by
                // normalize(), so everything here counts against the allowance.
                if ($fingerprint !== null) {
                    foreach ($bucket['attempts'] as $attempt) {
                        if (($attempt['f'] ?? null) === $fingerprint) {
                            // The same attempt arriving again. Leave the count
                            // and any existing lock untouched.
                            return $bucket;
                        }
                    }
                }

                $bucket['attempts'][] = ['t' => time(), 'f' => $fingerprint];
                $bucket['attempts'] = array_slice($bucket['attempts'], -self::MAX_STORED_ATTEMPTS);

                $overage = count($bucket['attempts']) - $freeAttempts;
                if ($overage > 0) {
                    // When base and max are equal this collapses to a flat
                    // window; the doubling only applies where escalation was
                    // asked for. The exponent is capped before it is applied so
                    // a long run of failures cannot overflow into a float.
                    $seconds = min(
                        $baseLockSeconds * (2 ** min($overage - 1, 30)),
                        $maxLockSeconds
                    );
                    $bucket['until'] = time() + (int)$seconds;
                }
                return $bucket;
            });
            $remaining = $lock - time();
            if ($remaining > $longest) {
                $longest = $remaining;
            }
        }
        return max(0, $longest);
    }

    /**
     * Clear buckets after a successful authentication.
     *
     * @param string[] $keys
     */
    public function clear(array $keys): void
    {
        foreach ($keys as $key) {
            $path = $this->pathFor($key);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Remove decayed bucket files so the directory does not grow without bound.
     */
    public function purgeExpired(): void
    {
        $entries = glob($this->storagePath . '/bucket_*.json') ?: [];
        // A file untouched for a full window holds nothing that still counts.
        $cutoff = time() - $this->windowSeconds;
        foreach ($entries as $entry) {
            if (@filemtime($entry) !== false && @filemtime($entry) < $cutoff) {
                @unlink($entry);
            }
        }
    }

    /**
     * Read a bucket without taking a write lock.
     *
     * @return array{count:int,until:int,seen?:int}
     */
    private function read(string $key): array
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return ['count' => 0, 'until' => 0];
        }
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return ['count' => 0, 'until' => 0];
        }
        @flock($handle, LOCK_SH);
        $contents = (string)stream_get_contents($handle);
        @flock($handle, LOCK_UN);
        fclose($handle);
        return $this->normalize($contents);
    }

    /**
     * Apply a change to a bucket under an exclusive lock.
     *
     * @param callable(array):array $change
     * @return int The bucket's lock expiry after the change
     */
    private function mutate(string $key, callable $change): int
    {
        $path = $this->pathFor($key);
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            // Storage failure must not become an authentication bypass signal,
            // but it also must not lock every user out. Report no lock and let
            // the caller's logging surface the storage problem.
            return 0;
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return 0;
        }

        $bucket = $this->normalize((string)stream_get_contents($handle));
        $bucket = $change($bucket);

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($bucket, JSON_THROW_ON_ERROR));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        @chmod($path, 0600);

        return (int)$bucket['until'];
    }

    /**
     * Decode a stored bucket, discarding malformed or fully decayed state.
     *
     * A bucket whose lock has expired keeps its count: the escalation ladder is
     * only reset by a successful login or by full decay.
     *
     * @return array{until:int,attempts:list<array{t:int,f:string|null}>}
     */
    private function normalize(string $contents): array
    {
        $fresh = ['until' => 0, 'attempts' => []];

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return $fresh;
        }

        // Failures older than the window stop counting, so the allowance means
        // "N within the window" rather than "N ever". Pruning on read is what
        // makes the window rolling rather than fixed, so there is no boundary
        // at which a caller gets a fresh allowance all at once.
        $cutoff = time() - $this->windowSeconds;
        $attempts = [];
        foreach (is_array($data['attempts'] ?? null) ? $data['attempts'] : [] as $attempt) {
            if (!is_array($attempt)) {
                continue;
            }
            $at = (int)($attempt['t'] ?? 0);
            if ($at <= $cutoff || $at > time() + 60) {
                // Out of window, or stamped implausibly far in the future -
                // a clock jump must not grant an indefinite allowance.
                continue;
            }
            $fingerprint = $attempt['f'] ?? null;
            $attempts[] = [
                't' => $at,
                'f' => is_string($fingerprint) && ctype_xdigit($fingerprint) ? $fingerprint : null,
            ];
        }

        return [
            'until' => (int)($data['until'] ?? 0),
            'attempts' => array_slice($attempts, -self::MAX_STORED_ATTEMPTS),
        ];
    }

    private function pathFor(string $key): string
    {
        // Keys are already hex digests from keysFor(); the extra filter keeps a
        // hand-built key from reaching the filesystem as a path fragment.
        $safe = preg_replace('/[^a-z0-9_]/i', '', $key) ?? '';
        return $this->storagePath . '/bucket_' . $safe . '.json';
    }
}
