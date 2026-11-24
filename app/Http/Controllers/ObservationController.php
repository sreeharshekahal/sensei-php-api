<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ObservationController extends Controller
{
    /**
     * List observations visible to user, with filters and pagination.
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

            if ((int)$ura->rank > 1) {
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
            }

            if ($request->filled('group')) {
                $groupIds = array_filter(explode(',', $request->query('group')), fn ($gid) => Str::isUuid($gid));
                if (count($groupIds) > 1) {
                    $query->whereIn('o.group_id', $groupIds);
                } elseif (count($groupIds) === 1) {
                    $query->where('o.group_id', reset($groupIds));
                }
            }

            if ($request->filled('observee') && Str::isUuid($request->query('observee'))) {
                $query->where('o.observee_id', $request->query('observee'));
            }

            if ($request->filled('observer') && Str::isUuid($request->query('observer'))) {
                $query->where('o.observer_id', $request->query('observer'));
            }

            if ($request->filled('of') && Str::isUuid($request->query('of'))) {
                $of = $request->query('of');
                $query->where(function ($q) use ($of) {
                    $q->where('o.observer_id', $of)
                      ->orWhere('o.observee_id', $of);
                });
            }

            if ($request->filled('is_published')) {
                $isPublished = filter_var($request->query('is_published'), FILTER_VALIDATE_BOOLEAN);
                $query->where('o.is_published', $isPublished);
            }

            if ($request->filled('is_observers_learning')) {
                $isLearning = filter_var($request->query('is_observers_learning'), FILTER_VALIDATE_BOOLEAN);
                $query->whereExists(function ($sub) use ($isLearning) {
                    $sub->select(DB::raw(1))
                        ->from('observations_evidence as e')
                        ->whereColumn('e.observation_id', 'o.id')
                        ->where('e.is_observers_learning', $isLearning);
                });
            }

            if ($request->filled('account')) {
                $accountId = $request->query('account');
                $query->join('access_group as ag_acc', 'o.group_id', '=', 'ag_acc.id')
                      ->where('ag_acc.account_id', $accountId);
            }

            $total = $query->distinct()->count('o.id');

            $limit = (int)$request->query('limit', $request->query('page_size', 10));
            $limit = max(1, min($limit, 1000));

            $offset = (int)$request->query('offset', 0);
            if ($request->filled('page') && !$request->filled('offset')) {
                $page = max(1, (int)$request->query('page'));
                $offset = ($page - 1) * $limit;
            }

            $ordering = $request->query('ordering', '-created');
            $orderDirection = str_starts_with($ordering, '-') ? 'desc' : 'asc';
            $orderField = ltrim($ordering, '-');
            if (!in_array($orderField, ['created', 'updated', 'title'])) {
                $orderField = 'created';
                $orderDirection = 'desc';
            }

            $observations = $query->select([
                    'o.id',
                    'o.title',
                    'o.description',
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
                ->orderBy("o.{$orderField}", $orderDirection)
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

    /**
     * Fetch full observation details by ID.
     */
    public function show(Request $request, string $id)
    {
        try {
            if (empty($id) || !Str::isUuid($id)) {
                return response()->json(['detail' => 'Observation not found.'], 404);
            }
            $user = $request->user();
            $ura = DB::table('access_userroleaccount as ura')
                ->join('access_role as r', 'ura.role_id', '=', 'r.id')
                ->where('ura.user_id', $user->id)
                ->select('ura.id as ura_id', 'r.rank')
                ->first();

            if (!$ura) {
                return response()->json(['detail' => 'User role account not found.'], 404);
            }

            $obs = DB::table('observations_observation as o')
                ->leftJoin('rubrics_rubric as rub', 'o.rubric_id', '=', 'rub.id')
                ->leftJoin('observations_ratingscheme as rs', 'rub.rating_scheme_id', '=', 'rs.id')
                ->where('o.id', $id)
                ->select([
                    'o.id',
                    'o.title',
                    'o.description',
                    'o.group_id',
                    'o.observer_id',
                    'o.observee_id',
                    'o.rubric_id',
                    'o.is_published',
                    'o.created',
                    'o.updated',
                    'rs.title as rating_scheme_title',
                    'rs.code as rating_scheme_code',
                    'rub.rating_scheme_id',
                ])
                ->first();

            if (!$obs) {
                return response()->json(['detail' => 'Observation not found.'], 404);
            }

            if ((int)$ura->rank > 1 && $obs->observer_id !== $ura->ura_id && $obs->observee_id !== $ura->ura_id) {
                $userGroupIds = DB::table('access_group_users')
                    ->where('userroleaccount_id', $ura->ura_id)
                    ->pluck('group_id')
                    ->toArray();

                $observeeRank = DB::table('access_userroleaccount as ura')
                    ->join('access_role as r', 'ura.role_id', '=', 'r.id')
                    ->where('ura.id', $obs->observee_id)
                    ->value('r.rank');

                $sharedGroup = DB::table('access_group_users')
                    ->where('userroleaccount_id', $obs->observee_id)
                    ->whereIn('group_id', $userGroupIds)
                    ->exists();

                if (!($observeeRank !== null && $observeeRank >= (int)$ura->rank && $sharedGroup)) {
                    return response()->json(['detail' => 'You do not have permission to view this observation.'], 403);
                }
            }

            $formatted = $this->formatObservations(collect([$obs]), $request);
            $data = $formatted[0] ?? [];

            $indicatorsCount = (int) DB::table('observations_indicatorrating as ir')
                ->join('observations_evidence as e', 'ir.evidence_id', '=', 'e.id')
                ->where('e.observation_id', $obs->id)
                ->count('ir.id');

            $indicatorRatings = DB::table('observations_indicatorrating as ir')
                ->join('observations_evidence as e', 'ir.evidence_id', '=', 'e.id')
                ->join('rubrics_indicator as ind', 'ir.indicator_id', '=', 'ind.id')
                ->join('observations_rating as r', 'ir.rating_id', '=', 'r.id')
                ->where('e.observation_id', $obs->id)
                ->select([
                    'ir.id',
                    'ir.indicator_id as indicator',
                    'ind.title as indicator_title',
                    'ir.rating_id as rating',
                    'r.score as rating_score',
                    'r.title as rating_title',
                ])
                ->get()
                ->map(function ($row) {
                    return [
                        'id'              => $row->id,
                        'indicator'       => $row->indicator,
                        'indicator_title' => $row->indicator_title,
                        'rating'          => $row->rating,
                        'rating_score'    => (string)$row->rating_score,
                        'rating_title'    => $row->rating_title,
                    ];
                })
                ->toArray();

            $totalIndicatorsCount = (int) DB::table('rubrics_indicator as ind')
                ->join('rubrics_subproposition as sub', 'ind.subproposition_id', '=', 'sub.id')
                ->join('rubrics_proposition as prop', 'sub.proposition_id', '=', 'prop.id')
                ->where('prop.rubric_id', $obs->rubric_id)
                ->count('ind.id');

            $maxRatingScore = DB::table('observations_rating')
                ->where('rating_scheme_id', $obs->rating_scheme_id)
                ->max('score');

            $avgScore = DB::table('observations_indicatorrating as ir')
                ->join('observations_evidence as e', 'ir.evidence_id', '=', 'e.id')
                ->join('observations_rating as r', 'ir.rating_id', '=', 'r.id')
                ->where('e.observation_id', $obs->id)
                ->avg('r.score');

            $avgRatingScore = $avgScore !== null ? (float)$avgScore : null;

            $diffSincePrevious = null;
            if ($avgRatingScore !== null) {
                $prevObs = DB::table('observations_observation')
                    ->where('group_id', $obs->group_id)
                    ->where('observee_id', $obs->observee_id)
                    ->where('created', '<', $obs->created)
                    ->orderBy('created', 'desc')
                    ->first();

                if ($prevObs) {
                    $prevAvg = DB::table('observations_indicatorrating as ir')
                        ->join('observations_evidence as e', 'ir.evidence_id', '=', 'e.id')
                        ->join('observations_rating as r', 'ir.rating_id', '=', 'r.id')
                        ->where('e.observation_id', $prevObs->id)
                        ->avg('r.score');

                    if ($prevAvg !== null) {
                        $diffSincePrevious = round($avgRatingScore - (float)$prevAvg, 2);
                    }
                } else {
                    $diffSincePrevious = 0.0;
                }
            }

            $data['description'] = $obs->description ?? null;
            $data['indicators_count'] = $indicatorsCount;
            $data['indicators_rating'] = $indicatorRatings;
            $data['total_indicators_count'] = $totalIndicatorsCount;
            $data['max_rating_score'] = $maxRatingScore !== null ? (int)$maxRatingScore : null;
            $data['avg_rating_score'] = $avgRatingScore !== null ? round($avgRatingScore, 2) : null;
            $data['diff_since_previous_observation'] = $diffSincePrevious;

            return response()->json($data, 200);

        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve observation details.',
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
            ->leftJoin('observations_ratingscheme as rs', 'r.rating_scheme_id', '=', 'rs.id')
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
                'r.description as rubric_description',
                'r.rating_scheme_id as rubric_rating_scheme',
                'r.account_id as rubric_account',
                'r.updated as rubric_updated',
                'rs.title as rubric_rating_scheme_title',
                'rs.code as rubric_rating_scheme_code',
                'rs.is_input_required as rubric_is_input_required',
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

        $schemeAndHost = $request->getSchemeAndHttpHost();

        return $observations->map(function ($o) use ($groupsData, $groupUserCounts, $formattedUras, $evidenceCounts, $schemeAndHost) {
            $g = $groupsData->get($o->group_id);
            $groupObj = null;

            if ($g) {
                $rubricObj = $g->rubric_id ? [
                    'id'                  => $g->rubric_id,
                    'indicator_url'       => "{$schemeAndHost}/v1/indicators?rubric={$g->rubric_id}",
                    'rating_scheme_title' => $g->rubric_rating_scheme_title,
                    'rating_scheme_code'  => $g->rubric_rating_scheme_code,
                    'is_input_required'   => (bool)$g->rubric_is_input_required,
                    'updated'             => $g->rubric_updated ? Carbon::parse($g->rubric_updated)->toJSON() : null,
                    'title'               => $g->rubric_title,
                    'description'         => $g->rubric_description ?? '',
                    'account'             => $g->rubric_account,
                    'rating_scheme'       => $g->rubric_rating_scheme,
                ] : null;

                $groupObj = [
                    'id'          => $g->id,
                    'users_count' => (int)($groupUserCounts[$g->id] ?? 0),
                    'created_by'  => $g->created_by,
                    'rubric'      => $rubricObj,
                    'title'       => $g->title,
                    'description' => $g->description ?? '',
                ];
            }

            return [
                'id'                  => $o->id,
                'observer'            => $formattedUras[$o->observer_id] ?? null,
                'observee'            => $formattedUras[$o->observee_id] ?? null,
                'rating_scheme_title' => $o->rating_scheme_title,
                'rating_scheme_code'  => $o->rating_scheme_code,
                'group'               => $groupObj,
                'evidences_count'     => (int)($evidenceCounts[$o->id] ?? 0),
                'evidences_url'       => "{$schemeAndHost}/v1/observations/{$o->id}/evidences/",
                'created'             => $o->created ? Carbon::parse($o->created)->toJSON() : null,
                'updated'             => $o->updated ? Carbon::parse($o->updated)->toJSON() : null,
                'title'               => $o->title,
                'description'         => $o->description ?? null,
                'is_published'        => (bool)$o->is_published,
                'rubric'              => $o->rubric_id,
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
