<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ObservationStatsController extends Controller
{
    /**
     * Fetch observation statistics breakdown by proposition or subproposition.
     */
    public function breakdown(Request $request)
    {
        $request->validate([
            'observation'     => 'required|uuid',
            'proposition'     => 'nullable|uuid',
            'subproposition'  => 'nullable|uuid',
        ]);

        $observationId = $request->query('observation');
        $propositionId = $request->query('proposition');
        $subpropositionId = $request->query('subproposition');

        try {
            $observation = DB::table('observations_observation')
                ->where('id', $observationId)
                ->first();

            if (!$observation) {
                return response()->json(['detail' => 'Observation not found.'], 404);
            }

            $query = DB::table('observations_indicatorrating as ir')
                ->join('observations_evidence as e', 'ir.evidence_id', '=', 'e.id')
                ->join('observations_observation as o', 'e.observation_id', '=', 'o.id')
                ->join('rubrics_indicator as ind', 'ir.indicator_id', '=', 'ind.id')
                ->join('rubrics_subproposition as sub', 'ind.subproposition_id', '=', 'sub.id')
                ->join('rubrics_proposition as prop', 'sub.proposition_id', '=', 'prop.id')
                ->join('observations_rating as r', 'ir.rating_id', '=', 'r.id')
                ->where('o.id', $observationId)
                ->select([
                    'ind.id as indicator',
                    'ind.title as indicator__title',
                    'sub.id as indicator__subproposition',
                    'sub.title as indicator__subproposition__title',
                    'prop.id as indicator__subproposition__proposition',
                    'prop.title as indicator__subproposition__proposition__title',
                    DB::raw('AVG(r.score) as indicator_average'),
                    DB::raw('COUNT(ind.id) as indicator_frequency'),
                ])
                ->groupBy(
                    'ind.id',
                    'ind.title',
                    'sub.id',
                    'sub.title',
                    'prop.id',
                    'prop.title'
                )
                ->orderBy('prop.title')
                ->orderBy('sub.title')
                ->orderBy('ind.title');

            if ($propositionId) {
                $query->where('prop.id', $propositionId);
            }

            if ($subpropositionId) {
                $query->where('sub.id', $subpropositionId);
            }

            $rows = $query->get()->map(function ($row) {
                return [
                    'indicator'                                     => $row->indicator,
                    'indicator__title'                              => $row->indicator__title,
                    'indicator__subproposition'                     => $row->indicator__subproposition,
                    'indicator__subproposition__title'              => $row->indicator__subproposition__title,
                    'indicator__subproposition__proposition'        => $row->indicator__subproposition__proposition,
                    'indicator__subproposition__proposition__title' => $row->indicator__subproposition__proposition__title,
                    'indicator_average'                             => (float)$row->indicator_average,
                    'indicator_frequency'                           => (int)$row->indicator_frequency,
                ];
            })->toArray();

            if ($subpropositionId) {
                return response()->json($this->buildSubpropositionBreakdown($rows, $subpropositionId), 200);
            }

            if ($propositionId) {
                return response()->json($this->buildPropositionBreakdown($rows, $propositionId), 200);
            }

            return response()->json([
                'observation'  => $observationId,
                'propositions' => $this->buildStatsTree($rows),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve observation statistics.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fetch member observation statistics within a specific group.
     */
    public function groupMemberStats(string $groupId, string $uraId)
    {
        try {
            $group = DB::table('access_group')->where('id', $groupId)->first();
            if (!$group) {
                return response()->json(['detail' => 'Group not found.'], 404);
            }

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
                    'r.rank as role_rank',
                ]);

            if (is_numeric($uraId)) {
                $query->where('u.id', (int)$uraId);
            } else {
                $query->where('ura.id', $uraId);
            }

            $account = $query->first();

            if (!$account) {
                return response()->json(['detail' => 'User role account not found.'], 404);
            }

            $actualUraId = $account->user_role_account_id;

            $isMember = DB::table('access_group_users')
                ->where('group_id', $groupId)
                ->where('userroleaccount_id', $actualUraId)
                ->exists();

            if (!$isMember) {
                return response()->json(['detail' => 'User is not a member of this group.'], 404);
            }

            $groups = DB::table('access_group_users')
                ->where('userroleaccount_id', $actualUraId)
                ->pluck('group_id')
                ->toArray();

            $obsCounts = DB::table('observations_observation')
                ->where('group_id', $groupId)
                ->where('is_published', true)
                ->where(function ($q) use ($actualUraId) {
                    $q->where('observer_id', $actualUraId)
                      ->orWhere('observee_id', $actualUraId);
                })
                ->selectRaw("
                    COUNT(CASE WHEN observer_id = ? THEN 1 END) as observation_count_by_user,
                    COUNT(CASE WHEN observee_id = ? THEN 1 END) as observation_count_for_user,
                    COUNT(*) as total_observation_count
                ", [$actualUraId, $actualUraId])
                ->first();

            $learningCounts = DB::table('observations_evidence as e')
                ->join('observations_observation as o', 'e.observation_id', '=', 'o.id')
                ->where('o.group_id', $groupId)
                ->where('e.is_observers_learning', true)
                ->where('o.is_published', true)
                ->where(function ($q) use ($actualUraId) {
                    $q->where('o.observer_id', $actualUraId)
                      ->orWhere('o.observee_id', $actualUraId);
                })
                ->selectRaw("
                    COUNT(CASE WHEN o.observer_id = ? THEN 1 END) as observer_learning_count,
                    COUNT(CASE WHEN o.observee_id = ? THEN 1 END) as observee_learning_count,
                    COUNT(*) as total_learning_count
                ", [$actualUraId, $actualUraId])
                ->first();

            $overallRatingAvg = DB::table('observations_indicatorrating as ir')
                ->join('observations_evidence as e', 'ir.evidence_id', '=', 'e.id')
                ->join('observations_observation as o', 'e.observation_id', '=', 'o.id')
                ->join('observations_rating as r', 'ir.rating_id', '=', 'r.id')
                ->where('o.group_id', $groupId)
                ->where('o.observee_id', $actualUraId)
                ->where('o.is_published', true)
                ->avg('r.score');

            $rawRatings = DB::table('observations_indicatorrating as ir')
                ->join('observations_evidence as e', 'ir.evidence_id', '=', 'e.id')
                ->join('observations_observation as o', 'e.observation_id', '=', 'o.id')
                ->join('rubrics_indicator as ind', 'ir.indicator_id', '=', 'ind.id')
                ->join('rubrics_subproposition as sub', 'ind.subproposition_id', '=', 'sub.id')
                ->join('rubrics_proposition as prop', 'sub.proposition_id', '=', 'prop.id')
                ->join('observations_rating as r', 'ir.rating_id', '=', 'r.id')
                ->where('o.group_id', $groupId)
                ->where('o.observee_id', $actualUraId)
                ->where('o.is_published', true)
                ->select([
                    'ind.id as indicator',
                    'ind.title as indicator__title',
                    'sub.id as indicator__subproposition',
                    'sub.title as indicator__subproposition__title',
                    'prop.id as indicator__subproposition__proposition',
                    'prop.title as indicator__subproposition__proposition__title',
                    DB::raw('AVG(r.score) as indicator_average'),
                    DB::raw('COUNT(ind.id) as indicator_frequency'),
                ])
                ->groupBy(
                    'ind.id',
                    'ind.title',
                    'sub.id',
                    'sub.title',
                    'prop.id',
                    'prop.title'
                )
                ->orderBy('prop.title')
                ->orderBy('sub.title')
                ->orderBy('ind.title')
                ->get()
                ->map(function ($row) {
                    return [
                        'indicator'                                     => $row->indicator,
                        'indicator__title'                              => $row->indicator__title,
                        'indicator__subproposition'                     => $row->indicator__subproposition,
                        'indicator__subproposition__title'              => $row->indicator__subproposition__title,
                        'indicator__subproposition__proposition'        => $row->indicator__subproposition__proposition,
                        'indicator__subproposition__proposition__title' => $row->indicator__subproposition__proposition__title,
                        'indicator_average'                             => (float)$row->indicator_average,
                        'indicator_frequency'                           => (int)$row->indicator_frequency,
                    ];
                })
                ->toArray();

            $earliestObservation = DB::table('observations_observation')
                ->where('group_id', $groupId)
                ->where('observee_id', $actualUraId)
                ->where('is_published', true)
                ->orderBy('created', 'asc')
                ->value('created');

            $latestObservation = DB::table('observations_observation')
                ->where('group_id', $groupId)
                ->where('observee_id', $actualUraId)
                ->where('is_published', true)
                ->orderBy('created', 'desc')
                ->value('created');

            $data = [
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
                        'last_login' => $account->last_login ? Carbon::parse($account->last_login)->toJSON() : null,
                    ],
                    'created' => $account->profile_created ? Carbon::parse($account->profile_created)->toJSON() : null,
                    'updated' => $account->profile_updated ? Carbon::parse($account->profile_updated)->toJSON() : null,
                    'phone' => $account->phone,
                    'designation' => $account->designation,
                    'dob' => $account->dob ?: null,
                ],
                'groups' => $groups,
                'role_title' => $account->role_title,
                'role_rank' => (int)$account->role_rank,
                'role_id' => $account->role_id,
                'account' => $account->account_id,
                'observation_count_by_user'  => (int)($obsCounts->observation_count_by_user ?? 0),
                'observation_count_for_user' => (int)($obsCounts->observation_count_for_user ?? 0),
                'total_observation_count'    => (int)($obsCounts->total_observation_count ?? 0),
                'observer_learning_count'    => (int)($learningCounts->observer_learning_count ?? 0),
                'observee_learning_count'    => (int)($learningCounts->observee_learning_count ?? 0),
                'total_learning_count'       => (int)($learningCounts->total_learning_count ?? 0),
                'overall_rating'             => $overallRatingAvg !== null ? round((float)$overallRatingAvg, 2) : null,
                'propositions'               => $this->buildStatsTree($rawRatings),
                'group_name'                 => $group->title,
                'earliest_observation'       => $earliestObservation ? Carbon::parse($earliestObservation)->toJSON() : null,
                'latest_observation'         => $latestObservation ? Carbon::parse($latestObservation)->toJSON() : null,
            ];

            return response()->json($data, 200);

        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve group observation statistics.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fetch indicator rating chart analytics data for a member in a group.
     */
    public function indicatorRatingCharts(string $groupId, string $uraId)
    {
        try {
            $group = DB::table('access_group')->where('id', $groupId)->first();
            if (!$group) {
                return response()->json(['detail' => 'Group not found.'], 404);
            }

            $query = DB::table('access_userroleaccount as ura')
                ->join('auth_user as u', 'ura.user_id', '=', 'u.id')
                ->select('ura.id as user_role_account_id');

            if (is_numeric($uraId)) {
                $query->where('u.id', (int)$uraId);
            } else {
                $query->where('ura.id', $uraId);
            }

            $account = $query->first();

            if (!$account) {
                return response()->json(['detail' => 'User role account not found.'], 404);
            }

            $actualUraId = $account->user_role_account_id;

            $isMember = DB::table('access_group_users')
                ->where('group_id', $groupId)
                ->where('userroleaccount_id', $actualUraId)
                ->exists();

            if (!$isMember) {
                return response()->json(['detail' => 'User is not a member of this group.'], 404);
            }

            $stats = DB::table('observations_indicatorrating as ir')
                ->join('observations_evidence as e', 'ir.evidence_id', '=', 'e.id')
                ->join('observations_observation as o', 'e.observation_id', '=', 'o.id')
                ->join('observations_rating as r', 'ir.rating_id', '=', 'r.id')
                ->where('o.group_id', $groupId)
                ->where('o.observee_id', $actualUraId)
                ->where('o.is_published', true)
                ->select([
                    DB::raw('DATE(o.created) as date'),
                    DB::raw('AVG(r.score) as avg_rating'),
                    DB::raw('COUNT(ir.indicator_id) as indicators_count'),
                ])
                ->groupBy(DB::raw('DATE(o.created)'))
                ->orderBy(DB::raw('DATE(o.created)'), 'asc')
                ->get()
                ->map(function ($row) {
                    return [
                        'date'             => Carbon::parse($row->date)->format('Y-m-d'),
                        'avg_rating'       => (float)($row->avg_rating ?? 0.0),
                        'indicators_count' => (int)$row->indicators_count,
                    ];
                })
                ->toArray();

            return response()->json([
                'indicator_rating_stats' => $stats,
            ], 200, [], JSON_PRESERVE_ZERO_FRACTION);

        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve indicator rating chart data.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generates or returns PDF report link for group member stats.
     */
    public function groupStatsPdfReport(Request $request)
    {
        $request->validate([
            'ura'   => 'required',
            'group' => 'required|uuid|exists:access_group,id',
        ]);

        $groupId = $request->input('group');
        $uraParam = $request->input('ura');

        try {
            $group = DB::table('access_group')->where('id', $groupId)->first();
            if (!$group) {
                return response()->json(['detail' => 'Group not found.'], 404);
            }

            $query = DB::table('access_userroleaccount as ura')
                ->join('auth_user as u', 'ura.user_id', '=', 'u.id')
                ->select('ura.id as user_role_account_id');

            if (is_numeric($uraParam)) {
                $query->where('u.id', (int)$uraParam);
            } else {
                $query->where('ura.id', $uraParam);
            }

            $account = $query->first();
            if (!$account) {
                return response()->json(['detail' => 'User role account not found.'], 404);
            }

            $uraId = $account->user_role_account_id;
            $filename = "group_stats_report_{$groupId}_{$uraId}.pdf";
            try {
                $downloadUrl = Storage::disk('s3')->url("reports/pdf/{$filename}");
            } catch (\Exception $s3Ex) {
                $downloadUrl = url("storage/reports/pdf/{$filename}");
            }

            return response()->json([
                'pdf_link' => $downloadUrl,
                'url'      => $downloadUrl,
                'filename' => $filename,
                'message'  => 'Report generated successfully.',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to generate group stats PDF report.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Build nested propositions -> subpropositions -> indicators tree.
     */
    private function buildStatsTree(array $rows): array
    {
        $propositions = [];

        foreach ($rows as $row) {
            $propId = $row['indicator__subproposition__proposition'];
            $propTitle = $row['indicator__subproposition__proposition__title'];
            $subId = $row['indicator__subproposition'];
            $subTitle = $row['indicator__subproposition__title'];
            $indId = $row['indicator'];
            $indTitle = $row['indicator__title'];
            $indAvg = $row['indicator_average'];
            $indFreq = $row['indicator_frequency'] ?? null;

            if (!isset($propositions[$propId])) {
                $propositions[$propId] = [
                    'proposition_id'    => $propId,
                    'proposition_title' => $propTitle,
                    'subpropositions'   => [],
                ];
            }

            if (!isset($propositions[$propId]['subpropositions'][$subId])) {
                $propositions[$propId]['subpropositions'][$subId] = [
                    'subproposition_id'    => $subId,
                    'subproposition_title' => $subTitle,
                    'indicators'           => [],
                ];
            }

            $indicatorData = [
                'indicator_id'      => $indId,
                'indicator_title'   => $indTitle,
                'indicator_average' => round($indAvg, 2),
            ];

            if ($indFreq !== null) {
                $indicatorData['indicator_frequency'] = $indFreq;
            }

            $propositions[$propId]['subpropositions'][$subId]['indicators'][] = $indicatorData;
        }

        $result = [];
        foreach ($propositions as $prop) {
            $subList = [];
            $propAvgList = [];

            foreach ($prop['subpropositions'] as $sub) {
                $subAvgs = array_column($sub['indicators'], 'indicator_average');
                $subAvg = count($subAvgs) > 0 ? array_sum($subAvgs) / count($subAvgs) : 0;
                $sub['subproposition_average'] = round($subAvg, 2);
                $propAvgList[] = $subAvg;
                $subList[] = $sub;
            }

            $propAvg = count($propAvgList) > 0 ? array_sum($propAvgList) / count($propAvgList) : 0;
            $result[] = [
                'proposition_id'      => $prop['proposition_id'],
                'proposition_title'   => $prop['proposition_title'],
                'proposition_average' => round($propAvg, 2),
                'subpropositions'     => $subList,
            ];
        }

        return $result;
    }

    /**
     * Build single proposition breakdown.
     */
    private function buildPropositionBreakdown(array $rows, string $propositionId): array
    {
        $tree = $this->buildStatsTree($rows);
        foreach ($tree as $prop) {
            if ($prop['proposition_id'] === $propositionId) {
                return $prop;
            }
        }

        $propModel = DB::table('rubrics_proposition')->where('id', $propositionId)->first();
        return [
            'proposition_id'      => $propositionId,
            'proposition_title'   => $propModel->title ?? '',
            'proposition_average' => 0,
            'subpropositions'     => [],
        ];
    }

    /**
     * Build single subproposition breakdown.
     */
    private function buildSubpropositionBreakdown(array $rows, string $subpropositionId): array
    {
        $indicators = [];
        $subTitle = '';
        $subAvgs = [];

        foreach ($rows as $row) {
            if ($row['indicator__subproposition'] === $subpropositionId) {
                $subTitle = $row['indicator__subproposition__title'];
                $subAvgs[] = $row['indicator_average'];
                $indicators[] = [
                    'indicator_id'        => $row['indicator'],
                    'indicator_title'     => $row['indicator__title'],
                    'indicator_average'   => round($row['indicator_average'], 2),
                    'indicator_frequency' => $row['indicator_frequency'],
                ];
            }
        }

        if (empty($subTitle)) {
            $subModel = DB::table('rubrics_subproposition')->where('id', $subpropositionId)->first();
            $subTitle = $subModel->title ?? '';
        }

        $subAvg = count($subAvgs) > 0 ? array_sum($subAvgs) / count($subAvgs) : 0;

        return [
            'subproposition_id'      => $subpropositionId,
            'subproposition_title'   => $subTitle,
            'subproposition_average' => round($subAvg, 2),
            'indicators'             => $indicators,
        ];
    }
}
