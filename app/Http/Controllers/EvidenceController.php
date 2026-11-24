<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EvidenceController extends Controller
{
    /**
     * List evidences with multi-filter query and pagination.
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

            $query = DB::table('observations_evidence as e')
                ->join('observations_observation as o', 'e.observation_id', '=', 'o.id')
                ->join('access_userroleaccount as observer_ura', 'o.observer_id', '=', 'observer_ura.id')
                ->join('access_userroleaccount as observee_ura', 'o.observee_id', '=', 'observee_ura.id')
                ->join('access_role as observee_role', 'observee_ura.role_id', '=', 'observee_role.id');

            if ((int)$ura->rank > 1) {
                $query->where(function ($q) use ($user, $ura) {
                    $q->where('observer_ura.user_id', $user->id)
                        ->orWhere('observee_ura.user_id', $user->id)
                        ->orWhere('observee_role.rank', '>=', $ura->rank);
                });
            }

            if ($request->filled('is_observers_learning')) {
                $isLearning = filter_var($request->query('is_observers_learning'), FILTER_VALIDATE_BOOLEAN);
                $query->where('e.is_observers_learning', $isLearning);
            }

            if ($request->filled('observer') && Str::isUuid($request->query('observer'))) {
                $query->where('o.observer_id', $request->query('observer'));
            }

            if ($request->filled('observee') && Str::isUuid($request->query('observee'))) {
                $query->where('o.observee_id', $request->query('observee'));
            }

            if ($request->filled('of') && Str::isUuid($request->query('of'))) {
                $of = $request->query('of');
                $query->where(function ($q) use ($of) {
                    $q->where('o.observer_id', $of)
                      ->orWhere('o.observee_id', $of);
                });
            }

            if ($request->filled('observation') && Str::isUuid($request->query('observation'))) {
                $query->where('e.observation_id', $request->query('observation'));
            }

            if ($request->filled('rubric') && Str::isUuid($request->query('rubric'))) {
                $query->where('o.rubric_id', $request->query('rubric'));
            }

            if ($request->filled('indicator') && Str::isUuid($request->query('indicator'))) {
                $indicatorId = $request->query('indicator');
                $query->whereExists(function ($sub) use ($indicatorId) {
                    $sub->select(DB::raw(1))
                        ->from('observations_indicatorrating as ir')
                        ->whereColumn('ir.evidence_id', 'e.id')
                        ->where('ir.indicator_id', $indicatorId);
                });
            }

            $total = $query->distinct()->count('e.id');

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

            if ($orderField === 'total_indicators') {
                $query->leftJoin('observations_indicatorrating as ir_cnt', 'ir_cnt.evidence_id', '=', 'e.id')
                    ->groupBy('e.id', 'e.title', 'e.description', 'e.is_observers_learning', 'e.observation_id', 'e.created', 'e.updated')
                    ->orderBy(DB::raw('COUNT(ir_cnt.id)'), $orderDirection);
            } else {
                $orderField = in_array($orderField, ['created', 'updated', 'title']) ? $orderField : 'created';
                $query->orderBy("e.{$orderField}", $orderDirection);
            }

            $evidences = $query->select([
                'e.id',
                'e.title',
                'e.description',
                'e.is_observers_learning',
                'e.observation_id',
                'e.created',
                'e.updated',
            ])
                ->distinct()
                ->offset($offset)
                ->limit($limit)
                ->get();

            $results = $this->formatEvidenceList($evidences, false);

            return response()->json([
                'count'    => $total,
                'next'     => $this->buildNextUrl($request, $offset, $limit, $total),
                'previous' => $this->buildPrevUrl($request, $offset, $limit),
                'results'  => $results,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve evidences.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fetch paginated list of evidences for a specific observation.
     */
    public function observationEvidences(Request $request, string $observationId)
    {
        try {
            if (empty($observationId) || !Str::isUuid($observationId)) {
                return response()->json(['detail' => 'Observation not found.'], 404);
            }

            $observation = DB::table('observations_observation')->where('id', $observationId)->first();
            if (!$observation) {
                return response()->json(['detail' => 'Observation not found.'], 404);
            }

            $query = DB::table('observations_evidence as e')
                ->where('e.observation_id', $observationId);

            if ($request->filled('is_observers_learning')) {
                $isLearning = filter_var($request->query('is_observers_learning'), FILTER_VALIDATE_BOOLEAN);
                $query->where('e.is_observers_learning', $isLearning);
            }

            $total = $query->count();

            $limit = (int)$request->input('limit', $request->input('page_size', 10));
            $limit = max(1, min($limit, 1000));

            $offset = (int)$request->input('offset', 0);
            if ($request->filled('page') && !$request->has('offset')) {
                $page = max(1, (int)$request->input('page'));
                $offset = ($page - 1) * $limit;
            }

            $evidences = $query->select([
                'e.id',
                'e.title',
                'e.description',
                'e.is_observers_learning',
                'e.observation_id',
                'e.created',
                'e.updated',
            ])
                ->orderBy('e.created', 'desc')
                ->offset($offset)
                ->limit($limit)
                ->get();

            $results = $this->formatEvidenceList($evidences, true);

            return response()->json([
                'count'    => $total,
                'next'     => $this->buildNextUrl($request, $offset, $limit, $total),
                'previous' => $this->buildPrevUrl($request, $offset, $limit),
                'results'  => $results,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve observation evidences.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create evidence card attached to observation, including indicator ratings & attachment IDs.
     */
    public function storeObservationEvidence(Request $request, string $observationId)
    {
        if (empty($observationId) || !Str::isUuid($observationId)) {
            return response()->json(['detail' => 'Observation not found.'], 404);
        }
        $request->validate([
            'title'                 => 'required|string',
            'description'           => 'nullable|string',
            'is_observers_learning' => 'nullable|boolean',
            'attachments'           => 'nullable|array',
            'attachments.*'         => 'uuid',
            'indicators'            => 'required|array',
            'indicators.*.indicator' => 'required|uuid',
            'indicators.*.rating'   => 'required|uuid',
        ]);

        $indicatorsData = $request->input('indicators', []);
        if (empty($indicatorsData)) {
            return response()->json([
                'indicators' => ['Atleast one Indicator is required to create an evidence.']
            ], 400);
        }

        try {
            $observation = DB::table('observations_observation as o')
                ->join('rubrics_rubric as rub', 'o.rubric_id', '=', 'rub.id')
                ->where('o.id', $observationId)
                ->select('o.*', 'rub.id as rubric_id', 'rub.rating_scheme_id')
                ->first();

            if (!$observation) {
                return response()->json(['detail' => 'Observation not found.'], 404);
            }

            $rubricIndicators = DB::table('rubrics_indicator as ind')
                ->join('rubrics_subproposition as sub', 'ind.subproposition_id', '=', 'sub.id')
                ->join('rubrics_proposition as prop', 'sub.proposition_id', '=', 'prop.id')
                ->where('prop.rubric_id', $observation->rubric_id)
                ->pluck('ind.title', 'ind.id')
                ->toArray();

            $schemeRatings = DB::table('observations_rating')
                ->where('rating_scheme_id', $observation->rating_scheme_id)
                ->pluck('score', 'id')
                ->toArray();

            foreach ($indicatorsData as $item) {
                $indId = $item['indicator'];
                if (!isset($rubricIndicators[$indId])) {
                    return response()->json([
                        'detail' => "{$indId} {$indId} doesnt belongs to rubric for which this observation was created"
                    ], 400);
                }

                $ratingId = $item['rating'];
                if (!isset($schemeRatings[$ratingId])) {
                    return response()->json([
                        'detail' => "{$ratingId} doesnt belongs to rubric"
                    ], 400);
                }
            }

            $evidenceId = (string) Str::uuid();
            $now = Carbon::now();

            DB::beginTransaction();

            DB::table('observations_evidence')->insert([
                'id'                    => $evidenceId,
                'title'                 => $request->input('title'),
                'description'           => $request->input('description', ''),
                'observation_id'        => $observationId,
                'is_observers_learning' => $request->boolean('is_observers_learning', false),
                'created'               => $now,
                'updated'               => $now,
            ]);

            $attachments = $request->input('attachments', []);
            if (!empty($attachments)) {
                $attachInserts = [];
                foreach ($attachments as $attId) {
                    $attachInserts[] = [
                        'evidence_id'   => $evidenceId,
                        'attachment_id' => $attId,
                    ];
                }
                DB::table('observations_evidence_attachments')->insert($attachInserts);
            }

            $indicatorInserts = [];
            foreach ($indicatorsData as $item) {
                $indicatorInserts[] = [
                    'evidence_id'  => $evidenceId,
                    'indicator_id' => $item['indicator'],
                    'rating_id'    => $item['rating'],
                ];
            }
            DB::table('observations_indicatorrating')->insert($indicatorInserts);

            DB::commit();

            $createdEvidence = DB::table('observations_evidence')->where('id', $evidenceId)->first();
            $formatted = $this->formatEvidenceList(collect([$createdEvidence]), true);

            return response()->json($formatted[0] ?? [], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error'   => 'Failed to create evidence.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    private function formatEvidenceList($evidences, bool $includeObservationDetails): array
    {
        if ($evidences->isEmpty()) {
            return [];
        }

        $evidenceIds = $evidences->pluck('id')->toArray();
        $observationIds = $evidences->pluck('observation_id')->unique()->toArray();

        $obsMap = [];
        if ($includeObservationDetails && !empty($observationIds)) {
            $obsRecords = DB::table('observations_observation as o')
                ->leftJoin('rubrics_rubric as rub', 'o.rubric_id', '=', 'rub.id')
                ->leftJoin('observations_ratingscheme as rs', 'rub.rating_scheme_id', '=', 'rs.id')
                ->whereIn('o.id', $observationIds)
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
                ])
                ->get();

            foreach ($obsRecords as $rec) {
                $obsMap[$rec->id] = [
                    'id'                  => $rec->id,
                    'rating_scheme_title' => $rec->rating_scheme_title,
                    'rating_scheme_code'  => $rec->rating_scheme_code,
                    'created'             => $rec->created ? Carbon::parse($rec->created)->toJSON() : null,
                    'updated'             => $rec->updated ? Carbon::parse($rec->updated)->toJSON() : null,
                    'title'               => $rec->title,
                    'description'         => $rec->description ?? null,
                    'is_published'        => (bool)$rec->is_published,
                    'rubric'              => $rec->rubric_id,
                    'group'               => $rec->group_id,
                    'observer'            => $rec->observer_id,
                    'observee'            => $rec->observee_id,
                ];
            }
        }

        $attachmentsMap = [];
        $attachRecords = DB::table('observations_evidence_attachments as ea')
            ->join('common_attachment as a', 'ea.attachment_id', '=', 'a.id')
            ->whereIn('ea.evidence_id', $evidenceIds)
            ->select([
                'ea.evidence_id',
                'a.id',
                'a.name',
                'a.path',
                'a.file_type',
                'a.size',
                'a.attachment_type',
                'a.created',
                'a.updated',
            ])
            ->get();

        foreach ($attachRecords as $att) {
            $path = $att->path;
            if ($path && !str_starts_with($path, 'http://') && !str_starts_with($path, 'https://')) {
                try {
                    $path = Storage::disk('s3')->url($path);
                } catch (\Exception $e) {
                    $path = url('storage/' . $path);
                }
            }

            $attachmentsMap[$att->evidence_id][] = [
                'id'              => $att->id,
                'name'            => $att->name,
                'path'            => $path,
                'file_type'       => $att->file_type,
                'size'            => $att->size !== null ? (int)$att->size : null,
                'attachment_type' => $att->attachment_type,
                'created'         => $att->created ? Carbon::parse($att->created)->toJSON() : null,
                'updated'         => $att->updated ? Carbon::parse($att->updated)->toJSON() : null,
            ];
        }

        $ratingsMap = [];
        $ratingRecords = DB::table('observations_indicatorrating as ir')
            ->join('rubrics_indicator as ind', 'ir.indicator_id', '=', 'ind.id')
            ->join('observations_rating as r', 'ir.rating_id', '=', 'r.id')
            ->whereIn('ir.evidence_id', $evidenceIds)
            ->select([
                'ir.id',
                'ir.evidence_id',
                'ir.indicator_id as indicator',
                'ind.title as indicator_title',
                'ir.rating_id as rating',
                'r.score as rating_score',
                'r.title as rating_title',
            ])
            ->get();

        foreach ($ratingRecords as $rat) {
            $ratingsMap[$rat->evidence_id][] = [
                'id'              => (int)$rat->id,
                'rating_score'    => (string)$rat->rating_score,
                'rating_title'    => (string)$rat->rating_title,
                'indicator_title' => $rat->indicator_title,
                'indicator'       => $rat->indicator,
                'rating'          => $rat->rating,
            ];
        }

        return $evidences->map(function ($e) use ($includeObservationDetails, $obsMap, $attachmentsMap, $ratingsMap) {
            $indicatorRatings = $ratingsMap[$e->id] ?? [];
            $attachmentsDetails = $attachmentsMap[$e->id] ?? [];

            if ($includeObservationDetails) {
                return [
                    'id'                    => $e->id,
                    'observation_details'   => $obsMap[$e->observation_id] ?? null,
                    'attachments_details'   => $attachmentsDetails,
                    'indicator_ratings'     => $indicatorRatings,
                    'indicators_count'      => count($indicatorRatings),
                    'created'               => $e->created ? Carbon::parse($e->created)->toJSON() : null,
                    'updated'               => $e->updated ? Carbon::parse($e->updated)->toJSON() : null,
                    'title'                 => $e->title,
                    'description'           => $e->description ?? '',
                    'is_observers_learning' => (bool)$e->is_observers_learning,
                ];
            }

            return [
                'id'                    => $e->id,
                'observation'           => $e->observation_id,
                'attachments_details'   => $attachmentsDetails,
                'indicator_ratings'     => $indicatorRatings,
                'indicators_count'      => count($indicatorRatings),
                'created'               => $e->created ? Carbon::parse($e->created)->toJSON() : null,
                'updated'               => $e->updated ? Carbon::parse($e->updated)->toJSON() : null,
                'title'                 => $e->title,
                'description'           => $e->description ?? '',
                'is_observers_learning' => (bool)$e->is_observers_learning,
            ];
        })->toArray();
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
