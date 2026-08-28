<?php
declare(strict_types=1);

namespace SLOCloud\Core;

/**
 * Single source of truth for password acceptability.
 *
 * The policy is deliberately length-and-blocklist based rather than
 * composition based. Character-class rules ("one uppercase, one digit, one
 * symbol") push users toward short predictable patterns like `Password1!` and
 * are no longer recommended; a long passphrase checked against known-bad values
 * is the stronger control.
 *
 * Callers get a list of human-readable problems back, so the same wording
 * appears in the admin UI, the setup wizard, and the CLI account tool.
 *
 * @package SLOCloud\Core
 */
final class PasswordPolicy
{
    /** Minimum length in characters (not bytes). */
    public const MINIMUM_LENGTH = 12;

    /**
     * Values that must never be accepted.
     *
     * Includes the generic top-of-every-breach-list entries plus the defaults
     * this product itself has shipped, so an operator cannot re-enter a
     * documented default during a rebuild or an upgrade.
     */
    private const BLOCKLIST = [
        'password', 'password1', 'password123', 'passw0rd',
        'admin', 'admin123', 'administrator', 'root', 'toor',
        'letmein', 'welcome', 'welcome1', 'changeme', 'default',
        'qwerty', 'qwerty123', 'qwertyuiop', '123456', '1234567',
        '12345678', '123456789', '1234567890', '111111', '000000',
        'iloveyou', 'monkey', 'dragon', 'sunshine', 'princess',
        'abc123', 'football', 'baseball', 'trustno1', 'starwars',
        // Defaults shipped by this application.
        'slopass', 'slocloud', 'slocloud123', 'sloadmin',
    ];

    /**
     * Check a candidate password.
     *
     * @param string $password The proposed password
     * @param string[] $context Values the password must not simply restate,
     *                          such as the account's email address or name
     * @return string[] Problems found; an empty array means the password is acceptable
     */
    public static function validate(string $password, array $context = []): array
    {
        $problems = [];

        $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
        if ($length < self::MINIMUM_LENGTH) {
            $problems[] = sprintf(
                'Password must be at least %d characters. A memorable passphrase of several words is a good choice.',
                self::MINIMUM_LENGTH
            );
        }

        $normalized = self::normalize($password);
        if ($normalized !== '' && in_array($normalized, self::BLOCKLIST, true)) {
            $problems[] = 'Choose a password that is not a common or default password.';
        }

        // Trailing digits are the usual way a blocked value is smuggled past a
        // literal list ("password2024"), so the stem is checked as well.
        $stem = rtrim($normalized, '0123456789!');
        if ($stem !== '' && $stem !== $normalized && in_array($stem, self::BLOCKLIST, true)) {
            $problems[] = 'Choose a password that is not a common or default password.';
        }

        foreach ($context as $value) {
            $value = self::normalize((string)$value);
            if ($value === '' || strlen($value) < 4) {
                continue;
            }
            if ($normalized === $value || str_contains($normalized, $value)) {
                $problems[] = 'Password must not contain your name, email address, or username.';
                break;
            }
            // Compare against the local part of an email as well.
            $localPart = self::normalize((string)strstr($value, '@', true));
            if ($localPart !== '' && strlen($localPart) >= 4 && str_contains($normalized, $localPart)) {
                $problems[] = 'Password must not contain your name, email address, or username.';
                break;
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * Convenience wrapper for callers that only need the first problem.
     *
     * @param string[] $context
     * @return string|null Null when the password is acceptable
     */
    public static function firstProblem(string $password, array $context = []): ?string
    {
        return self::validate($password, $context)[0] ?? null;
    }

    /**
     * Fold a value to the form the blocklist is written in.
     */
    private static function normalize(string $value): string
    {
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
