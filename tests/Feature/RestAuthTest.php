<?php

namespace Tests\Feature;

use App\Models\AuthUser;
use App\Services\DjangoPasswordHasher;
use App\Services\DjangoTokenGenerator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RestAuthTest extends TestCase
{
    private ?int $testUserId = null;
    private ?string $testTokenKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'sensei_new',
            'database.connections.pgsql.username' => 'postgres',
            'database.connections.pgsql.password' => 'postgres',
        ]);
        DB::purge('pgsql');

        // Create a dedicated test user for auth tests
        $password = DjangoPasswordHasher::make('CorrectPassword123!');
        $user = AuthUser::create([
            'username'     => 'test_auth_user_' . uniqid(),
            'email'        => 'test_auth_' . uniqid() . '@example.com',
            'first_name'   => 'Test',
            'last_name'    => 'Author',
            'password'     => $password,
            'is_active'    => true,
            'is_staff'     => false,
            'is_superuser' => false,
            'date_joined'  => now(),
        ]);
        $this->testUserId = $user->id;

        $this->testTokenKey = bin2hex(random_bytes(20));
        DB::table('authtoken_token')->insert([
            'key'     => $this->testTokenKey,
            'user_id' => $user->id,
            'created' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->testUserId) {
            DB::table('authtoken_token')->where('user_id', $this->testUserId)->delete();
            DB::table('account_emailaddress')->where('user_id', $this->testUserId)->delete();
            DB::table('common_profile')->where('user_id', $this->testUserId)->delete();
            DB::table('access_userroleaccount')->where('user_id', $this->testUserId)->delete();
            DB::table('auth_user')->where('id', $this->testUserId)->delete();
        }

        // Clean up any other test users created during registration tests
        DB::table('auth_user')->where('username', 'like', 'registered_user_%')->delete();

        parent::tearDown();
    }

    public function test_login_validation_missing_fields(): void
    {
        // Missing password
        $response = $this->postJson('/api/rest-auth/login/', [
            'username' => 'someuser',
        ]);
        $response->assertStatus(400);
        $response->assertJsonStructure(['password']);

        // Missing username and email
        $response = $this->postJson('/api/rest-auth/login/', [
            'password' => 'somepass',
        ]);
        $response->assertStatus(400);
        $response->assertJsonStructure(['non_field_errors']);
    }

    public function test_login_invalid_credentials(): void
    {
        $user = AuthUser::find($this->testUserId);

        $response = $this->postJson('/api/rest-auth/login/', [
            'username' => $user->username,
            'password' => 'WrongPassword',
        ]);
        $response->assertStatus(400);
        $response->assertJson(['non_field_errors' => ['Unable to log in with provided credentials.']]);
    }

    public function test_login_success_with_username_and_email(): void
    {
        $user = AuthUser::find($this->testUserId);

        // Login with username
        $response = $this->postJson('/api/rest-auth/login/', [
            'username' => $user->username,
            'password' => 'CorrectPassword123!',
        ]);
        $response->assertStatus(200);
        $response->assertJsonStructure(['key']);
        $this->assertEquals(40, strlen($response->json('key')));

        // Login with email
        $responseEmail = $this->postJson('/api/rest-auth/login/', [
            'email'    => $user->email,
            'password' => 'CorrectPassword123!',
        ]);
        $responseEmail->assertStatus(200);
        $responseEmail->assertJsonStructure(['key']);
    }

    public function test_login_returns_user_role_account(): void
    {
        $user = AuthUser::find($this->testUserId);

        // Check if an existing role and account exist
        $role = DB::table('access_role')->first();
        $account = DB::table('access_account')->first();

        if ($role && $account) {
            $uraId = \Illuminate\Support\Str::uuid()->toString();
            DB::table('access_userroleaccount')->insert([
                'id'         => $uraId,
                'user_id'    => $user->id,
                'role_id'    => $role->id,
                'account_id' => $account->id,
                'created'    => now(),
                'updated'    => now(),
            ]);

            $profileId = \Illuminate\Support\Str::uuid()->toString();
            DB::table('common_profile')->insert([
                'id'          => $profileId,
                'user_id'     => $user->id,
                'phone'       => '1234567890',
                'designation' => 'Staff',
                'created'     => now(),
                'updated'     => now(),
            ]);

            $response = $this->postJson('/api/rest-auth/login/', [
                'username' => $user->username,
                'password' => 'CorrectPassword123!',
            ]);

            $response->assertStatus(200);
            $response->assertJsonStructure([
                'key',
                'user_role_account' => [
                    'role',
                    'role_id',
                    'rank',
                    'account',
                    'account_id',
                    'ura_id',
                    'profile_id',
                ],
            ]);
            $this->assertEquals($uraId, $response->json('user_role_account.ura_id'));
            $this->assertEquals($profileId, $response->json('user_role_account.profile_id'));
        }
    }

    public function test_user_details_requires_auth(): void
    {
        $response = $this->getJson('/api/rest-auth/user/');
        $response->assertStatus(401);
        $response->assertJson(['detail' => 'Authentication credentials were not provided.']);
    }

    public function test_user_details_authenticated(): void
    {
        $user = AuthUser::find($this->testUserId);

        $response = $this->withHeaders([
            'Authorization' => "Token {$this->testTokenKey}",
        ])->getJson('/api/rest-auth/user/');

        $response->assertStatus(200);
        $response->assertJson([
            'pk'         => $user->id,
            'username'   => $user->username,
            'email'      => $user->email,
            'first_name' => 'Test',
            'last_name'  => 'Author',
        ]);
    }

    public function test_user_details_update_put_and_patch(): void
    {
        $user = AuthUser::find($this->testUserId);

        // PATCH partial update
        $patchResponse = $this->withHeaders([
            'Authorization' => "Token {$this->testTokenKey}",
        ])->patchJson('/api/rest-auth/user/', [
            'first_name' => 'UpdatedFirst',
        ]);
        $patchResponse->assertStatus(200);
        $patchResponse->assertJson([
            'first_name' => 'UpdatedFirst',
            'last_name'  => 'Author',
        ]);

        // PUT full update
        $putResponse = $this->withHeaders([
            'Authorization' => "Token {$this->testTokenKey}",
        ])->putJson('/api/rest-auth/user/', [
            'username'   => $user->username,
            'email'      => 'newemail_' . uniqid() . '@example.com',
            'first_name' => 'PutFirst',
            'last_name'  => 'PutLast',
        ]);
        $putResponse->assertStatus(200);
        $putResponse->assertJson([
            'first_name' => 'PutFirst',
            'last_name'  => 'PutLast',
        ]);
    }

    public function test_password_change(): void
    {
        // Wrong old password
        $failResponse = $this->withHeaders([
            'Authorization' => "Token {$this->testTokenKey}",
        ])->postJson('/api/rest-auth/password/change/', [
            'old_password'  => 'WrongOldPassword',
            'new_password1' => 'BrandNewPass123!',
            'new_password2' => 'BrandNewPass123!',
        ]);
        $failResponse->assertStatus(400);
        $failResponse->assertJson(['old_password' => ['Invalid password.']]);

        // Mismatched new passwords
        $mismatchResponse = $this->withHeaders([
            'Authorization' => "Token {$this->testTokenKey}",
        ])->postJson('/api/rest-auth/password/change/', [
            'old_password'  => 'CorrectPassword123!',
            'new_password1' => 'BrandNewPass123!',
            'new_password2' => 'DifferentPass123!',
        ]);
        $mismatchResponse->assertStatus(400);
        $mismatchResponse->assertJson(['new_password2' => ["The two password fields didn't match."]]);

        // Successful password change
        $successResponse = $this->withHeaders([
            'Authorization' => "Token {$this->testTokenKey}",
        ])->postJson('/api/rest-auth/password/change/', [
            'old_password'  => 'CorrectPassword123!',
            'new_password1' => 'BrandNewPass123!',
            'new_password2' => 'BrandNewPass123!',
        ]);
        $successResponse->assertStatus(200);
        $successResponse->assertJson(['detail' => 'New password has been saved.']);

        // Verify can login with new password
        $user = AuthUser::find($this->testUserId);
        $loginResponse = $this->postJson('/api/rest-auth/login/', [
            'username' => $user->username,
            'password' => 'BrandNewPass123!',
        ]);
        $loginResponse->assertStatus(200);
    }

    public function test_password_reset_and_confirm(): void
    {
        $user = AuthUser::find($this->testUserId);

        // Request reset
        $resetResponse = $this->postJson('/api/rest-auth/password/reset/', [
            'email' => $user->email,
        ]);
        $resetResponse->assertStatus(200);
        $resetResponse->assertJson(['detail' => 'Password reset e-mail has been sent.']);

        // Generate token and uid for confirmation
        $tokenGen = new DjangoTokenGenerator();
        $token = $tokenGen->makeToken($user);
        $uid = DjangoTokenGenerator::encodeUid($user->id);

        // Confirm reset with valid token
        $confirmResponse = $this->postJson('/api/rest-auth/password/reset/confirm/', [
            'uid'           => $uid,
            'token'         => $token,
            'new_password1' => 'ResetPassword123!',
            'new_password2' => 'ResetPassword123!',
        ]);
        $confirmResponse->assertStatus(200);
        $confirmResponse->assertJson(['detail' => 'Password has been reset with the new password.']);

        // Verify can login with reset password
        $loginResponse = $this->postJson('/api/rest-auth/login/', [
            'username' => $user->username,
            'password' => 'ResetPassword123!',
        ]);
        $loginResponse->assertStatus(200);

        // Reusing the token should fail
        $reuseResponse = $this->postJson('/api/rest-auth/password/reset/confirm/', [
            'uid'           => $uid,
            'token'         => $token,
            'new_password1' => 'AnotherPassword123!',
            'new_password2' => 'AnotherPassword123!',
        ]);
        $reuseResponse->assertStatus(400);

        // Test alias route: /api/rest-auth/password/reset/{uidb64}/{token}/
        $userUpdated = AuthUser::find($this->testUserId);
        $token2 = $tokenGen->makeToken($userUpdated);
        $aliasResponse = $this->postJson("/api/rest-auth/password/reset/{$uid}/{$token2}/", [
            'new_password1' => 'AliasResetPassword123!',
            'new_password2' => 'AliasResetPassword123!',
        ]);
        $aliasResponse->assertStatus(200);
        $aliasResponse->assertJson(['detail' => 'Password has been reset with the new password.']);
    }

    public function test_logout(): void
    {
        // Test GET logout
        $getResponse = $this->withHeaders([
            'Authorization' => "Token {$this->testTokenKey}",
        ])->getJson('/api/rest-auth/logout/');
        $getResponse->assertStatus(200);
        $getResponse->assertJson(['detail' => 'Successfully logged out.']);

        // Re-insert token for POST logout test
        DB::table('authtoken_token')->insert([
            'key'     => $this->testTokenKey,
            'user_id' => $this->testUserId,
            'created' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Token {$this->testTokenKey}",
        ])->postJson('/api/rest-auth/logout/');

        $response->assertStatus(200);
        $response->assertJson(['detail' => 'Successfully logged out.']);

        // Verify token removed from DB
        $this->assertDatabaseMissing('authtoken_token', [
            'key' => $this->testTokenKey,
        ]);
    }

    public function test_registration(): void
    {
        $regUsername = 'registered_user_' . uniqid();
        $regEmail = 'reg_' . uniqid() . '@example.com';

        $response = $this->postJson('/api/rest-auth/registration/', [
            'username'  => $regUsername,
            'email'     => $regEmail,
            'password1' => 'RegisterPassword123!',
            'password2' => 'RegisterPassword123!',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['key']);
        $tokenKey = $response->json('key');
        $this->assertEquals(40, strlen($tokenKey));

        // Verify user exists in auth_user
        $this->assertDatabaseHas('auth_user', [
            'username' => $regUsername,
            'email'    => $regEmail,
        ]);

        // Clean up registered user
        $regUser = DB::table('auth_user')->where('username', $regUsername)->first();
        if ($regUser) {
            DB::table('authtoken_token')->where('user_id', $regUser->id)->delete();
            DB::table('account_emailaddress')->where('user_id', $regUser->id)->delete();
            DB::table('auth_user')->where('id', $regUser->id)->delete();
        }
    }

    public function test_verify_email(): void
    {
        // 404 for unknown key
        $response = $this->postJson('/api/rest-auth/registration/verify-email/', [
            'key' => 'nonexistent_key_123',
        ]);
        $response->assertStatus(404);
        $response->assertJson(['detail' => 'Not found.']);
    }
}
