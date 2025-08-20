<?php

namespace App\Http\Controllers;

use App\Models\AuthUser;
use App\Models\UserRoleAccount;
use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserRoleAccountController extends Controller
{

    /**
     * Create a new user role account
     */
    public function store(Request $request)
    {
        // $request->validate([
        //     'first_name'  => 'required|string|max:30',
        //     'last_name'   => 'required|string|max:30',
        //     'username'    => 'required|string|max:150|unique:auth_user,username',
        //     'email'       => 'required|email|unique:auth_user,email',
        //     'role_id'     => 'required|uuid|exists:access_role,id',
        //     'account_id'  => 'required|uuid|exists:access_account,id',
        //     // 'groups'      => 'nullable|array',
        //     // 'groups.*'    => 'uuid|exists:access_group,id',
        // ]);

        DB::beginTransaction();

        try {
            // 1️⃣ Generate random password
            $plainPassword = Str::random(12);
            $hashedPassword = $this->make_pbkdf2_sha256($plainPassword);
            // 2️⃣ Create User
            $user = AuthUser::create([
                'username'     => $request->username,
                'email'        => $request->email,
                'first_name'   => $request->first_name,
                'last_name'    => $request->last_name,
                'password'     => $hashedPassword,
                'is_active'    => true,
                'is_staff'     => false,
                'is_superuser' => false,
                'date_joined'  => now(),
            ]);



            // 3️⃣ Create UserRoleAccount
            $ura = UserRoleAccount::create([
                'id'         => Str::uuid(),
                'user_id'    => $user->id,
                'role_id'    => $request->role_id,
                'account_id' => $request->account_id,
            ]);

            // 4️⃣ Assign Groups if provided
            if ($request->has('groups') && is_array($request->groups)) {
                foreach ($request->groups as $groupId) {
                    DB::table('access_group_users')->insert([
                        'userroleaccount_id'  => $ura->id,   // newly created user id
                        'group_id' => $groupId,
                    ]);
                }
            }

            DB::commit();
            return response()->json([ 'user_role_account' => $ura, 'password' => $plainPassword, // send generated password
             ]
            , 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Custom Django-compatible PBKDF2-SHA256 hash
     */
    private function make_pbkdf2_sha256($password, $iterations = 36000, $length = 32)
    {
        $salt = base64_encode(random_bytes(12));
        $hash = base64_encode(hash_pbkdf2('sha256', $password, $salt, $iterations, $length, true));

        return "pbkdf2_sha256$$iterations$$salt$$hash";
    }
}
