<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AccessRoleController extends Controller
{
    /**
     * List roles with search, ordering, and limit/offset pagination (GET /roles/).
     */
    public function index(Request $request)
    {
        try {
            $usersCountSub = DB::table('access_userroleaccount')
                ->select('role_id', DB::raw('COUNT(*) as users_count'))
                ->groupBy('role_id');

            $query = DB::table('access_role as r')
                ->leftJoinSub($usersCountSub, 'uc', 'r.id', '=', 'uc.role_id');

            if ($request->filled('search')) {
                $search = $request->query('search');
                $query->where('r.title', 'ilike', "%{$search}%");
            }

            $ordering = $request->query('ordering', 'title');
            $orderDirection = str_starts_with($ordering, '-') ? 'desc' : 'asc';
            $orderField = ltrim($ordering, '-');

            if ($orderField === 'users_count') {
                $query->orderByRaw('COALESCE(uc.users_count, 0) ' . $orderDirection);
            } elseif (in_array($orderField, ['title', 'updated', 'rank', 'created'])) {
                $query->orderBy("r.{$orderField}", $orderDirection);
            } else {
                $query->orderBy('r.title', 'asc');
            }

            $total = (clone $query)->count('r.id');

            $limit = max(1, (int) $request->query('limit', 10));
            $offset = max(0, (int) $request->query('offset', 0));
            if ($request->filled('page') && !$request->filled('offset')) {
                $page = max(1, (int) $request->query('page'));
                $offset = ($page - 1) * $limit;
            }

            $roles = $query->select([
                    'r.id',
                    'r.title',
                    'r.description',
                    'r.rank',
                    'r.account_id',
                    'r.created',
                    'r.updated',
                    DB::raw('COALESCE(uc.users_count, 0) as users_count'),
                ])
                ->offset($offset)
                ->limit($limit)
                ->get();

            $next = ($offset + $limit < $total)
                ? $request->fullUrlWithQuery(['offset' => $offset + $limit, 'limit' => $limit])
                : null;
            $previous = ($offset > 0)
                ? $request->fullUrlWithQuery(['offset' => max(0, $offset - $limit), 'limit' => $limit])
                : null;

            return response()->json([
                'count'    => $total,
                'next'     => $next,
                'previous' => $previous,
                'results'  => $roles->map(fn ($r) => $this->formatRole($r))->values(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve roles.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a role (POST /roles/).
     */
    public function store(Request $request)
    {
        $accountId = $request->input('account_id', $request->input('account'));
        if (!$accountId && $request->user()) {
            $accountId = DB::table('access_userroleaccount')
                ->where('user_id', $request->user()->id)
                ->value('account_id');
        }

        $payload = array_merge($request->all(), ['account_id' => $accountId]);

        $validator = Validator::make($payload, [
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'rank'        => 'nullable|integer',
            'account_id'  => 'required|uuid|exists:access_account,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 400);
        }

        try {
            $id = (string) Str::uuid();
            $now = now();

            DB::table('access_role')->insert([
                'id'          => $id,
                'title'       => $request->input('title'),
                'description' => $request->input('description') ?? '',
                'rank'        => (int) $request->input('rank', 0),
                'account_id'  => $accountId,
                'created'     => $now,
                'updated'     => $now,
            ]);

            $role = $this->findRoleWithCount($id);

            return response()->json($this->formatRole($role), 201);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to create role.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retrieve a single role (GET /roles/{id}/).
     */
    public function show(string $id)
    {
        $id = trim($id);
        if (!Str::isUuid($id)) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        try {
            $role = $this->findRoleWithCount($id);

            if (!$role) {
                return response()->json(['detail' => 'Not found.'], 404);
            }

            return response()->json($this->formatRole($role), 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve role.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update or partially update a role (PUT/PATCH /roles/{id}/).
     */
    public function update(Request $request, string $id)
    {
        $id = trim($id);
        if (!Str::isUuid($id)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Role ID format',
            ], 400);
        }

        $existing = DB::table('access_role')->where('id', $id)->first();
        if (!$existing) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        $payload = $request->all();
        if (!$request->has('account_id') && $request->has('account')) {
            $payload['account_id'] = $request->input('account');
        }

        $isPut = $request->isMethod('put');
        $validator = Validator::make($payload, [
            'title'       => $isPut ? 'required|string|max:255' : 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string',
            'rank'        => 'sometimes|integer',
            'account_id'  => 'sometimes|required|uuid|exists:access_account,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 400);
        }

        try {
            $updateData = ['updated' => now()];

            if (array_key_exists('title', $payload)) {
                $updateData['title'] = $payload['title'];
            }
            if (array_key_exists('description', $payload)) {
                $updateData['description'] = $payload['description'] ?? '';
            }
            if (array_key_exists('rank', $payload)) {
                $updateData['rank'] = (int) $payload['rank'];
            }
            if (array_key_exists('account_id', $payload)) {
                $updateData['account_id'] = $payload['account_id'];
            }

            DB::table('access_role')->where('id', $id)->update($updateData);

            $role = $this->findRoleWithCount($id);

            return response()->json($this->formatRole($role), 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to update role.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a role (DELETE /roles/{id}/).
     */
    public function destroy(string $id)
    {
        $id = trim($id);
        if (!Str::isUuid($id)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Role ID format',
            ], 400);
        }

        $existing = DB::table('access_role')->where('id', $id)->first();
        if (!$existing) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        try {
            DB::table('access_role')->where('id', $id)->delete();

            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to delete role.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk-delete roles (POST /roles/bulk_delete/).
     */
    public function bulkDelete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'roles'   => 'required|array|min:1',
            'roles.*' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 400);
        }

        try {
            DB::table('access_role')->whereIn('id', $request->input('roles', []))->delete();

            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to bulk-delete roles.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    private function findRoleWithCount(string $id): ?object
    {
        $usersCountSub = DB::table('access_userroleaccount')
            ->select('role_id', DB::raw('COUNT(*) as users_count'))
            ->where('role_id', $id)
            ->groupBy('role_id');

        return DB::table('access_role as r')
            ->leftJoinSub($usersCountSub, 'uc', 'r.id', '=', 'uc.role_id')
            ->where('r.id', $id)
            ->select([
                'r.id',
                'r.title',
                'r.description',
                'r.rank',
                'r.account_id',
                'r.created',
                'r.updated',
                DB::raw('COALESCE(uc.users_count, 0) as users_count'),
            ])
            ->first();
    }

    private function formatRole(object $role): array
    {
        return [
            'id'          => $role->id,
            'title'       => $role->title,
            'description' => $role->description ?? '',
            'rank'        => (int) $role->rank,
            'account'     => $role->account_id,
            'users_count' => (int) ($role->users_count ?? 0),
            'created'     => $role->created ? Carbon::parse($role->created)->toJSON() : null,
            'updated'     => $role->updated ? Carbon::parse($role->updated)->toJSON() : null,
        ];
    }
}
