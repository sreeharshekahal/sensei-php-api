<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GroupController extends Controller
{
    /**
     * List paginated groups accessible to the logged-in user.
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $ura = $this->getCurrentUserRoleAccount($user->id);

            if (!$ura) {
                return response()->json(['detail' => 'User role account not found.'], 404);
            }

            $query = DB::table('access_group as g');

            if ($ura->rank !== 1) {
                $userGroupIds = DB::table('access_group_users')
                    ->where('userroleaccount_id', $ura->ura_id)
                    ->pluck('group_id')
                    ->toArray();

                $query->whereIn('g.id', $userGroupIds);
            }

            if ($request->filled('title')) {
                $query->where('g.title', 'like', '%' . $request->query('title') . '%');
            }

            if ($request->filled('search')) {
                $query->where('g.title', 'like', '%' . $request->query('search') . '%');
            }

            $total = $query->count();

            $limit = (int)$request->query('limit', $request->query('page_size', 10));
            $limit = max(1, min($limit, 1000));

            $offset = (int)$request->query('offset', 0);
            if ($request->filled('page') && !$request->filled('offset')) {
                $page = max(1, (int)$request->query('page'));
                $offset = ($page - 1) * $limit;
            }

            $groups = $query->select('g.*')
                ->orderBy('g.updated', 'desc')
                ->offset($offset)
                ->limit($limit)
                ->get();

            $results = $this->formatGroupDetails($groups);

            return response()->json([
                'count'    => $total,
                'next'     => $this->buildNextUrl($request, $offset, $limit, $total),
                'previous' => $this->buildPrevUrl($request, $offset, $limit),
                'results'  => $results,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve groups.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fetch specific group details by ID.
     */
    public function show(string $id)
    {
        try {
            if (empty($id) || !Str::isUuid($id)) {
                return response()->json(['detail' => 'Not found.'], 404);
            }

            $group = DB::table('access_group')->where('id', $id)->first();

            if (!$group) {
                return response()->json(['detail' => 'Not found.'], 404);
            }

            $formatted = $this->formatGroupDetails(collect([$group]));

            return response()->json($formatted[0], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve group details.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fetch users/observees in a specific group.
     */
    public function users(string $id)
    {
        try {
            if (empty($id) || !Str::isUuid($id)) {
                return response()->json(['detail' => 'Not found.'], 404);
            }

            $groupExists = DB::table('access_group')->where('id', $id)->exists();

            if (!$groupExists) {
                return response()->json(['detail' => 'Not found.'], 404);
            }

            $memberUraIds = DB::table('access_group_users')
                ->where('group_id', $id)
                ->pluck('userroleaccount_id')
                ->toArray();

            $members = $this->fetchFormattedUras($memberUraIds);

            return response()->json(array_values($members), 200);

        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve group members.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fetch user groups with nested members list.
     */
    public function myGroupBasedMembers(Request $request)
    {
        try {
            $user = $request->user();
            $ura = $this->getCurrentUserRoleAccount($user->id);

            if (!$ura) {
                return response()->json(['detail' => 'User role account not found.'], 404);
            }

            $userGroupIds = DB::table('access_group_users')
                ->where('userroleaccount_id', $ura->ura_id)
                ->pluck('group_id')
                ->toArray();

            $query = DB::table('access_group')->whereIn('id', $userGroupIds);

            $total = $query->count();

            $limit = (int)$request->query('limit', $request->query('page_size', 1000));
            $offset = (int)$request->query('offset', 0);
            if ($request->filled('page') && !$request->filled('offset')) {
                $page = max(1, (int)$request->query('page'));
                $offset = ($page - 1) * $limit;
            }

            $groups = $query->orderBy('title', 'asc')
                ->offset($offset)
                ->limit($limit)
                ->get();

            $groupIds = $groups->pluck('id')->toArray();

            $pivotQuery = DB::table('access_group_users as gu')
                ->join('access_userroleaccount as ura', 'gu.userroleaccount_id', '=', 'ura.id')
                ->join('auth_user as u', 'ura.user_id', '=', 'u.id')
                ->join('access_role as r', 'ura.role_id', '=', 'r.id')
                ->whereIn('gu.group_id', $groupIds);

            if ($request->query('apply_rank') == 1) {
                $pivotQuery->where(function ($q) use ($ura) {
                    $q->where('ura.id', $ura->ura_id)
                      ->orWhere('r.rank', '>', $ura->rank);
                });
            }

            if ($request->filled('name')) {
                $searchTerm = '%' . $request->query('name') . '%';
                $pivotQuery->where(function ($q) use ($searchTerm) {
                    $q->where('u.username', 'like', $searchTerm)
                      ->orWhere('u.first_name', 'like', $searchTerm)
                      ->orWhere('u.last_name', 'like', $searchTerm)
                      ->orWhere(DB::raw("CONCAT(u.first_name, ' ', u.last_name)"), 'like', $searchTerm);
                });
            }

            $pivotRows = $pivotQuery->select('gu.group_id', 'gu.userroleaccount_id')
                ->orderBy('u.first_name', 'asc')
                ->get();

            $allMemberUraIds = $pivotRows->pluck('userroleaccount_id')->unique()->toArray();
            $formattedUras = $this->fetchFormattedUras($allMemberUraIds);

            $usersLimit = $request->filled('users_limit') ? (int)$request->query('users_limit') : null;

            $groupMembersMap = [];
            foreach ($pivotRows as $row) {
                if (!isset($formattedUras[$row->userroleaccount_id])) {
                    continue;
                }
                if ($usersLimit !== null && isset($groupMembersMap[$row->group_id]) && count($groupMembersMap[$row->group_id]) >= $usersLimit) {
                    continue;
                }
                $groupMembersMap[$row->group_id][] = $formattedUras[$row->userroleaccount_id];
            }

            $results = $groups->map(function ($g) use ($groupMembersMap) {
                return [
                    'id'          => $g->id,
                    'members'     => $groupMembersMap[$g->id] ?? [],
                    'title'       => $g->title,
                    'description' => $g->description,
                    'account'     => $g->account_id,
                    'created_by'  => $g->created_by_id,
                    'rubric'      => $g->rubric_id,
                ];
            })->toArray();

            return response()->json([
                'count'    => $total,
                'next'     => $this->buildNextUrl($request, $offset, $limit, $total),
                'previous' => $this->buildPrevUrl($request, $offset, $limit),
                'results'  => $results,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve group members.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    private function getCurrentUserRoleAccount(int $userId): ?object
    {
        return DB::table('access_userroleaccount as ura')
            ->join('access_role as r', 'ura.role_id', '=', 'r.id')
            ->where('ura.user_id', $userId)
            ->select('ura.id as ura_id', 'ura.account_id', 'r.rank', 'r.title as role_title')
            ->first();
    }

    public function formatGroupDetails($groups): array
    {
        if ($groups->isEmpty()) {
            return [];
        }

        $groupIds = $groups->pluck('id')->toArray();
        $accountIds = $groups->pluck('account_id')->filter()->unique()->toArray();
        $rubricIds = $groups->pluck('rubric_id')->filter()->unique()->toArray();
        $creatorUraIds = $groups->pluck('created_by_id')->filter()->unique()->toArray();

        $accountsMap = DB::table('access_account')
            ->whereIn('id', $accountIds)
            ->get()
            ->keyBy('id');

        $rubricsMap = DB::table('rubrics_rubric as rub')
            ->leftJoin('observations_ratingscheme as rs', 'rub.rating_scheme_id', '=', 'rs.id')
            ->whereIn('rub.id', $rubricIds)
            ->select([
                'rub.id',
                'rub.title',
                'rub.description',
                'rub.rating_scheme_id as rating_scheme',
                'rub.account_id as account',
                'rub.updated',
                'rs.title as rating_scheme_title',
                'rs.code as rating_scheme_code',
                'rs.is_input_required',
            ])
            ->get()
            ->keyBy('id');

        $creatorsMap = $this->fetchFormattedUras($creatorUraIds);

        $groupUsers = DB::table('access_group_users')
            ->whereIn('group_id', $groupIds)
            ->select('group_id', 'userroleaccount_id')
            ->get();

        $groupUsersMap = [];
        $usersCounts = [];
        foreach ($groupUsers as $gu) {
            $groupUsersMap[$gu->group_id][] = $gu->userroleaccount_id;
            $usersCounts[$gu->group_id] = ($usersCounts[$gu->group_id] ?? 0) + 1;
        }

        $obsCounts = DB::table('observations_observation')
            ->whereIn('group_id', $groupIds)
            ->where('is_published', true)
            ->select('group_id', DB::raw('COUNT(id) as cnt'))
            ->groupBy('group_id')
            ->pluck('cnt', 'group_id');

        $baseUrl = request()->root();

        return $groups->map(function ($g) use ($accountsMap, $rubricsMap, $creatorsMap, $groupUsersMap, $usersCounts, $obsCounts, $baseUrl) {
            $account = $accountsMap->get($g->account_id);
            $rubric = $rubricsMap->get($g->rubric_id);

            $accountObj = null;
            if ($account) {
                $accountObj = [
                    'id'          => $account->id,
                    'title'       => $account->title,
                    'description' => $account->description ?? '',
                    'logo'        => $account->logo_id ?? null,
                    'app_url'     => $account->app_url ?? '',
                ];
            }

            $rubricObj = null;
            if ($rubric) {
                $rubricObj = [
                    'id'                  => $rubric->id,
                    'title'               => $rubric->title,
                    'description'         => $rubric->description ?? '',
                    'updated'             => $rubric->updated ? Carbon::parse($rubric->updated)->toJSON() : null,
                    'rating_scheme'       => $rubric->rating_scheme,
                    'account'             => $rubric->account,
                    'rating_scheme_title' => $rubric->rating_scheme_title,
                    'rating_scheme_code'  => $rubric->rating_scheme_code,
                    'is_input_required'   => (bool)$rubric->is_input_required,
                    'indicator_url'       => request()->getSchemeAndHttpHost() . "/v1/indicators?rubric={$rubric->id}",
                ];
            }

            return [
                'id'                 => $g->id,
                'title'              => $g->title,
                'description'        => $g->description,
                'users'              => $groupUsersMap[$g->id] ?? [],
                'account'            => $accountObj,
                'account_id'         => $g->account_id,
                'rubric'             => $rubricObj,
                'rubric_id'          => $g->rubric_id,
                'created_by'         => $creatorsMap[$g->created_by_id] ?? null,
                'created_by_id'      => $g->created_by_id,
                'users_count'        => (int)($usersCounts[$g->id] ?? 0),
                'observations_count' => (int)($obsCounts[$g->id] ?? 0),
                'updated'            => $g->updated ? Carbon::parse($g->updated)->toJSON() : null,
            ];
        })->toArray();
    }

    private function fetchFormattedUras(array $uraIds): array
    {
        if (empty($uraIds)) {
            return [];
        }

        $records = DB::table('access_userroleaccount as ura')
            ->join('auth_user as u', 'ura.user_id', '=', 'u.id')
            ->leftJoin('common_profile as p', 'p.user_id', '=', 'u.id')
            ->join('access_role as r', 'ura.role_id', '=', 'r.id')
            ->whereIn('ura.id', $uraIds)
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
                'r.rank as role_rank',
            ])
            ->get();

        $groupsMap = DB::table('access_group_users')
            ->whereIn('userroleaccount_id', $uraIds)
            ->select('userroleaccount_id', 'group_id')
            ->get()
            ->groupBy('userroleaccount_id')
            ->map(fn ($items) => $items->pluck('group_id')->toArray())
            ->toArray();

        $formatted = [];
        foreach ($records as $item) {
            $formatted[$item->user_role_account_id] = $this->formatUserRoleAccount(
                $item,
                $groupsMap[$item->user_role_account_id] ?? []
            );
        }

        return $formatted;
    }

    private function formatUserRoleAccount(object $item, array $groups = []): array
    {
        return [
            'id' => $item->user_role_account_id ?? $item->id,
            'profile' => [
                'id' => $item->profile_id ?? null,
                'avatar' => $item->avatar_id ?? null,
                'user' => [
                    'username' => $item->username ?? '',
                    'first_name' => $item->first_name ?? '',
                    'last_name' => $item->last_name ?? '',
                    'full_name' => trim(($item->first_name ?? '') . ' ' . ($item->last_name ?? '')),
                    'email' => $item->email ?? '',
                    'last_login' => !empty($item->last_login) ? Carbon::parse($item->last_login)->toJSON() : null,
                ],
                'created' => !empty($item->profile_created) ? Carbon::parse($item->profile_created)->toJSON() : null,
                'updated' => !empty($item->profile_updated) ? Carbon::parse($item->profile_updated)->toJSON() : null,
                'phone' => !empty($item->phone) ? (string)$item->phone : '',
                'designation' => $item->designation ?? null,
                'dob' => $item->dob ?? null,
            ],
            'groups' => $groups,
            'role_title' => $item->role_title ?? null,
            'role_rank' => isset($item->role_rank) ? (int)$item->role_rank : null,
            'role_id' => $item->role_id ?? null,
            'account' => $item->account_id ?? null,
        ];
    }

    private function buildNextUrl(Request $request, int $offset, int $limit, int $total): ?string
    {
        if ($offset + $limit < $total) {
            return $request->fullUrlWithQuery(['offset' => $offset + $limit, 'limit' => $limit]);
        }
        return null;
    }

    private function buildPrevUrl(Request $request, int $offset, int $limit): ?string
    {
        if ($offset > 0) {
            $prevOffset = $offset - $limit;
            if ($prevOffset <= 0) {
                $url = $request->fullUrlWithQuery(['limit' => $limit]);
                $parsed = parse_url($url);
                parse_str($parsed['query'] ?? '', $queryParams);
                unset($queryParams['offset']);
                $newQuery = http_build_query($queryParams);
                $scheme = $parsed['scheme'] ?? 'http';
                $host = $parsed['host'] ?? '';
                $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
                $path = $parsed['path'] ?? '';
                return $scheme . '://' . $host . $port . $path . ($newQuery ? '?' . $newQuery : '');
            }
            return $request->fullUrlWithQuery(['offset' => $prevOffset, 'limit' => $limit]);
        }
        return null;
    }
}
