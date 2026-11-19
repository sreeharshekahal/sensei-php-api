<?php

namespace App\Services;

class DjangoPasswordHasher
{
    /**
     * Create a Django-compatible pbkdf2_sha256 hash.
     */
    public static function make(string $password, int $iterations = 36000, int $length = 32): string
    {
        $salt = base64_encode(random_bytes(12));
        $hash = base64_encode(hash_pbkdf2('sha256', $password, $salt, $iterations, $length, true));

        return "pbkdf2_sha256\${$iterations}\${$salt}\${$hash}";
    }

    /**
     * Verify a plaintext password against a Django pbkdf2_sha256 hash.
     */
    public static function check(string $password, string $djangoHash): bool
    {
        $pieces = explode('$', $djangoHash);
        if (count($pieces) !== 4) return false;

        [$header, $iterations, $salt, $storedHash] = $pieces;
        if ($header !== 'pbkdf2_sha256') return false;

        $length = strlen(base64_decode($storedHash));
        $calculated = base64_encode(
            hash_pbkdf2('sha256', $password, $salt, (int)$iterations, $length, true)
        );

        return hash_equals($storedHash, $calculated);
    }
}
