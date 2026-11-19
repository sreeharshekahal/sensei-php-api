<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ObservationController extends Controller
{
    /**
     * List observations visible to user, with optional group filter and pagination.
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $ura = DB::table('access_userroleaccount as ura')
                ->join('access_role as r', 'ura.role_id', '=', 'r.id')
                ->where('ura.user_id', $user->id)
                ->select('ura.id as ura_id', 'r.rank')
                ->first();

            if (!$ura) {
                return response()->json(['detail' => 'User role account not found.'], 404);
            }

            $userGroupIds = DB::table('access_group_users')
                ->where('userroleaccount_id', $ura->ura_id)
                ->pluck('group_id')
                ->toArray();

            $query = DB::table('observations_observation as o')
                ->join('access_userroleaccount as observer_ura', 'o.observer_id', '=', 'observer_ura.id')
                ->join('access_userroleaccount as observee_ura', 'o.observee_id', '=', 'observee_ura.id')
                ->join('access_role as observee_role', 'observee_ura.role_id', '=', 'observee_role.id')
                ->leftJoin('rubrics_rubric as rub', 'o.rubric_id', '=', 'rub.id')
                ->leftJoin('observations_ratingscheme as rs', 'rub.rating_scheme_id', '=', 'rs.id');

            $query->where(function ($q) use ($user, $ura, $userGroupIds) {
                $q->where('observer_ura.user_id', $user->id)
                  ->orWhere('observee_ura.user_id', $user->id);

                if (!empty($userGroupIds)) {
                    $q->orWhere(function ($sub) use ($ura, $userGroupIds) {
                        $sub->where('observee_role.rank', '>=', $ura->rank)
                            ->whereExists(function ($pivot) use ($userGroupIds) {
                                $pivot->select(DB::raw(1))
                                      ->from('access_group_users as gu')
                                      ->whereColumn('gu.userroleaccount_id', 'observee_ura.id')
                                      ->whereIn('gu.group_id', $userGroupIds);
                            });
                    });
                }
            });

            if ($request->filled('group')) {
                $query->where('o.group_id', $request->query('group'));
            }

            if ($request->filled('is_published')) {
                $isPublished = filter_var($request->query('is_published'), FILTER_VALIDATE_BOOLEAN);
                $query->where('o.is_published', $isPublished);
            }

            $total = $query->distinct()->count('o.id');

            $limit = (int)$request->query('limit', $request->query('page_size', 10));
            $limit = max(1, min($limit, 1000));

            $offset = (int)$request->query('offset', 0);
            if ($request->filled('page') && !$request->filled('offset')) {
                $page = max(1, (int)$request->query('page'));
                $offset = ($page - 1) * $limit;
            }

            $observations = $query->select([
                    'o.id',
                    'o.title',
                    'o.group_id',
                    'o.observer_id',
                    'o.observee_id',
                    'o.rubric_id',
                    'o.is_published',
                    'o.created',
                    'o.updated',
                    'rs.title as rating_scheme_title',
                    'rs.code as rating_scheme_code',
                ])
                ->distinct()
                ->orderBy('o.created', 'desc')
                ->offset($offset)
                ->limit($limit)
                ->get();

            $results = $this->formatObservations($observations, $request);

            return response()->json([
                'count'    => $total,
                'next'     => $this->buildNextUrl($request, $offset, $limit, $total),
                'previous' => $this->buildPrevUrl($request, $offset, $limit),
                'results'  => $results,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve observations.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    private function formatObservations($observations, Request $request): array
    {
        if ($observations->isEmpty()) {
            return [];
        }

        $obsIds = $observations->pluck('id')->toArray();
        $groupIds = $observations->pluck('group_id')->unique()->toArray();
        $observerIds = $observations->pluck('observer_id')->unique()->toArray();
        $observeeIds = $observations->pluck('observee_id')->unique()->toArray();
        $allUraIds = array_unique(array_merge($observerIds, $observeeIds));

        $formattedUras = $this->fetchFormattedUras($allUraIds);

        $groupsData = DB::table('access_group as g')
            ->leftJoin('rubrics_rubric as r', 'g.rubric_id', '=', 'r.id')
            ->leftJoin('access_userroleaccount as ura', 'g.created_by_id', '=', 'ura.id')
            ->leftJoin('auth_user as u', 'ura.user_id', '=', 'u.id')
            ->whereIn('g.id', $groupIds)
            ->select([
                'g.id',
                'g.title',
                'g.description',
                'u.username as created_by',
                'r.id as rubric_id',
                'r.title as rubric_title',
            ])
            ->get()
            ->keyBy('id');

        $groupUserCounts = DB::table('access_group_users')
            ->whereIn('group_id', $groupIds)
            ->select('group_id', DB::raw('COUNT(DISTINCT userroleaccount_id) as cnt'))
            ->groupBy('group_id')
            ->pluck('cnt', 'group_id');

        $evidenceCounts = DB::table('observations_evidence')
            ->whereIn('observation_id', $obsIds)
            ->select('observation_id', DB::raw('COUNT(id) as cnt'))
            ->groupBy('observation_id')
            ->pluck('cnt', 'observation_id');

        $baseUrl = $request->root();

        return $observations->map(function ($o) use ($groupsData, $groupUserCounts, $formattedUras, $evidenceCounts, $baseUrl) {
            $g = $groupsData->get($o->group_id);
            $groupObj = null;

            if ($g) {
                $groupObj = [
                    'id'          => $g->id,
                    'title'       => $g->title,
                    'description' => $g->description,
                    'users_count' => (int)($groupUserCounts[$g->id] ?? 0),
                    'created_by'  => $g->created_by,
                    'rubric'      => $g->rubric_id ? ['id' => $g->rubric_id, 'title' => $g->rubric_title] : null,
                ];
            }

            return [
                'id'                  => $o->id,
                'title'               => $o->title,
                'group'               => $groupObj,
                'group_id'            => $o->group_id,
                'observer'            => $formattedUras[$o->observer_id] ?? null,
                'observer_id'         => $o->observer_id,
                'observee'            => $formattedUras[$o->observee_id] ?? null,
                'observee_id'         => $o->observee_id,
                'rubric_id'           => $o->rubric_id,
                'is_published'        => (bool)$o->is_published,
                'rating_scheme_title' => $o->rating_scheme_title,
                'rating_scheme_code'  => $o->rating_scheme_code,
                'evidences_count'     => (int)($evidenceCounts[$o->id] ?? 0),
                'evidences_url'       => "{$baseUrl}/api/v1/observations/{$o->id}/evidences/",
                'created'             => $o->created ? Carbon::parse($o->created)->toJSON() : null,
                'updated'             => $o->updated ? Carbon::parse($o->updated)->toJSON() : null,
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
                'phone' => $item->phone ?? null,
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
            return $request->fullUrlWithQuery(['offset' => max(0, $offset - $limit), 'limit' => $limit]);
        }
        return null;
    }
}
