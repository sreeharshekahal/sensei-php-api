<?php

namespace App\Http\Controllers;

use App\Models\AuthUser;
use App\Services\DjangoPasswordHasher;
use App\Services\DjangoTokenGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * POST /rest-auth/login/
     *
     * Authenticate user, return token + user_role_account data.
     * Matches Django rest-auth TokenSerializer with custom user_role_account field.
     */
    public function login(Request $request)
    {
        $username = $request->input('username');
        $email = $request->input('email');
        $password = $request->input('password');

        if (empty($password)) {
            return response()->json([
                'password' => ['This field is required.'],
            ], 400);
        }

        if (!$username && !$email) {
            return response()->json([
                'non_field_errors' => ['Must include either "username" or "email".'],
            ], 400);
        }

        // Look up user by username or email
        $user = null;
        if ($username) {
            $user = AuthUser::where('username', $username)->first();
        }
        if (!$user && $email) {
            $user = AuthUser::where('email', $email)->first();
        }

        if (!$user) {
            return response()->json([
                'non_field_errors' => ['Unable to log in with provided credentials.'],
            ], 400);
        }

        // Verify password
        if (!DjangoPasswordHasher::check($password, $user->password)) {
            return response()->json([
                'non_field_errors' => ['Unable to log in with provided credentials.'],
            ], 400);
        }

        // Check is_active
        if (!$user->is_active) {
            return response()->json([
                'non_field_errors' => ['User account is disabled.'],
            ], 400);
        }

        // Create or retrieve token from authtoken_token
        $existingToken = DB::table('authtoken_token')->where('user_id', $user->id)->first();
        if ($existingToken) {
            $tokenKey = $existingToken->key;
        } else {
            // Generate 40-char hex token (matches Django: binascii.hexlify(os.urandom(20)))
            $tokenKey = bin2hex(random_bytes(20));
            DB::table('authtoken_token')->insert([
                'key'     => $tokenKey,
                'user_id' => $user->id,
                'created' => now(),
            ]);
        }

        // Update last_login
        DB::table('auth_user')->where('id', $user->id)->update([
            'last_login' => now(),
        ]);

        // Build user_role_account response
        $uraData = $this->buildUserRoleAccountData($user->id);

        $response = ['key' => $tokenKey];
        if ($uraData) {
            $response['user_role_account'] = $uraData;
        }

        return response()->json($response, 200);
    }

    /**
     * GET|POST /rest-auth/logout/
     *
     * Delete user's auth token. Always returns success.
     */
    public function logout(Request $request)
    {
        $authHeader = $request->header('Authorization');
        if ($authHeader && preg_match('/^(Token|Bearer)\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[2];
            DB::table('authtoken_token')->where('key', $token)->delete();
        }

        return response()->json([
            'detail' => 'Successfully logged out.',
        ], 200);
    }

    /**
     * GET /rest-auth/user/
     *
     * Return current user details matching Django UserDetailsSerializer.
     */
    public function user(Request $request)
    {
        $user = Auth::user();

        return response()->json([
            'pk'         => $user->id,
            'username'   => $user->username,
            'email'      => $user->email,
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
        ], 200);
    }

    /**
     * PUT|PATCH /rest-auth/user/
     *
     * Update current user details.
     */
    public function updateUser(Request $request)
    {
        $user = Auth::user();

        $rules = [];
        if ($request->isMethod('put')) {
            // Full update — all fields required
            $rules = [
                'username'   => 'required|string|max:150|unique:auth_user,username,' . $user->id,
                'email'      => 'required|email|max:254',
                'first_name' => 'required|string|max:150',
                'last_name'  => 'required|string|max:150',
            ];
        } else {
            // Partial update — fields optional
            $rules = [
                'username'   => 'sometimes|string|max:150|unique:auth_user,username,' . $user->id,
                'email'      => 'sometimes|email|max:254',
                'first_name' => 'sometimes|string|max:150',
                'last_name'  => 'sometimes|string|max:150',
            ];
        }

        $validator = Validator::make($request->all(), $rules, [
            'username.required'   => 'This field is required.',
            'username.unique'     => 'A user with that username already exists.',
            'email.required'      => 'This field is required.',
            'first_name.required' => 'This field is required.',
            'last_name.required'  => 'This field is required.',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $validated = $validator->validated();

        $updateData = [];
        foreach (['username', 'email', 'first_name', 'last_name'] as $field) {
            if (array_key_exists($field, $validated)) {
                $updateData[$field] = $validated[$field];
            }
        }

        if (!empty($updateData)) {
            DB::table('auth_user')->where('id', $user->id)->update($updateData);
            $user = AuthUser::find($user->id); // Refresh
        }

        return response()->json([
            'pk'         => $user->id,
            'username'   => $user->username,
            'email'      => $user->email,
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
        ], 200);
    }

    /**
     * POST /rest-auth/password/change/
     *
     * Change password for authenticated user.
     * OLD_PASSWORD_FIELD_ENABLED = True in Django settings.
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'old_password'  => 'required|string',
            'new_password1' => 'required|string|min:1',
            'new_password2' => 'required|string',
        ], [
            'old_password.required'  => 'This field is required.',
            'new_password1.required' => 'This field is required.',
            'new_password2.required' => 'This field is required.',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        if ($request->new_password1 !== $request->new_password2) {
            return response()->json([
                'new_password2' => ["The two password fields didn't match."],
            ], 400);
        }

        $user = Auth::user();

        // Verify old password
        if (!DjangoPasswordHasher::check($request->old_password, $user->password)) {
            return response()->json([
                'old_password' => ['Invalid password.'],
            ], 400);
        }

        // Hash and save new password
        $newHash = DjangoPasswordHasher::make($request->new_password1);
        DB::table('auth_user')->where('id', $user->id)->update([
            'password' => $newHash,
        ]);

        return response()->json([
            'detail' => 'New password has been saved.',
        ], 200);
    }

    /**
     * POST /rest-auth/password/reset/
     *
     * Request password reset email.
     * Always returns success (don't reveal if email exists).
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ], [
            'email.required' => 'This field is required.',
            'email.email'    => 'Enter a valid email address.',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $user = AuthUser::where('email', $request->email)
            ->where('is_active', true)
            ->first();

        if ($user) {
            try {
                $tokenGen = new DjangoTokenGenerator();
                $token = $tokenGen->makeToken($user);
                $uid = DjangoTokenGenerator::encodeUid($user->id);

                // Get the account app_url for the reset link
                $ura = DB::table('access_userroleaccount')
                    ->where('user_id', $user->id)
                    ->first();

                $appUrl = '';
                if ($ura) {
                    $account = DB::table('access_account')
                        ->where('id', $ura->account_id)
                        ->first();
                    if ($account) {
                        $appUrl = $account->app_url ?? '';
                    }
                }

                $resetLink = rtrim($appUrl, '/') . "/user/reset-password/{$uid}/{$token}";

                // Send password reset email
                Mail::raw(
                    "You're receiving this email because you requested a password reset.\n\n" .
                    "Please go to the following page and choose a new password:\n\n" .
                    "{$resetLink}\n\n" .
                    "If you didn't request this, please ignore this email.\n",
                    function ($message) use ($user) {
                        $message->to($user->email)
                            ->subject('Password reset');
                    }
                );
            } catch (\Exception $e) {
                Log::warning("Password reset email failed for {$user->email}: " . $e->getMessage());
            }
        }

        // Always return success (matches Django behavior)
        return response()->json([
            'detail' => 'Password reset e-mail has been sent.',
        ], 200);
    }

    /**
     * POST /rest-auth/password/reset/confirm/
     * POST /rest-auth/password/reset/{uidb64}/{token}/
     *
     * Confirm password reset with uid/token and set new password.
     */
    public function resetConfirm(Request $request, ?string $uidb64 = null, ?string $token = null)
    {
        // Accept uid/token from URL params or request body
        $uid = $uidb64 ?? $request->input('uid');
        $tokenValue = $token ?? $request->input('token');
        $newPassword1 = $request->input('new_password1');
        $newPassword2 = $request->input('new_password2');

        // Validate
        if (!$uid || !$tokenValue) {
            return response()->json([
                'non_field_errors' => ['Invalid token.'],
            ], 400);
        }

        if (!$newPassword1 || !$newPassword2) {
            return response()->json([
                'new_password2' => ['This field is required.'],
            ], 400);
        }

        if ($newPassword1 !== $newPassword2) {
            return response()->json([
                'new_password2' => ["The two password fields didn't match."],
            ], 400);
        }

        // Decode uid
        $userId = DjangoTokenGenerator::decodeUid($uid);
        if (!$userId) {
            return response()->json([
                'uid' => ['Invalid value.'],
            ], 400);
        }

        $user = AuthUser::find($userId);
        if (!$user) {
            return response()->json([
                'uid' => ['Invalid value.'],
            ], 400);
        }

        // Validate token
        $tokenGen = new DjangoTokenGenerator();
        if (!$tokenGen->checkToken($user, $tokenValue)) {
            return response()->json([
                'token' => ['Invalid value.'],
            ], 400);
        }

        // Hash and save new password
        $newHash = DjangoPasswordHasher::make($newPassword1);
        DB::table('auth_user')->where('id', $user->id)->update([
            'password' => $newHash,
        ]);

        return response()->json([
            'detail' => 'Password has been reset with the new password.',
        ], 200);
    }

    /**
     * POST /rest-auth/registration/
     *
     * Register a new user. Returns token (same format as login).
     * Does NOT create UserRoleAccount or profile — those are created
     * separately via /user-role-accounts endpoint.
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username'  => 'required|string|max:150|unique:auth_user,username',
            'email'     => 'required|email|max:254|unique:auth_user,email',
            'password1' => 'required|string|min:1',
            'password2' => 'required|string',
        ], [
            'username.required'  => 'This field is required.',
            'username.unique'    => 'A user with that username already exists.',
            'email.required'     => 'This field is required.',
            'email.unique'       => 'A user is already registered with this e-mail address.',
            'password1.required' => 'This field is required.',
            'password2.required' => 'This field is required.',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        if ($request->password1 !== $request->password2) {
            return response()->json([
                'password2' => ["The two password fields didn't match."],
            ], 400);
        }

        DB::beginTransaction();

        try {
            // Create auth_user
            $hashedPassword = DjangoPasswordHasher::make($request->password1);
            $user = AuthUser::create([
                'username'     => $request->username,
                'email'        => $request->email,
                'first_name'   => '',
                'last_name'    => '',
                'password'     => $hashedPassword,
                'is_active'    => true,
                'is_staff'     => false,
                'is_superuser' => false,
                'date_joined'  => now(),
            ]);

            // Create account_emailaddress row (allauth)
            DB::table('account_emailaddress')->insert([
                'email'      => $request->email,
                'verified'   => false,
                'primary'    => true,
                'user_id'    => $user->id,
            ]);

            // Generate 40-char hex token
            $tokenKey = bin2hex(random_bytes(20));
            DB::table('authtoken_token')->insert([
                'key'     => $tokenKey,
                'user_id' => $user->id,
                'created' => now(),
            ]);

            DB::commit();

            // Build response (same as login)
            $response = ['key' => $tokenKey];
            $uraData = $this->buildUserRoleAccountData($user->id);
            if ($uraData) {
                $response['user_role_account'] = $uraData;
            }

            return response()->json($response, 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'detail' => 'Registration failed.',
                'error'  => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * POST /rest-auth/registration/verify-email/
     *
     * Verify email confirmation key from account_emailconfirmation.
     */
    public function verifyEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'key' => 'required|string',
        ], [
            'key.required' => 'This field is required.',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $confirmation = DB::table('account_emailconfirmation')
            ->where('key', $request->key)
            ->first();

        if (!$confirmation) {
            return response()->json([
                'detail' => 'Not found.',
            ], 404);
        }

        // Set the email address as verified
        DB::table('account_emailaddress')
            ->where('id', $confirmation->email_address_id)
            ->update(['verified' => true]);

        return response()->json([
            'detail' => 'ok',
        ], 200);
    }

    /**
     * Build user_role_account data for token response.
     * Matches Django TokenSerializer.user_role_account field.
     */
    private function buildUserRoleAccountData(int $userId): ?array
    {
        $ura = DB::table('access_userroleaccount as ura')
            ->join('access_role as r', 'ura.role_id', '=', 'r.id')
            ->join('access_account as a', 'ura.account_id', '=', 'a.id')
            ->where('ura.user_id', $userId)
            ->select([
                'r.title as role',
                'r.id as role_id',
                'r.rank as rank',
                'a.title as account',
                'a.id as account_id',
                'ura.id as ura_id',
            ])
            ->first();

        if (!$ura) {
            return null;
        }

        // Get profile_id
        $profile = DB::table('common_profile')
            ->where('user_id', $userId)
            ->select('id')
            ->first();

        return [
            'role'       => $ura->role,
            'role_id'    => $ura->role_id,
            'rank'       => (int)$ura->rank,
            'account'    => $ura->account,
            'account_id' => $ura->account_id,
            'ura_id'     => $ura->ura_id,
            'profile_id' => $profile?->id,
        ];
    }
}
