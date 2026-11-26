<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ObservationReportController extends Controller
{
    /**
     * GET /group-observation-activity-reports/{id}
     */
    public function groupObservationActivityReport(Request $request, string $id)
    {
        if (!Str::isUuid($id)) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        $group = DB::table('access_group')->where('id', $id)->first();
        if (!$group) {
            return response()->json(['detail' => 'Group not found.'], 404);
        }

        try {
            $data = $this->computeActivityReport($id, null, $request);

            return response()->json($data, 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve group observation activity report.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /groups/{groupId}/member-observation-activity-reports/{uraId}
     */
    public function memberObservationActivityReport(Request $request, string $groupId, string $uraId)
    {
        if (!Str::isUuid($groupId) || !Str::isUuid($uraId)) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        $group = DB::table('access_group')->where('id', $groupId)->first();
        if (!$group) {
            return response()->json(['detail' => 'Group not found.'], 404);
        }

        $member = DB::table('access_userroleaccount')->where('id', $uraId)->first();
        if (!$member) {
            return response()->json(['detail' => 'Member not found.'], 404);
        }

        try {
            $data = $this->computeActivityReport($groupId, $uraId, $request);

            return response()->json($data, 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve member observation activity report.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /group-observation-indicators-reports/{id}
     */
    public function groupObservationIndicatorsReport(Request $request, string $id)
    {
        if (!Str::isUuid($id)) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        $group = DB::table('access_group')->where('id', $id)->first();
        if (!$group) {
            return response()->json(['detail' => 'Group not found.'], 404);
        }

        try {
            $data = $this->computeIndicatorsReport($id, null, $request);

            return response()->json($data, 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve group indicator analytics.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /groups/{groupId}/member-observation-indicators-reports/{uraId}
     */
    public function memberObservationIndicatorsReport(Request $request, string $groupId, string $uraId)
    {
        if (!Str::isUuid($groupId) || !Str::isUuid($uraId)) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        $group = DB::table('access_group')->where('id', $groupId)->first();
        if (!$group) {
            return response()->json(['detail' => 'Group not found.'], 404);
        }

        $member = DB::table('access_userroleaccount')->where('id', $uraId)->first();
        if (!$member) {
            return response()->json(['detail' => 'Member not found.'], 404);
        }

        try {
            $data = $this->computeIndicatorsReport($groupId, $uraId, $request);

            return response()->json($data, 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve member indicator analytics.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /groups/{groupId}/member-observation-rating-reports
     */
    public function memberObservationRatingReports(Request $request, string $groupId)
    {
        if (!Str::isUuid($groupId)) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        $group = DB::table('access_group')->where('id', $groupId)->first();
        if (!$group) {
            return response()->json(['detail' => 'Group not found.'], 404);
        }

        $currentUra = $this->getCurrentUserRoleAccount($request->user()->id);
        if (!$currentUra) {
            return response()->json(['detail' => 'User role account not found.'], 404);
        }

        try {
            $membersQuery = DB::table('access_group_users as gu')
                ->join('access_userroleaccount as ura', 'gu.userroleaccount_id', '=', 'ura.id')
                ->join('auth_user as u', 'ura.user_id', '=', 'u.id')
                ->leftJoin('common_profile as p', 'p.user_id', '=', 'u.id')
                ->join('access_role as r', 'ura.role_id', '=', 'r.id')
                ->where('gu.group_id', $groupId)
                ->where('r.rank', '>=', $currentUra->rank)
                ->orderBy('p.created', 'desc');

            $total = (clone $membersQuery)->count('ura.id');

            $pageSize = max(1, min((int) $request->query('page_size', $request->query('limit', 1000)), 1000));
            $page = max(1, (int) $request->query('page', 1));
            $offset = $request->filled('offset') ? max(0, (int) $request->query('offset')) : ($page - 1) * $pageSize;

            $members = $membersQuery->select([
                'ura.id as ura_id',
                'ura.account_id',
                'u.username',
                'u.first_name',
                'u.last_name',
                'u.email',
                'u.last_login',
                'p.id as profile_id',
                'p.avatar_id',
                'p.created as profile_created',
                'p.updated as profile_updated',
                'p.phone',
                'p.designation',
                'p.dob',
                'r.id as role_id',
                'r.title as role_title',
                'r.rank as role_rank',
            ])
                ->offset($offset)
                ->limit($pageSize)
                ->get();

            $uraIds = $members->pluck('ura_id')->toArray();
            $groupsMap = empty($uraIds)
                ? []
                : DB::table('access_group_users')
                    ->whereIn('userroleaccount_id', $uraIds)
                    ->select('userroleaccount_id', 'group_id')
                    ->get()
                    ->groupBy('userroleaccount_id')
                    ->map(fn ($items) => $items->pluck('group_id')->toArray())
                    ->toArray();

            $statsByUra = $this->computeMemberRatingStatsMap($groupId, $uraIds, $request);

            $results = $members->map(fn ($m) => $this->formatMemberRatingReport($m, $groupsMap[$m->ura_id] ?? [], $statsByUra[$m->ura_id] ?? null))->values();

            $next = ($offset + $pageSize < $total)
                ? $request->fullUrlWithQuery(['page' => $page + 1, 'page_size' => $pageSize])
                : null;
            $previous = ($page > 1 && $offset > 0)
                ? $request->fullUrlWithQuery(['page' => $page - 1, 'page_size' => $pageSize])
                : null;

            return response()->json([
                'count'    => $total,
                'next'     => $next,
                'previous' => $previous,
                'results'  => $results,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve member observation rating reports.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /groups/{groupId}/member-observation-rating-reports/{uraId}
     */
    public function memberObservationRatingReportDetail(Request $request, string $groupId, string $uraId)
    {
        if (!Str::isUuid($groupId) || !Str::isUuid($uraId)) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        $group = DB::table('access_group')->where('id', $groupId)->first();
        if (!$group) {
            return response()->json(['detail' => 'Group not found.'], 404);
        }

        $currentUra = $this->getCurrentUserRoleAccount($request->user()->id);
        if (!$currentUra) {
            return response()->json(['detail' => 'User role account not found.'], 404);
        }

        $member = DB::table('access_group_users as gu')
            ->join('access_userroleaccount as ura', 'gu.userroleaccount_id', '=', 'ura.id')
            ->join('auth_user as u', 'ura.user_id', '=', 'u.id')
            ->leftJoin('common_profile as p', 'p.user_id', '=', 'u.id')
            ->join('access_role as r', 'ura.role_id', '=', 'r.id')
            ->where('gu.group_id', $groupId)
            ->where('ura.id', $uraId)
            ->where('r.rank', '>=', $currentUra->rank)
            ->select([
                'ura.id as ura_id',
                'ura.account_id',
                'u.username',
                'u.first_name',
                'u.last_name',
                'u.email',
                'u.last_login',
                'p.id as profile_id',
                'p.avatar_id',
                'p.created as profile_created',
                'p.updated as profile_updated',
                'p.phone',
                'p.designation',
                'p.dob',
                'r.id as role_id',
                'r.title as role_title',
                'r.rank as role_rank',
            ])
            ->first();

        if (!$member) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        try {
            $groups = DB::table('access_group_users')
                ->where('userroleaccount_id', $uraId)
                ->pluck('group_id')
                ->toArray();

            $statsByUra = $this->computeMemberRatingStatsMap($groupId, [$uraId], $request);

            return response()->json(
                $this->formatMemberRatingReport($member, $groups, $statsByUra[$uraId] ?? null),
                200
            );
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve member observation rating report.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /groups/{groupId}/observation-advance-report
     */
    public function advanceReport(Request $request, string $groupId)
    {
        if (!Str::isUuid($groupId)) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        $group = DB::table('access_group')->where('id', $groupId)->first();
        if (!$group) {
            return response()->json(['detail' => 'Group not found.'], 404);
        }

        try {
            $data = $this->computeAdvanceReport($groupId, $request);
            $total = count($data['rows']);

            $pageSize = max(1, min((int) $request->query('page_size', $request->query('limit', 1000)), 1000));
            $page = max(1, (int) $request->query('page', 1));
            $offset = $request->filled('offset') ? max(0, (int) $request->query('offset')) : ($page - 1) * $pageSize;

            $paged = array_slice($data['rows'], $offset, $pageSize);

            $next = ($offset + $pageSize < $total)
                ? $request->fullUrlWithQuery(['page' => $page + 1, 'page_size' => $pageSize])
                : null;
            $previous = ($page > 1 && $offset > 0)
                ? $request->fullUrlWithQuery(['page' => $page - 1, 'page_size' => $pageSize])
                : null;

            return response()->json([
                'count'    => $total,
                'next'     => $next,
                'previous' => $previous,
                'level'    => $data['level'],
                'results'  => $paged,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve advance observation report.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /groups/{groupId}/observation-advance-report/download_report
     */
    public function downloadAdvanceReport(Request $request, string $groupId)
    {
        if (!Str::isUuid($groupId)) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        $group = DB::table('access_group')->where('id', $groupId)->first();
        if (!$group) {
            return response()->json(['detail' => 'Group not found.'], 404);
        }

        try {
            $data = $this->computeAdvanceReport($groupId, $request);
            $lines = [
                "Group: {$group->title}",
                "Level: {$data['level']}",
                "Generated: " . Carbon::now()->toDateTimeString(),
                str_repeat('-', 70),
            ];

            foreach ($data['rows'] as $row) {
                $lines[] = "{$row['title']} | Avg: " . number_format($row['average_score'], 2) . " | Ratings: {$row['ratings_count']} | Obs: {$row['observations_count']}";
            }

            if (empty($data['rows'])) {
                $lines[] = 'No rating data found for the selected filters.';
            }

            $pdfBinary = $this->generateSimplePdf('Observation Advance Report', $lines);

            return response($pdfBinary, 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"advance-report-{$groupId}.pdf\"",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to download advance observation report.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /groups/{groupId}/advance-trends/{pk}
     */
    public function advanceTrends(Request $request, string $groupId, string $pk)
    {
        if (!Str::isUuid($groupId) || !Str::isUuid($pk)) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        $group = DB::table('access_group')->where('id', $groupId)->first();
        if (!$group) {
            return response()->json(['detail' => 'Group not found.'], 404);
        }

        try {
            $data = $this->computeAdvanceTrends($groupId, $pk, $request);

            return response()->json([
                'group'       => ['id' => $group->id, 'title' => $group->title],
                'level'       => $data['level'],
                'level_value' => $pk,
                'trends'      => $data['trends'],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve advance trends.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /groups/{groupId}/advance-trends/{pk}/download_report
     */
    public function downloadAdvanceTrends(Request $request, string $groupId, string $pk)
    {
        if (!Str::isUuid($groupId) || !Str::isUuid($pk)) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        $group = DB::table('access_group')->where('id', $groupId)->first();
        if (!$group) {
            return response()->json(['detail' => 'Group not found.'], 404);
        }

        try {
            $data = $this->computeAdvanceTrends($groupId, $pk, $request);
            $lines = [
                "Group: {$group->title}",
                "Level: {$data['level']} ({$pk})",
                "Generated: " . Carbon::now()->toDateTimeString(),
                str_repeat('-', 70),
            ];

            foreach ($data['trends'] as $point) {
                $lines[] = "{$point['date']} | Avg: " . number_format($point['average_score'], 2) . " | Ratings: {$point['ratings_count']} | Obs: {$point['observations_count']}";
            }

            if (empty($data['trends'])) {
                $lines[] = 'No trend data found for the selected filters.';
            }

            $pdfBinary = $this->generateSimplePdf('Observation Advance Trends Report', $lines);

            return response($pdfBinary, 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"advance-trends-{$groupId}-{$pk}.pdf\"",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to download advance trends report.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    private function computeActivityReport(string $groupId, ?string $uraId, Request $request): array
    {
        $obsQuery = DB::table('observations_observation as o')
            ->where('o.group_id', $groupId);

        if ($uraId) {
            $obsQuery->where('o.observee_id', $uraId);
        }

        $this->applyDateFilter($obsQuery, 'o.created', $request);

        $totalObservation = (int) (clone $obsQuery)->count('o.id');
        $lastObsCreated = (clone $obsQuery)->max('o.created');

        $observationStats = (clone $obsQuery)
            ->select([
                DB::raw('DATE(o.created) as date'),
                DB::raw('COUNT(o.id) as observation_count'),
            ])
            ->groupBy(DB::raw('DATE(o.created)'))
            ->orderBy(DB::raw('DATE(o.created)'), 'asc')
            ->get()
            ->map(fn($row) => [
                'date'              => Carbon::parse($row->date)->format('Y-m-d'),
                'observation_count' => (int) $row->observation_count,
            ])
            ->values();

        $memberCount = $uraId
            ? 1
            : (int) DB::table('access_group_users')->where('group_id', $groupId)->count('userroleaccount_id');

        $ratingQuery = DB::table('observations_indicatorrating as ir')
            ->join('observations_evidence as e', 'ir.evidence_id', '=', 'e.id')
            ->join('observations_observation as o', 'e.observation_id', '=', 'o.id')
            ->join('observations_rating as rt', 'ir.rating_id', '=', 'rt.id')
            ->where('o.group_id', $groupId);

        if ($uraId) {
            $ratingQuery->where('o.observee_id', $uraId);
        }

        $this->applyDateFilter($ratingQuery, 'o.created', $request);

        $overallRating = $ratingQuery->avg('rt.score');

        return [
            'observation_stats' => $observationStats,
            'last_observation'  => $lastObsCreated ? Carbon::parse($lastObsCreated)->toJSON() : null,
            'total_observation' => $totalObservation,
            'member_count'      => $memberCount,
            'overall_rating'    => $overallRating !== null ? (float) $overallRating : 0.0,
        ];
    }

    private function computeIndicatorsReport(string $groupId, ?string $uraId, Request $request): array
    {
        $query = DB::table('observations_indicatorrating as ir')
            ->join('observations_evidence as e', 'ir.evidence_id', '=', 'e.id')
            ->join('observations_observation as o', 'e.observation_id', '=', 'o.id')
            ->join('rubrics_indicator as ind', 'ir.indicator_id', '=', 'ind.id')
            ->join('rubrics_subproposition as sub', 'ind.subproposition_id', '=', 'sub.id')
            ->join('rubrics_proposition as prop', 'sub.proposition_id', '=', 'prop.id')
            ->join('observations_rating as rt', 'ir.rating_id', '=', 'rt.id')
            ->where('o.group_id', $groupId);

        if ($uraId) {
            $query->where('o.observee_id', $uraId);
        }

        $this->applyDateFilter($query, 'o.created', $request);

        $overallRating = (clone $query)->avg('rt.score');

        $rows = $query->select([
            'ind.id as indicator_id',
            'ind.title as indicator_title',
            'sub.id as subproposition_id',
            'sub.title as subproposition_title',
            'prop.id as proposition_id',
            'prop.title as proposition_title',
            DB::raw('COUNT(ir.id) as indicator_frequency'),
            DB::raw('AVG(rt.score) as indicator_average'),
        ])
            ->groupBy('ind.id', 'ind.title', 'sub.id', 'sub.title', 'prop.id', 'prop.title')
            ->orderBy('prop.title')
            ->orderBy('sub.title')
            ->orderBy('ind.title')
            ->get();

        $propositions = [];

        foreach ($rows as $row) {
            $propId = $row->proposition_id;
            $subId = $row->subproposition_id;

            if (!isset($propositions[$propId])) {
                $propositions[$propId] = [
                    'proposition_id'      => $propId,
                    'proposition_title'   => $row->proposition_title,
                    'proposition_average' => 0.0,
                    'subpropositions'     => [],
                ];
            }

            if (!isset($propositions[$propId]['subpropositions'][$subId])) {
                $propositions[$propId]['subpropositions'][$subId] = [
                    'subproposition_id'      => $subId,
                    'subproposition_title'   => $row->subproposition_title,
                    'subproposition_average' => 0.0,
                    'indicators'             => [],
                ];
            }

            $propositions[$propId]['subpropositions'][$subId]['indicators'][] = [
                'indicator_id'        => $row->indicator_id,
                'indicator_title'     => $row->indicator_title,
                'indicator_average'   => (float) $row->indicator_average,
                'indicator_frequency' => (int) $row->indicator_frequency,
            ];
        }

        $formattedPropositions = [];
        foreach ($propositions as $prop) {
            $subList = [];
            $subAverages = [];

            foreach ($prop['subpropositions'] as $sub) {
                $indAverages = array_column($sub['indicators'], 'indicator_average');
                $subAvg = count($indAverages) > 0 ? array_sum($indAverages) / count($indAverages) : 0.0;
                $sub['subproposition_average'] = (float) $subAvg;
                $subList[] = $sub;
                $subAverages[] = $subAvg;
            }

            $prop['subpropositions'] = $subList;
            $prop['proposition_average'] = count($subAverages) > 0 ? (float) (array_sum($subAverages) / count($subAverages)) : 0.0;
            $formattedPropositions[] = $prop;
        }

        return [
            'overall_rating' => $overallRating !== null ? (float) $overallRating : 0.0,
            'propositions'   => $formattedPropositions,
        ];
    }

    private function computeMemberRatingStatsMap(string $groupId, array $uraIds, Request $request): array
    {
        if (empty($uraIds)) {
            return [];
        }

        $obsForQuery = DB::table('observations_observation as o')
            ->where('o.group_id', $groupId)
            ->whereIn('o.observee_id', $uraIds);
        $this->applyDateFilter($obsForQuery, 'o.created', $request);

        $obsForCounts = $obsForQuery
            ->select('o.observee_id', DB::raw('COUNT(o.id) as cnt'))
            ->groupBy('o.observee_id')
            ->pluck('cnt', 'observee_id')
            ->toArray();

        $obsByQuery = DB::table('observations_observation as o')
            ->where('o.group_id', $groupId)
            ->whereIn('o.observer_id', $uraIds);
        $this->applyDateFilter($obsByQuery, 'o.created', $request);

        $obsByCounts = $obsByQuery
            ->select('o.observer_id', DB::raw('COUNT(o.id) as cnt'))
            ->groupBy('o.observer_id')
            ->pluck('cnt', 'observer_id')
            ->toArray();

        $ratingQuery = DB::table('observations_indicatorrating as ir')
            ->join('observations_evidence as e', 'ir.evidence_id', '=', 'e.id')
            ->join('observations_observation as o', 'e.observation_id', '=', 'o.id')
            ->join('observations_rating as rt', 'ir.rating_id', '=', 'rt.id')
            ->where('o.group_id', $groupId)
            ->whereIn('o.observee_id', $uraIds);
        $this->applyDateFilter($ratingQuery, 'o.created', $request);

        $ratingRows = $ratingQuery->select([
                'o.observee_id',
                DB::raw('COUNT(DISTINCT ir.indicator_id) as unique_indicators_marked'),
                DB::raw('AVG(rt.score) as avg_rating'),
            ])
            ->groupBy('o.observee_id')
            ->get()
            ->keyBy('observee_id');

        $map = [];
        foreach ($uraIds as $id) {
            $r = $ratingRows->get($id);
            $map[$id] = [
                'avg_rating'                 => ($r && $r->avg_rating !== null) ? (float) $r->avg_rating : null,
                'unique_indicators_marked'   => $r ? (int) $r->unique_indicators_marked : 0,
                'observation_count_by_user'  => (int) ($obsByCounts[$id] ?? 0),
                'observation_count_for_user' => (int) ($obsForCounts[$id] ?? 0),
            ];
        }

        return $map;
    }

    private function formatMemberRatingReport(object $m, array $groups, ?array $stat): array
    {
        $stat = $stat ?? [
            'avg_rating'                 => null,
            'unique_indicators_marked'   => 0,
            'observation_count_by_user'  => 0,
            'observation_count_for_user' => 0,
        ];

        return [
            'member' => [
                'id'      => $m->ura_id,
                'profile' => [
                    'id'     => $m->profile_id,
                    'avatar' => $m->avatar_id,
                    'user'   => [
                        'username'   => $m->username,
                        'first_name' => $m->first_name,
                        'last_name'  => $m->last_name,
                        'full_name'  => trim($m->first_name . ' ' . $m->last_name),
                        'email'      => $m->email,
                        'last_login' => $m->last_login ? Carbon::parse($m->last_login)->toJSON() : null,
                    ],
                    'created'     => $m->profile_created ? Carbon::parse($m->profile_created)->toJSON() : null,
                    'updated'     => $m->profile_updated ? Carbon::parse($m->profile_updated)->toJSON() : null,
                    'phone'       => $m->phone ?? '',
                    'designation' => $m->designation,
                    'dob'         => $m->dob,
                ],
                'groups'     => $groups,
                'role_title' => $m->role_title,
                'role_rank'  => (int) $m->role_rank,
                'role_id'    => $m->role_id,
                'account'    => $m->account_id,
            ],
            'avg_rating'                 => $stat['avg_rating'],
            'unique_indicators_marked'   => $stat['unique_indicators_marked'],
            'observation_count_by_user'  => $stat['observation_count_by_user'],
            'observation_count_for_user' => $stat['observation_count_for_user'],
        ];
    }

    private function computeAdvanceReport(string $groupId, Request $request): array
    {
        $level = $request->query('level', 'proposition');
        if (!in_array($level, ['proposition', 'subproposition', 'indicator'], true)) {
            $level = 'proposition';
        }

        $levelValues = array_values(array_filter(
            explode(',', (string) $request->query('level_value', '')),
            fn($v) => Str::isUuid(trim($v))
        ));

        $percentiles = array_values(array_filter(
            array_map('intval', explode(',', (string) $request->query('percentiles', ''))),
            fn($p) => $p >= 0 && $p <= 100
        ));

        $query = DB::table('observations_indicatorrating as ir')
            ->join('observations_evidence as e', 'ir.evidence_id', '=', 'e.id')
            ->join('observations_observation as o', 'e.observation_id', '=', 'o.id')
            ->join('rubrics_indicator as ind', 'ir.indicator_id', '=', 'ind.id')
            ->join('rubrics_subproposition as sub', 'ind.subproposition_id', '=', 'sub.id')
            ->join('rubrics_proposition as prop', 'sub.proposition_id', '=', 'prop.id')
            ->join('observations_rating as rt', 'ir.rating_id', '=', 'rt.id')
            ->where('o.group_id', $groupId)
            ->where('o.is_published', true);

        $this->applyDateFilter($query, 'o.created', $request);

        $entityCol = match ($level) {
            'indicator'      => 'ind.id',
            'subproposition' => 'sub.id',
            default          => 'prop.id',
        };
        $titleCol = match ($level) {
            'indicator'      => 'ind.title',
            'subproposition' => 'sub.title',
            default          => 'prop.title',
        };

        if (!empty($levelValues)) {
            $query->whereIn($entityCol, $levelValues);
        }

        $rawRows = $query->select([
            "{$entityCol} as entity_id",
            "{$titleCol} as entity_title",
            'o.id as observation_id',
            'rt.score as score',
        ])
            ->get();

        $grouped = [];
        foreach ($rawRows as $row) {
            $eid = $row->entity_id;
            if (!isset($grouped[$eid])) {
                $grouped[$eid] = [
                    'id'              => $eid,
                    'title'           => $row->entity_title,
                    'scores'          => [],
                    'observation_ids' => [],
                ];
            }
            $grouped[$eid]['scores'][] = (float) $row->score;
            $grouped[$eid]['observation_ids'][$row->observation_id] = true;
        }

        $results = [];
        foreach ($grouped as $item) {
            $scores = $item['scores'];
            sort($scores);
            $count = count($scores);

            $row = [
                'id'                 => $item['id'],
                'title'              => $item['title'],
                'average_score'      => $count > 0 ? round(array_sum($scores) / $count, 2) : 0.0,
                'min_score'          => $count > 0 ? $scores[0] : 0.0,
                'max_score'          => $count > 0 ? $scores[$count - 1] : 0.0,
                'ratings_count'      => $count,
                'observations_count' => count($item['observation_ids']),
            ];

            if (!empty($percentiles) && $count > 0) {
                $pctMap = [];
                foreach ($percentiles as $p) {
                    $idx = (int) floor(($p / 100) * ($count - 1));
                    $pctMap["p{$p}"] = $scores[$idx];
                }
                $row['percentiles'] = $pctMap;
            }

            $results[] = $row;
        }

        return [
            'level' => $level,
            'rows'  => $results,
        ];
    }

    private function computeAdvanceTrends(string $groupId, string $pk, Request $request): array
    {
        $level = $request->query('level', 'proposition');
        if (!in_array($level, ['proposition', 'subproposition', 'indicator'], true)) {
            $level = 'proposition';
        }

        $entityCol = match ($level) {
            'indicator'      => 'ind.id',
            'subproposition' => 'sub.id',
            default          => 'prop.id',
        };

        $query = DB::table('observations_indicatorrating as ir')
            ->join('observations_evidence as e', 'ir.evidence_id', '=', 'e.id')
            ->join('observations_observation as o', 'e.observation_id', '=', 'o.id')
            ->join('rubrics_indicator as ind', 'ir.indicator_id', '=', 'ind.id')
            ->join('rubrics_subproposition as sub', 'ind.subproposition_id', '=', 'sub.id')
            ->join('rubrics_proposition as prop', 'sub.proposition_id', '=', 'prop.id')
            ->join('observations_rating as rt', 'ir.rating_id', '=', 'rt.id')
            ->where('o.group_id', $groupId)
            ->where('o.is_published', true)
            ->where($entityCol, $pk);

        $this->applyDateFilter($query, 'o.created', $request);

        $trends = $query->select([
            DB::raw('DATE(o.created) as date'),
            DB::raw('AVG(rt.score) as average_score'),
            DB::raw('COUNT(ir.id) as ratings_count'),
            DB::raw('COUNT(DISTINCT o.id) as observations_count'),
        ])
            ->groupBy(DB::raw('DATE(o.created)'))
            ->orderBy(DB::raw('DATE(o.created)'), 'asc')
            ->get()
            ->map(fn($row) => [
                'date'               => Carbon::parse($row->date)->format('Y-m-d'),
                'average_score'      => round((float) $row->average_score, 2),
                'ratings_count'      => (int) $row->ratings_count,
                'observations_count' => (int) $row->observations_count,
            ])
            ->values()
            ->toArray();

        return [
            'level'  => $level,
            'trends' => $trends,
        ];
    }

    private function applyDateFilter($query, string $column, Request $request): void
    {
        if ($request->filled('start_date')) {
            try {
                $start = Carbon::parse($request->query('start_date'))->startOfDay();
                $query->where($column, '>=', $start);
            } catch (\Exception $e) {
                // Ignore malformed start_date
            }
        }

        if ($request->filled('end_date')) {
            try {
                $end = Carbon::parse($request->query('end_date'))->endOfDay();
                $query->where($column, '<=', $end);
            } catch (\Exception $e) {
                // Ignore malformed end_date
            }
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

    /**
     * Generate a minimal valid PDF-1.4 document without external dependencies.
     */
    private function generateSimplePdf(string $title, array $lines): string
    {
        $escape = fn(string $text): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);

        $stream = "BT\n/F1 14 Tf\n50 760 Td\n(" . $escape($title) . ") Tj\n/F1 10 Tf\n0 -24 Td\n";
        foreach (array_slice($lines, 0, 40) as $line) {
            $stream .= "(" . $escape(substr($line, 0, 95)) . ") Tj\n0 -15 Td\n";
        }
        $stream .= "ET";

        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n",
            "4 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream\nendobj\n",
            "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= $obj;
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }
}
