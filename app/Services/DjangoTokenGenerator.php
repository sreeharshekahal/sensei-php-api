<?php

namespace App\Services;

class DjangoTokenGenerator
{
    private string $keySalt = 'django.contrib.auth.tokens.PasswordResetTokenGenerator';
    private string $secret;

    public function __construct(?string $secret = null)
    {
        $this->secret = $secret ?? (function_exists('config') ? config('app.django_secret_key', '') : '');
    }

    /**
     * Generate a password reset token for the given user.
     */
    public function makeToken(object $user): string
    {
        return $this->makeTokenWithTimestamp($user, $this->numDays(now()));
    }

    /**
     * Check that a password reset token is correct for a given user.
     */
    public function checkToken(object $user, string $token): bool
    {
        if (!$user || !$token) return false;

        $parts = explode('-', $token, 2);
        if (count($parts) !== 2) return false;

        $tsB36 = $parts[0];
        $ts = $this->base36ToInt($tsB36);
        if ($ts === false) return false;

        $expected = $this->makeTokenWithTimestamp($user, $ts);
        if (!hash_equals($expected, $token)) return false;

        // Check expiry (PASSWORD_RESET_TIMEOUT_DAYS = 2)
        $timeoutDays = 2;
        if (($this->numDays(now()) - $ts) > $timeoutDays) return false;

        return true;
    }

    private function makeTokenWithTimestamp(object $user, int $timestamp): string
    {
        $tsB36 = $this->intToBase36($timestamp);
        $hashValue = $this->makeHashValue($user, $timestamp);
        $hmac = $this->saltedHmac($this->keySalt, $hashValue, $this->secret);
        // Take every other character: hexdigest[::2]
        $shortened = '';
        for ($i = 0; $i < strlen($hmac); $i += 2) {
            $shortened .= $hmac[$i];
        }
        return "{$tsB36}-{$shortened}";
    }

    private function makeHashValue(object $user, int $timestamp): string
    {
        $loginTimestamp = '';
        if ($user->last_login) {
            $dt = new \DateTime($user->last_login);
            $dt->setTimezone(new \DateTimeZone('UTC'));
            // Truncate microseconds, remove tzinfo (match Django 1.11)
            $loginTimestamp = $dt->format('Y-m-d H:i:s');
        }
        return (string)$user->id . $user->password . $loginTimestamp . (string)$timestamp;
    }

    private function saltedHmac(string $keySalt, string $value, string $secret): string
    {
        // Django: key = hashlib.sha1((key_salt + secret).encode()).digest()
        $key = sha1($keySalt . $secret, true); // raw binary
        return hash_hmac('sha1', $value, $key);
    }

    private function numDays(\DateTimeInterface $dt): int
    {
        $epoch = new \DateTime('2001-01-01', new \DateTimeZone('UTC'));
        return (int)$epoch->diff($dt)->days;
    }

    // --- base36 helpers ---
    private function intToBase36(int $n): string
    {
        return base_convert((string)$n, 10, 36);
    }

    private function base36ToInt(string $s): int|false
    {
        if (!preg_match('/^[0-9a-z]+$/', $s)) return false;
        return (int)base_convert($s, 36, 10);
    }

    // --- UID encoding (urlsafe base64, no padding) ---
    public static function encodeUid(int $userId): string
    {
        return rtrim(strtr(base64_encode((string)$userId), '+/', '-_'), '=');
    }

    public static function decodeUid(string $uidb64): ?int
    {
        $decoded = base64_decode(strtr($uidb64, '-_', '+/'));
        if ($decoded === false || !is_numeric($decoded)) return null;
        return (int)$decoded;
    }
}
