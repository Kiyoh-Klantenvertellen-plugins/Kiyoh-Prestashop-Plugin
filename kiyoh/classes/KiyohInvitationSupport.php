<?php
/**
 * Invitation logging and retry policy helpers (testable without PrestaShop).
 *
 * @author    Kiyoh
 * @copyright 2024 Kiyoh
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class KiyohInvitationSupport
{
    public const MAX_RETRY_ATTEMPTS = 3;

    /** Backoff delays in seconds before attempts 1, 2, and 3 (after a failure). */
    public const RETRY_BACKOFF_SECONDS = [900, 3600, 14400];

    /**
     * Mask an email address for PrestaShop logs (never log the full address).
     */
    public static function maskEmailForLog(string $email): string
    {
        $email = trim($email);
        if ($email === '' || strpos($email, '@') === false) {
            return '***';
        }

        [$local, $domain] = explode('@', $email, 2);
        $local = trim($local);
        $domain = trim($domain);
        if ($local === '' || $domain === '') {
            return '***';
        }

        $first = mb_substr($local, 0, 1);
        if ($first === '') {
            $first = '*';
        }

        return $first . '***@' . $domain;
    }

    /**
     * Parse "HTTP 503" style messages from API exceptions.
     */
    public static function parseHttpStatusFromMessage(string $message): ?int
    {
        if (preg_match('/\bHTTP\s+(\d{3})\b/i', $message, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Whether an HTTP status represents a temporary invite failure worth retrying.
     */
    public static function isTemporaryHttpStatus(?int $httpStatus): bool
    {
        if ($httpStatus === null) {
            return false;
        }

        if (in_array($httpStatus, [408, 425, 429], true)) {
            return true;
        }

        return $httpStatus >= 500 && $httpStatus <= 599;
    }

    /**
     * Classify an invite failure for retry scheduling.
     *
     * @return string temporary|permanent
     */
    public static function classifyInviteFailure(string $message, ?int $httpStatus = null): string
    {
        if ($httpStatus === null) {
            $httpStatus = self::parseHttpStatusFromMessage($message);
        }

        if (stripos($message, 'cURL error') !== false) {
            return 'temporary';
        }

        if (self::isTemporaryHttpStatus($httpStatus)) {
            return 'temporary';
        }

        if ($httpStatus !== null && $httpStatus >= 400 && $httpStatus <= 599) {
            return 'permanent';
        }

        if (stripos($message, 'validation failed') !== false) {
            return 'permanent';
        }

        return 'permanent';
    }

    /**
     * Seconds to wait before the next retry after attempt $failedAttempts (0-based).
     */
    public static function retryBackoffSeconds(int $failedAttempts): int
    {
        $index = max(0, min($failedAttempts, count(self::RETRY_BACKOFF_SECONDS) - 1));

        return self::RETRY_BACKOFF_SECONDS[$index];
    }

    /**
     * Whether a failed row is eligible for another retry attempt.
     */
    public static function isRetryEligible(string $status, string $failureClass, int $retryAttempts): bool
    {
        if ($status !== 'failed' || $failureClass !== 'temporary') {
            return false;
        }

        return $retryAttempts < self::MAX_RETRY_ATTEMPTS;
    }

    /**
     * Whether a new invite must not be sent for this order (hook / manual send).
     */
    public static function blocksNewInviteForOrder(string $status, ?string $failureClass, int $retryAttempts): bool
    {
        if (in_array($status, ['sent', 'pending'], true)) {
            return true;
        }

        if ($status === 'failed_final') {
            return true;
        }

        if ($status === 'failed') {
            if ($failureClass === '' || $failureClass === null) {
                return true;
            }
            if ($failureClass === 'permanent') {
                return true;
            }
            if ($failureClass === 'temporary' && self::isRetryEligible($status, $failureClass, $retryAttempts)) {
                return true;
            }
            if ($failureClass === 'temporary' && $retryAttempts >= self::MAX_RETRY_ATTEMPTS) {
                return true;
            }
        }

        return false;
    }
}