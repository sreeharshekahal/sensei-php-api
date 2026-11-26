<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RubricController extends Controller
{
    /**
     * List rubrics with proposition, subproposition, and indicator counts (GET /rubrics/).
     */
    public function index(Request $request)
    {
        try {
            $propCountSub = DB::table('rubrics_proposition')
                ->select('rubric_id', DB::raw('COUNT(*) as proposition_count'))
                ->groupBy('rubric_id');

            $subpropCountSub = DB::table('rubrics_subproposition as sp')
                ->join('rubrics_proposition as p', 'sp.proposition_id', '=', 'p.id')
                ->select('p.rubric_id', DB::raw('COUNT(*) as subproposition_count'))
                ->groupBy('p.rubric_id');

            $indCountSub = DB::table('rubrics_indicator as i')
                ->join('rubrics_subproposition as sp', 'i.subproposition_id', '=', 'sp.id')
                ->join('rubrics_proposition as p', 'sp.proposition_id', '=', 'p.id')
                ->select('p.rubric_id', DB::raw('COUNT(*) as indicator_count'))
                ->groupBy('p.rubric_id');

            $query = DB::table('rubrics_rubric as r')
                ->leftJoin('observations_ratingscheme as rs', 'r.rating_scheme_id', '=', 'rs.id')
                ->leftJoinSub($propCountSub, 'pc', 'r.id', '=', 'pc.rubric_id')
                ->leftJoinSub($subpropCountSub, 'spc', 'r.id', '=', 'spc.rubric_id')
                ->leftJoinSub($indCountSub, 'ic', 'r.id', '=', 'ic.rubric_id');

            if ($request->filled('search')) {
                $search = $request->query('search');
                $query->where('r.title', 'ilike', "%{$search}%");
            }

            $ordering = $request->query('ordering', 'created');
            $orderDirection = str_starts_with($ordering, '-') ? 'desc' : 'asc';
            $orderField = ltrim($ordering, '-');

            if ($orderField === 'proposition_count') {
                $query->orderByRaw('COALESCE(pc.proposition_count, 0) ' . $orderDirection);
            } elseif ($orderField === 'subproposition_count') {
                $query->orderByRaw('COALESCE(spc.subproposition_count, 0) ' . $orderDirection);
            } elseif ($orderField === 'indicator_count') {
                $query->orderByRaw('COALESCE(ic.indicator_count, 0) ' . $orderDirection);
            } elseif (in_array($orderField, ['title', 'created', 'updated'])) {
                $query->orderBy("r.{$orderField}", $orderDirection);
            } else {
                $query->orderBy('r.created', 'asc');
            }

            $total = (clone $query)->count('r.id');

            $limit = max(1, (int) $request->query('limit', 10));
            $offset = max(0, (int) $request->query('offset', 0));
            if ($request->filled('page') && !$request->filled('offset')) {
                $page = max(1, (int) $request->query('page'));
                $offset = ($page - 1) * $limit;
            }

            $rubrics = $query->select([
                    'r.id',
                    'r.title',
                    'r.description',
                    'r.rating_scheme_id',
                    'r.account_id',
                    'r.created',
                    'r.updated',
                    'rs.title as rating_scheme_title',
                    'rs.code as rating_scheme_code',
                    'rs.is_input_required',
                    DB::raw('COALESCE(pc.proposition_count, 0) as proposition_count'),
                    DB::raw('COALESCE(spc.subproposition_count, 0) as subproposition_count'),
                    DB::raw('COALESCE(ic.indicator_count, 0) as indicator_count'),
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

            $schemeAndHost = $request->getSchemeAndHttpHost();

            return response()->json([
                'count'    => $total,
                'next'     => $next,
                'previous' => $previous,
                'results'  => $rubrics->map(fn ($r) => $this->formatRubric($r, $schemeAndHost))->values(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve rubrics.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a rubric (POST /rubrics/).
     */
    public function store(Request $request)
    {
        $ratingSchemeId = $request->input('rating_scheme_id', $request->input('rating_scheme'));
        $accountId = $request->input('account_id', $request->input('account'));

        if (!$accountId && $request->user()) {
            $accountId = DB::table('access_userroleaccount')
                ->where('user_id', $request->user()->id)
                ->value('account_id');
        }

        $payload = array_merge($request->all(), [
            'rating_scheme_id' => $ratingSchemeId,
            'account_id'       => $accountId,
        ]);

        $validator = Validator::make($payload, [
            'title'            => 'required|string|max:255',
            'description'      => 'nullable|string',
            'rating_scheme_id' => 'required|uuid|exists:observations_ratingscheme,id',
            'account_id'       => 'required|uuid|exists:access_account,id',
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

            DB::table('rubrics_rubric')->insert([
                'id'               => $id,
                'title'            => $request->input('title'),
                'description'      => $request->input('description') ?? '',
                'rating_scheme_id' => $ratingSchemeId,
                'account_id'       => $accountId,
                'created'          => $now,
                'updated'          => $now,
            ]);

            $rubric = $this->findRubricWithCounts($id);

            return response()->json($this->formatRubric($rubric, $request->getSchemeAndHttpHost()), 201);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to create rubric.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retrieve a single rubric (GET /rubrics/{id}/).
     */
    public function show(Request $request, string $id)
    {
        $id = trim($id);
        if (!Str::isUuid($id)) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        try {
            $rubric = $this->findRubricWithCounts($id);

            if (!$rubric) {
                return response()->json(['detail' => 'Not found.'], 404);
            }

            return response()->json($this->formatRubric($rubric, $request->getSchemeAndHttpHost()), 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve rubric.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update or partially update a rubric (PUT/PATCH /rubrics/{id}/).
     */
    public function update(Request $request, string $id)
    {
        $id = trim($id);
        if (!Str::isUuid($id)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Rubric ID format',
            ], 400);
        }

        $existing = DB::table('rubrics_rubric')->where('id', $id)->first();
        if (!$existing) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        $payload = $request->all();
        if (!$request->has('rating_scheme_id') && $request->has('rating_scheme')) {
            $payload['rating_scheme_id'] = $request->input('rating_scheme');
        }
        if (!$request->has('account_id') && $request->has('account')) {
            $payload['account_id'] = $request->input('account');
        }

        $isPut = $request->isMethod('put');
        $validator = Validator::make($payload, [
            'title'            => $isPut ? 'required|string|max:255' : 'sometimes|required|string|max:255',
            'description'      => 'sometimes|nullable|string',
            'rating_scheme_id' => $isPut ? 'required|uuid|exists:observations_ratingscheme,id' : 'sometimes|required|uuid|exists:observations_ratingscheme,id',
            'account_id'       => 'sometimes|required|uuid|exists:access_account,id',
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
            if (array_key_exists('rating_scheme_id', $payload)) {
                $updateData['rating_scheme_id'] = $payload['rating_scheme_id'];
            }
            if (array_key_exists('account_id', $payload)) {
                $updateData['account_id'] = $payload['account_id'];
            }

            DB::table('rubrics_rubric')->where('id', $id)->update($updateData);

            $rubric = $this->findRubricWithCounts($id);

            return response()->json($this->formatRubric($rubric, $request->getSchemeAndHttpHost()), 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to update rubric.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a rubric (DELETE /rubrics/{id}/).
     */
    public function destroy(string $id)
    {
        $id = trim($id);
        if (!Str::isUuid($id)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Rubric ID format',
            ], 400);
        }

        $existing = DB::table('rubrics_rubric')->where('id', $id)->first();
        if (!$existing) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        try {
            DB::table('rubrics_rubric')->where('id', $id)->delete();

            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to delete rubric.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Return nested rubric tree: rubric -> propositions -> subpropositions -> indicators (GET /rubrics/{id}/tree/).
     */
    public function tree(Request $request, string $id)
    {
        $id = trim($id);
        if (!Str::isUuid($id)) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        try {
            $rubric = $this->findRubricWithCounts($id);

            if (!$rubric) {
                return response()->json(['detail' => 'Not found.'], 404);
            }

            $propositions = DB::table('rubrics_proposition')
                ->where('rubric_id', $id)
                ->orderBy('created', 'asc')
                ->get();

            $propIds = $propositions->pluck('id')->toArray();

            $subpropositions = empty($propIds)
                ? collect()
                : DB::table('rubrics_subproposition')
                    ->whereIn('proposition_id', $propIds)
                    ->orderBy('created', 'asc')
                    ->get();

            $subpropIds = $subpropositions->pluck('id')->toArray();

            $indicators = empty($subpropIds)
                ? collect()
                : DB::table('rubrics_indicator')
                    ->whereIn('subproposition_id', $subpropIds)
                    ->orderBy('created', 'asc')
                    ->get();

            $indicatorIds = $indicators->pluck('id')->toArray();

            $tagsMap = [];
            if (!empty($indicatorIds)) {
                $tagRows = DB::table('rubrics_indicator_tags')
                    ->whereIn('indicator_id', $indicatorIds)
                    ->select(['indicator_id', 'tag_id'])
                    ->get();

                foreach ($tagRows as $row) {
                    $tagsMap[$row->indicator_id][] = $row->tag_id;
                }
            }

            $indicatorsBySubprop = [];
            foreach ($indicators as $ind) {
                $indicatorsBySubprop[$ind->subproposition_id][] = [
                    'id'             => $ind->id,
                    'title'          => $ind->title,
                    'description'    => $ind->description ?? '',
                    'subproposition' => $ind->subproposition_id,
                    'tags'           => $tagsMap[$ind->id] ?? [],
                ];
            }

            $subpropsByProp = [];
            foreach ($subpropositions as $sp) {
                $subpropsByProp[$sp->proposition_id][] = [
                    'id'          => $sp->id,
                    'indicators'  => $indicatorsBySubprop[$sp->id] ?? [],
                    'title'       => $sp->title,
                    'description' => $sp->description ?? '',
                    'proposition' => $sp->proposition_id,
                ];
            }

            $propTree = $propositions->map(function ($p) use ($subpropsByProp) {
                return [
                    'id'              => $p->id,
                    'subpropositions' => $subpropsByProp[$p->id] ?? [],
                    'title'           => $p->title,
                    'description'     => $p->description ?? '',
                    'rubric'          => $p->rubric_id,
                ];
            })->values();

            return response()->json([
                'id'                  => $rubric->id,
                'propositions'        => $propTree,
                'rating_scheme_title' => $rubric->rating_scheme_title,
                'title'               => $rubric->title,
                'description'         => $rubric->description ?? '',
                'account'             => $rubric->account_id,
                'rating_scheme'       => $rubric->rating_scheme_id,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve rubric tree.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    private function findRubricWithCounts(string $id): ?object
    {
        $propCountSub = DB::table('rubrics_proposition')
            ->select('rubric_id', DB::raw('COUNT(*) as proposition_count'))
            ->where('rubric_id', $id)
            ->groupBy('rubric_id');

        $subpropCountSub = DB::table('rubrics_subproposition as sp')
            ->join('rubrics_proposition as p', 'sp.proposition_id', '=', 'p.id')
            ->select('p.rubric_id', DB::raw('COUNT(*) as subproposition_count'))
            ->where('p.rubric_id', $id)
            ->groupBy('p.rubric_id');

        $indCountSub = DB::table('rubrics_indicator as i')
            ->join('rubrics_subproposition as sp', 'i.subproposition_id', '=', 'sp.id')
            ->join('rubrics_proposition as p', 'sp.proposition_id', '=', 'p.id')
            ->select('p.rubric_id', DB::raw('COUNT(*) as indicator_count'))
            ->where('p.rubric_id', $id)
            ->groupBy('p.rubric_id');

        return DB::table('rubrics_rubric as r')
            ->leftJoin('observations_ratingscheme as rs', 'r.rating_scheme_id', '=', 'rs.id')
            ->leftJoinSub($propCountSub, 'pc', 'r.id', '=', 'pc.rubric_id')
            ->leftJoinSub($subpropCountSub, 'spc', 'r.id', '=', 'spc.rubric_id')
            ->leftJoinSub($indCountSub, 'ic', 'r.id', '=', 'ic.rubric_id')
            ->where('r.id', $id)
            ->select([
                'r.id',
                'r.title',
                'r.description',
                'r.rating_scheme_id',
                'r.account_id',
                'r.created',
                'r.updated',
                'rs.title as rating_scheme_title',
                'rs.code as rating_scheme_code',
                'rs.is_input_required',
                DB::raw('COALESCE(pc.proposition_count, 0) as proposition_count'),
                DB::raw('COALESCE(spc.subproposition_count, 0) as subproposition_count'),
                DB::raw('COALESCE(ic.indicator_count, 0) as indicator_count'),
            ])
            ->first();
    }

    private function formatRubric(object $rubric, string $schemeAndHost): array
    {
        return [
            'id'                   => $rubric->id,
            'indicator_url'        => "{$schemeAndHost}/v1/indicators?rubric={$rubric->id}",
            'rating_scheme_title'  => $rubric->rating_scheme_title,
            'rating_scheme_code'   => $rubric->rating_scheme_code,
            'is_input_required'    => (bool) $rubric->is_input_required,
            'proposition_count'    => (int) ($rubric->proposition_count ?? 0),
            'subproposition_count' => (int) ($rubric->subproposition_count ?? 0),
            'indicator_count'      => (int) ($rubric->indicator_count ?? 0),
            'updated'              => $rubric->updated ? Carbon::parse($rubric->updated)->toJSON() : null,
            'title'                => $rubric->title,
            'description'          => $rubric->description ?? '',
            'account'              => $rubric->account_id,
            'rating_scheme'        => $rubric->rating_scheme_id,
        ];
    }
}
