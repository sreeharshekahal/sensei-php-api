<?php

namespace Tests\Unit;

use App\Services\DjangoPasswordHasher;
use App\Services\DjangoTokenGenerator;
use PHPUnit\Framework\TestCase;

class DjangoAuthServicesTest extends TestCase
{
    public function test_django_password_hasher_make_and_check(): void
    {
        $password = 'TestSecret123!';
        $hash = DjangoPasswordHasher::make($password);

        $this->assertStringStartsWith('pbkdf2_sha256$36000$', $hash);
        $this->assertTrue(DjangoPasswordHasher::check($password, $hash));
        $this->assertFalse(DjangoPasswordHasher::check('WrongPassword', $hash));
    }

    public function test_django_password_hasher_with_known_django_hash(): void
    {
        // Sample hash format from Django: pbkdf2_sha256$36000$salt$hash
        $salt = 'skNAlMc9aFzF';
        $iterations = 36000;
        $password = 'mypassword';
        $computedRaw = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
        $hashStr = "pbkdf2_sha256\${$iterations}\${$salt}\$" . base64_encode($computedRaw);

        $this->assertTrue(DjangoPasswordHasher::check('mypassword', $hashStr));
        $this->assertFalse(DjangoPasswordHasher::check('otherpassword', $hashStr));
    }

    public function test_django_token_generator_uid_encode_decode(): void
    {
        $userId = 42;
        $encoded = DjangoTokenGenerator::encodeUid($userId);
        $this->assertEquals($userId, DjangoTokenGenerator::decodeUid($encoded));

        $userIdLarge = 999999;
        $encodedLarge = DjangoTokenGenerator::encodeUid($userIdLarge);
        $this->assertEquals($userIdLarge, DjangoTokenGenerator::decodeUid($encodedLarge));
    }

    public function test_django_token_generator_make_and_check(): void
    {
        // Create mock user object
        $user = (object)[
            'id' => 123,
            'password' => 'pbkdf2_sha256$36000$saltsalt$fakehash',
            'last_login' => '2026-11-17 12:00:00',
        ];

        $secret = ')j61=+qm$@k*#q^d=bstvr*te5^5&v!jgyfj25(-pe=nvgb%cm';
        $generator = new DjangoTokenGenerator($secret);
        $token = $generator->makeToken($user);

        $this->assertMatchesRegularExpression('/^[0-9a-z]+-[0-9a-f]+$/', $token);
        $this->assertTrue($generator->checkToken($user, $token));

        // Invalid token
        $this->assertFalse($generator->checkToken($user, 'invalid-token'));

        // Altered user password should invalidate token
        $userModified = (object)[
            'id' => 123,
            'password' => 'pbkdf2_sha256$36000$saltsalt$changedhash',
            'last_login' => '2026-11-17 12:00:00',
        ];
        $this->assertFalse($generator->checkToken($userModified, $token));
    }
}
