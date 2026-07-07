<?php

namespace App\Http\Controllers;

use App\Models\AuthUser;
use App\Models\UserRoleAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserRoleAccountController extends Controller
{
    /**
     * Create a new user role account with profile and groups from a nested payload
     */
    public function store(Request $request)
    {
        // 1️⃣ Validate your exact 100% required nested JSON payload structure
        $request->validate([
            'role_id' => 'required|uuid|exists:access_role,id',
            'groups' => 'nullable|array',
            'groups.*' => 'uuid|exists:access_group,id',
            'profile.designation' => 'nullable|string|max:255',
            'profile.avatar_id' => 'nullable|uuid',
            'profile.user.email' => 'nullable|email|max:254|unique:auth_user,email',
            'profile.user.first_name' => 'required|string|max:150',
            'profile.user.last_name' => 'required|string|max:150',
            'profile.user.username' => 'required|string|max:150|unique:auth_user,username',
            'profile.user.password' => 'nullable|string|min:6',
        ]);

        // Unpack nested payload segments for safe array matching
        $profileData = $request->input('profile', []);
        $userData = $profileData['user'] ?? [];

        DB::beginTransaction();

        try {
            // 2️⃣ Hash password using your custom Django-compatible algorithm
            $plainPassword = $userData['password'] ?? null;
            $sendSetPasswordEmail = false;
            if (empty($plainPassword)) {
                $plainPassword = Str::random(12);
                $sendSetPasswordEmail = true;
            }
            $hashedPassword = $this->make_pbkdf2_sha256($plainPassword);

            // 3️⃣ Create Core User inside 'auth_user'
            $user = AuthUser::create([
                'username'     => $userData['username'],
                'email'        => $userData['email'] ?? '',
                'first_name'   => $userData['first_name'],
                'last_name'    => $userData['last_name'],
                'password'     => $hashedPassword,
                'is_active'    => true,
                'is_staff'     => false,
                'is_superuser' => false,
                'date_joined'  => now(),
            ]);

            // 4️⃣ Create Django profile connection row (mapping into common_profile table)
            DB::table('common_profile')->insert([
                'id'          => Str::uuid()->toString(),
                'user_id'     => $user->id,
                'phone'       => '', // Default fallback required by table structure layout
                'designation' => $profileData['designation'] ?? null,
                'avatar_id'   => $profileData['avatar_id'] ?? null,
                'created'     => now(),
                'updated'     => now(),
            ]);

            // 5️⃣ Automatically fetch account_id linked with the provided role_id
            $associatedRole = DB::table('access_role')->where('id', $request->role_id)->first();
            if (!$associatedRole) {
                throw new \Exception("The selected access role context was not found.");
            }

            // 6️⃣ Build UserRoleAccount intersection record
            // Note: Explicit 'id' mapping removed here since your Model uses the HasUuids trait!
            $ura = UserRoleAccount::create([
                'user_id'    => $user->id,
                'role_id'    => $request->role_id,
                'account_id' => $associatedRole->account_id,
            ]);

            // 7️⃣ Bind userroleaccount record context to multiple groups
            if (!empty($request->groups)) {
                try {
                    $ura->groups()->attach($request->groups);
                } catch (\Illuminate\Database\QueryException $e) {
                    // If it's a primary key unique key violation (due to Postgres sequence mismatch)
                    if ($e->getCode() == '23505' && str_contains($e->getMessage(), 'access_group_users_pkey')) {
                        // Self-heal: Sync sequence to the maximum ID currently in the table
                        $maxIdResult = DB::select("SELECT MAX(id) as max_id FROM access_group_users");
                        $maxId = $maxIdResult[0]->max_id ?? 0;
                        $nextVal = max($maxId, 1);
                        $isCalled = $maxId > 0 ? 'true' : 'false';

                        DB::statement("SELECT setval('access_group_users_id_seq', {$nextVal}, {$isCalled})");

                        // Re-attempt attach
                        $ura->groups()->attach($request->groups);
                    } else {
                        throw $e;
                    }
                }
            }

            DB::commit();

            // 8️⃣ Send password set link if password was not provided
            if ($sendSetPasswordEmail) {
                try {
                    \Illuminate\Support\Facades\Password::broker()->sendResetLink(['email' => $user->email]);
                } catch (\Exception $mailException) {
                    \Illuminate\Support\Facades\Log::warning("Could not send password set email to {$user->email}: " . $mailException->getMessage());
                }
            }

            return $this->show($user->id)->setStatusCode(201);

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
    private function make_pbkdf2_sha256(string $password, int $iterations = 36000, int $length = 32): string
    {
        $salt = base64_encode(random_bytes(12));
        $hash = base64_encode(hash_pbkdf2('sha256', $password, $salt, $iterations, $length, true));

        return "pbkdf2_sha256$$iterations$$salt$$hash";
    }

    /**
     * Delete a user along with all associated profiles, roles, observations, tokens, and group mappings
     */
    public function destroy(int|string $id)
    {
        // 1️⃣ Validate that the user exists in auth_user
        $userExists = DB::table('auth_user')->where('id', $id)->exists();

        if (!$userExists) {
            return response()->json([
                'error' => 'User account not found.'
            ], 404);
        }

        DB::beginTransaction();

        try {
            // 2️⃣ Find all UserRoleAccount IDs linked to this user
            $uraIds = DB::table('access_userroleaccount')
                ->where('user_id', $id)
                ->pluck('id')
                ->toArray();

            if (!empty($uraIds)) {
                // 3️⃣ Find all observations where this user is either the observer or observee
                $observationIds = DB::table('observations_observation')
                    ->whereIn('observee_id', $uraIds)
                    ->orWhereIn('observer_id', $uraIds)
                    ->pluck('id')
                    ->toArray();

                if (!empty($observationIds)) {
                    // a. Find related evidence blocks linked to these observations
                    $evidenceIds = DB::table('observations_evidence')
                        ->whereIn('observation_id', $observationIds)
                        ->pluck('id')
                        ->toArray();

                    if (!empty($evidenceIds)) {
                        // b. Clear lowest-level indicator ratings first
                        DB::table('observations_indicatorrating')
                            ->whereIn('evidence_id', $evidenceIds)
                            ->delete();

                        // c. Clear evidence attachment pivot relationships
                        DB::table('observations_evidence_attachments')
                            ->whereIn('evidence_id', $evidenceIds)
                            ->delete();

                        // d. Clear the evidence records
                        DB::table('observations_evidence')
                            ->whereIn('id', $evidenceIds)
                            ->delete();
                    }

                    // e. Finally, delete the parent observation tracking entries safely
                    DB::table('observations_observation')
                        ->whereIn('id', $observationIds)
                        ->delete();
                }

                // 4️⃣ Delete group associations
                DB::table('access_group_users')
                    ->whereIn('userroleaccount_id', $uraIds)
                    ->delete();
            }

            // 5️⃣ Delete from access_userroleaccount
            DB::table('access_userroleaccount')
                ->where('user_id', $id)
                ->delete();

            // 6️⃣ Delete from common_profile
            DB::table('common_profile')
                ->where('user_id', $id)
                ->delete();

            // 7️⃣ Clear active API login tokens (Fixes the new authtoken_token constraint violation)
            DB::table('authtoken_token')
                ->where('user_id', $id)
                ->delete();

            // 8️⃣ Delete core user record from auth_user
            DB::table('auth_user')
                ->where('id', $id)
                ->delete();

            DB::commit();

            return response()->json([
                'message' => 'User account and all deep relational token structures deleted successfully.',
                'deleted_user_id' => (int)$id
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to delete user safely.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of user role accounts with detailed profile and role information
     */
    public function index(Request $request)
    {
        try {
            // Optional pagination: default to 15 items per page
            $perPage = $request->query('per_page', 15);

            // Fetch records using explicit joins across table dependencies
            $query = DB::table('access_userroleaccount as ura')
                ->join('auth_user as u', 'ura.user_id', '=', 'u.id')
                ->leftJoin('common_profile as p', 'p.user_id', '=', 'u.id')
                ->join('access_role as r', 'ura.role_id', '=', 'r.id');

            // Filter by user__username if provided (exact match to mimic Django filter_fields)
            if ($request->filled('user__username')) {
                $query->where('u.username', $request->query('user__username'));
            }

            // Search by user__first_name, user__last_name, user__username, role__title if search query is provided
            if ($request->filled('search')) {
                $searchTerm = '%' . $request->query('search') . '%';
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('u.username', 'like', $searchTerm)
                      ->orWhere('u.first_name', 'like', $searchTerm)
                      ->orWhere('u.last_name', 'like', $searchTerm)
                      ->orWhere('r.title', 'like', $searchTerm);
                });
            }

            // Dynamic Ordering to match Django ordering_fields
            $ordering = $request->query('ordering');
            if ($ordering) {
                $descending = false;
                if (str_starts_with($ordering, '-')) {
                    $descending = true;
                    $ordering = substr($ordering, 1);
                }

                $columnMap = [
                    'user__first_name' => 'u.first_name',
                    'user__username' => 'u.username',
                    'role__title' => 'r.title',
                    'user__email' => 'u.email',
                ];

                if (isset($columnMap[$ordering])) {
                    $query->orderBy($columnMap[$ordering], $descending ? 'desc' : 'asc');
                } else {
                    $query->orderBy('u.date_joined', 'desc');
                }
            } else {
                $query->orderBy('u.date_joined', 'desc');
            }

            $accounts = $query->select([
                    'ura.id as user_role_account_id',
                    'ura.account_id',
                    'u.id as user_id',
                    'u.username',
                    'u.email',
                    'u.first_name',
                    'u.last_name',
                    'u.is_active',
                    'u.last_login',
                    'p.id as profile_id',
                    'p.created as profile_created',
                    'p.updated as profile_updated',
                    'p.designation',
                    'p.avatar_id',
                    'p.phone',
                    'p.dob',
                    'r.id as role_id',
                    'r.title as role_title',
                    'r.rank as role_rank'
                ])
                ->paginate($perPage);

            $uraIds = collect($accounts->items())->pluck('user_role_account_id')->toArray();

            $groupsMap = [];
            if (!empty($uraIds)) {
                $groupsMap = DB::table('access_group_users')
                    ->whereIn('userroleaccount_id', $uraIds)
                    ->select('userroleaccount_id', 'group_id')
                    ->get()
                    ->groupBy('userroleaccount_id')
                    ->map(function ($items) {
                        return $items->pluck('group_id')->toArray();
                    })
                    ->toArray();
            }

            // Restructure the collection dynamically to match Django DRF detail pattern
            $transformedData = collect($accounts->items())->map(function ($item) use ($groupsMap) {
                $groups = $groupsMap[$item->user_role_account_id] ?? [];

                return [
                    'id' => $item->user_role_account_id,
                    'profile' => [
                        'id' => $item->profile_id,
                        'avatar' => $item->avatar_id,
                        'user' => [
                            'username' => $item->username,
                            'first_name' => $item->first_name,
                            'last_name' => $item->last_name,
                            'full_name' => trim($item->first_name . ' ' . $item->last_name),
                            'email' => $item->email,
                            'last_login' => $item->last_login ? \Illuminate\Support\Carbon::parse($item->last_login)->toJSON() : null,
                        ],
                        'created' => $item->profile_created ? \Illuminate\Support\Carbon::parse($item->profile_created)->toJSON() : null,
                        'updated' => $item->profile_updated ? \Illuminate\Support\Carbon::parse($item->profile_updated)->toJSON() : null,
                        'phone' => $item->phone,
                        'designation' => $item->designation,
                        'dob' => $item->dob ?: null,
                    ],
                    'groups' => $groups,
                    'role_title' => $item->role_title,
                    'role_rank' => (int)$item->role_rank,
                    'role_id' => $item->role_id,
                    'account' => $item->account_id,
                ];
            })->toArray();

            return response()->json([
                'count'    => $accounts->total(),
                'next'     => $accounts->nextPageUrl(),
                'previous' => $accounts->previousPageUrl(),
                'results'  => $transformedData,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve user role accounts.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified user role account detail with profile, role, and groups
     * (Supports both User ID integer and Role Account UUID lookups)
     */
    public function show(int|string $id)
    {
        try {
            // 1️⃣ Fetch the core record with explicit joins
            $query = DB::table('access_userroleaccount as ura')
                ->join('auth_user as u', 'ura.user_id', '=', 'u.id')
                ->leftJoin('common_profile as p', 'p.user_id', '=', 'u.id')
                ->join('access_role as r', 'ura.role_id', '=', 'r.id')
                ->select([
                    'ura.id as user_role_account_id',
                    'ura.account_id',
                    'u.id as user_id',
                    'u.username',
                    'u.email',
                    'u.first_name',
                    'u.last_name',
                    'u.is_active',
                    'u.last_login',
                    'p.id as profile_id',
                    'p.created as profile_created',
                    'p.updated as profile_updated',
                    'p.designation',
                    'p.avatar_id',
                    'p.phone',
                    'p.dob',
                    'r.id as role_id',
                    'r.title as role_title',
                    'r.rank as role_rank'
                ]);

            // Dynamically check if the incoming parameter is a numeric user ID or UUID string
            if (is_numeric($id)) {
                $query->where('u.id', $id);
            } else {
                $query->where('ura.id', $id);
            }

            $account = $query->first();

            // 2️⃣ Return 404 if the record doesn't exist
            if (!$account) {
                return response()->json([
                    'detail' => 'Not found.'
                ], 404);
            }

            // 3️⃣ Fetch the associated group UUIDs for this specific account
            $groups = DB::table('access_group_users')
                ->where('userroleaccount_id', $account->user_role_account_id)
                ->pluck('group_id')
                ->toArray();

            // Map out the exact required nested JSON structure
            $transformedData = [
                'id' => $account->user_role_account_id,
                'profile' => [
                    'id' => $account->profile_id,
                    'avatar' => $account->avatar_id,
                    'user' => [
                        'username' => $account->username,
                        'first_name' => $account->first_name,
                        'last_name' => $account->last_name,
                        'full_name' => trim($account->first_name . ' ' . $account->last_name),
                        'email' => $account->email,
                        'last_login' => $account->last_login ? \Illuminate\Support\Carbon::parse($account->last_login)->toJSON() : null,
                    ],
                    'created' => $account->profile_created ? \Illuminate\Support\Carbon::parse($account->profile_created)->toJSON() : null,
                    'updated' => $account->profile_updated ? \Illuminate\Support\Carbon::parse($account->profile_updated)->toJSON() : null,
                    'phone' => $account->phone,
                    'designation' => $account->designation,
                    'dob' => $account->dob ?: null,
                ],
                'groups' => $groups,
                'role_title' => $account->role_title,
                'role_rank' => (int)$account->role_rank,
                'role_id' => $account->role_id,
                'account' => $account->account_id,
            ];

            return response()->json($transformedData, 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user role account details.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update an existing user role account by auth_user integer ID
     */
    public function update(Request $request, int|string $id)
    {
        // 1️⃣ Automatically strip the outer "data" key layer if present in input payloads
        if ($request->has('data')) {
            $request->merge($request->input('data'));
        }

        // 2️⃣ Find the UserRoleAccount context matching the auth_user integer ID
        $ura = UserRoleAccount::firstWhere('user_id', $id);

        if (!$ura) {
            return response()->json([
                'detail' => 'User Role Account not found.'
            ], 404);
        }

        // 3️⃣ Run input validation checks (exempting this user's current record, using 'sometimes' for partial updates)
        $request->validate([
            'role_id' => 'sometimes|required|uuid|exists:access_role,id',
            'groups' => 'sometimes|nullable|array',
            'groups.*' => 'uuid|exists:access_group,id',
            'profile.designation' => 'nullable|string|max:255',
            'profile.avatar_id' => 'nullable|uuid',
            'profile.phone' => 'nullable|string|max:50',
            'profile.user.email' => 'nullable|email|max:254|unique:auth_user,email,' . $id,
            'profile.user.username' => 'sometimes|required|string|max:150|unique:auth_user,username,' . $id,
            'profile.user.first_name' => 'sometimes|required|string|max:150',
            'profile.user.last_name' => 'sometimes|required|string|max:150',
            'profile.user.password' => 'nullable|string|min:6',
        ]);

        $profileData = $request->input('profile', []);
        $userData = $profileData['user'] ?? [];

        DB::beginTransaction();

        try {
            // 4️⃣ Update Core User Identity rows inside 'auth_user'
            $userUpdateData = [];
            if ($request->has('profile.user.username')) {
                $userUpdateData['username'] = $userData['username'];
            }
            if ($request->has('profile.user.email')) {
                $userUpdateData['email'] = $userData['email'] ?? '';
            }
            if ($request->has('profile.user.first_name')) {
                $userUpdateData['first_name'] = $userData['first_name'];
            }
            if ($request->has('profile.user.last_name')) {
                $userUpdateData['last_name'] = $userData['last_name'];
            }
            if ($request->has('profile.user.password') && !empty($userData['password'])) {
                $userUpdateData['password'] = $this->make_pbkdf2_sha256($userData['password']);
            }

            if (!empty($userUpdateData)) {
                DB::table('auth_user')->where('id', $id)->update($userUpdateData);
            }

            // 5️⃣ Update Custom Profile Meta fields inside 'common_profile' dynamically
            $profileUpdateData = [];
            if ($request->has('profile.designation')) {
                $profileUpdateData['designation'] = $profileData['designation'] ?? null;
            }
            if ($request->has('profile.avatar_id')) {
                $profileUpdateData['avatar_id'] = $profileData['avatar_id'] ?? null;
            }
            if ($request->has('profile.phone')) {
                $profileUpdateData['phone'] = $profileData['phone'] ?? '';
            }

            if (!empty($profileUpdateData)) {
                $profileUpdateData['updated'] = now();
                DB::table('common_profile')->where('user_id', $id)->update($profileUpdateData);
            }

            // 6️⃣ Re-verify and update current Access Role contextual link dynamically
            if ($request->has('role_id')) {
                $associatedRole = DB::table('access_role')->where('id', $request->role_id)->first();
                if (!$associatedRole) {
                    throw new \Exception("The selected access role context was not found.");
                }

                $ura->update([
                    'role_id'    => $request->role_id,
                    'account_id' => $associatedRole->account_id,
                ]);
            }

            // 7️⃣ Sync Group mappings pivots dynamically if provided
            if ($request->has('groups')) {
                try {
                    $ura->groups()->sync($request->groups ?? []);
                } catch (\Illuminate\Database\QueryException $e) {
                    // If it's a primary key unique key violation (due to Postgres sequence mismatch)
                    if ($e->getCode() == '23505' && str_contains($e->getMessage(), 'access_group_users_pkey')) {
                        // Self-heal: Sync sequence to the maximum ID currently in the table
                        $maxIdResult = DB::select("SELECT MAX(id) as max_id FROM access_group_users");
                        $maxId = $maxIdResult[0]->max_id ?? 0;
                        $nextVal = max($maxId, 1);
                        $isCalled = $maxId > 0 ? 'true' : 'false';

                        DB::statement("SELECT setval('access_group_users_id_seq', {$nextVal}, {$isCalled})");

                        // Re-attempt sync
                        $ura->groups()->sync($request->groups ?? []);
                    } else {
                        throw $e;
                    }
                }
            }

            DB::commit();

            // 8️⃣ Safely pass the user ID integer context to get pure structural JSON returned
            return $this->show($id);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user account safely.',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}
