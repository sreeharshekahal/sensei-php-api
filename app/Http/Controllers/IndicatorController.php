<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IndicatorController extends Controller
{
    /**
     * List indicators with rubric, tags filtering, and pagination.
     */
    public function index(Request $request)
    {
        try {
            $query = DB::table('rubrics_indicator as ind')
                ->join('rubrics_subproposition as sub', 'ind.subproposition_id', '=', 'sub.id')
                ->join('rubrics_proposition as prop', 'sub.proposition_id', '=', 'prop.id')
                ->join('rubrics_rubric as rub', 'prop.rubric_id', '=', 'rub.id')
                ->leftJoin('observations_ratingscheme as rs', 'rub.rating_scheme_id', '=', 'rs.id');

            if ($request->filled('rubric') && Str::isUuid($request->query('rubric'))) {
                $query->where('rub.id', $request->query('rubric'));
            }

            if ($request->filled('tags')) {
                $tagIds = array_filter(explode(',', $request->query('tags')));
                if (!empty($tagIds)) {
                    $query->whereExists(function ($subQ) use ($tagIds) {
                        $subQ->select(DB::raw(1))
                            ->from('rubrics_indicator_tags as it')
                            ->whereColumn('it.indicator_id', 'ind.id')
                            ->whereIn('it.tag_id', $tagIds);
                    });
                }
            }

            if ($request->filled('search')) {
                $search = $request->query('search');
                $query->where(function ($q) use ($search) {
                    $q->where('ind.title', 'ilike', "%{$search}%")
                      ->orWhere('sub.title', 'ilike', "%{$search}%")
                      ->orWhere('prop.title', 'ilike', "%{$search}%");
                });
            }

            $total = $query->distinct()->count('ind.id');

            $limit = (int)$request->query('limit', $request->query('page_size', 1000));
            $limit = max(1, min($limit, 1000));

            $offset = (int)$request->query('offset', 0);
            if ($request->filled('page') && !$request->filled('offset')) {
                $page = max(1, (int)$request->query('page'));
                $offset = ($page - 1) * $limit;
            }

            $ordering = $request->query('ordering', 'created');
            $orderDirection = str_starts_with($ordering, '-') ? 'desc' : 'asc';
            $orderField = ltrim($ordering, '-');
            $orderField = in_array($orderField, ['created', 'updated', 'title']) ? $orderField : 'created';

            $indicators = $query->select([
                    'ind.id',
                    'ind.title',
                    'ind.description',
                    'ind.subproposition_id as subproposition',
                    'ind.created',
                    'ind.updated',
                    'rub.id as rubric_id',
                    'rub.title as rubric_title',
                    'rub.description as rubric_description',
                    'rub.rating_scheme_id as rubric_rating_scheme_id',
                    'rub.account_id as rubric_account_id',
                    'rs.title as rating_scheme_title',
                    'rs.code as rating_scheme_code',
                    'rs.is_input_required',
                ])
                ->distinct()
                ->orderBy("ind.{$orderField}", $orderDirection)
                ->offset($offset)
                ->limit($limit)
                ->get();

            $results = $this->formatIndicators($indicators, $request);

            return response()->json([
                'count'    => $total,
                'next'     => $this->buildNextUrl($request, $offset, $limit, $total),
                'previous' => $this->buildPrevUrl($request, $offset, $limit),
                'results'  => $results,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve indicators.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    private function formatIndicators($indicators, Request $request): array
    {
        if ($indicators->isEmpty()) {
            return [];
        }

        $indicatorIds = $indicators->pluck('id')->toArray();
        $rubricIds = $indicators->pluck('rubric_id')->unique()->toArray();
        $baseUrl = $request->root();

        $rubricCounts = [];
        if (!empty($rubricIds)) {
            $propCounts = DB::table('rubrics_proposition')
                ->whereIn('rubric_id', $rubricIds)
                ->select('rubric_id', DB::raw('COUNT(DISTINCT id) as cnt'))
                ->groupBy('rubric_id')
                ->pluck('cnt', 'rubric_id');

            $subCounts = DB::table('rubrics_subproposition as s')
                ->join('rubrics_proposition as p', 's.proposition_id', '=', 'p.id')
                ->whereIn('p.rubric_id', $rubricIds)
                ->select('p.rubric_id', DB::raw('COUNT(DISTINCT s.id) as cnt'))
                ->groupBy('p.rubric_id')
                ->pluck('cnt', 'p.rubric_id');

            $indCounts = DB::table('rubrics_indicator as i')
                ->join('rubrics_subproposition as s', 'i.subproposition_id', '=', 's.id')
                ->join('rubrics_proposition as p', 's.proposition_id', '=', 'p.id')
                ->whereIn('p.rubric_id', $rubricIds)
                ->select('p.rubric_id', DB::raw('COUNT(DISTINCT i.id) as cnt'))
                ->groupBy('p.rubric_id')
                ->pluck('cnt', 'p.rubric_id');

            foreach ($rubricIds as $rId) {
                $rubricCounts[$rId] = [
                    'proposition_count'    => (int)($propCounts[$rId] ?? 0),
                    'subproposition_count' => (int)($subCounts[$rId] ?? 0),
                    'indicator_count'      => (int)($indCounts[$rId] ?? 0),
                ];
            }
        }

        $tagCounts = DB::table('rubrics_indicator_tags')
            ->select('tag_id', DB::raw('COUNT(DISTINCT indicator_id) as cnt'))
            ->groupBy('tag_id')
            ->pluck('cnt', 'tag_id');

        $tagsRecords = DB::table('rubrics_indicator_tags as it')
            ->join('rubrics_tag as t', 'it.tag_id', '=', 't.id')
            ->leftJoin('rubrics_rubric as r', 't.rubric_id', '=', 'r.id')
            ->whereIn('it.indicator_id', $indicatorIds)
            ->select([
                'it.indicator_id',
                't.id',
                't.title',
                't.description',
                't.rubric_id',
                'r.title as rubric_title',
            ])
            ->get();

        $indicatorTagsMap = [];
        foreach ($tagsRecords as $tag) {
            $indicatorTagsMap[$tag->indicator_id][] = [
                'id'               => $tag->id,
                'title'            => $tag->title,
                'description'      => $tag->description ?? '',
                'rubric'           => $tag->rubric_id,
                'rubric_title'     => $tag->rubric_title,
                'indicators_count' => (int)($tagCounts[$tag->id] ?? 0),
            ];
        }

        return $indicators->map(function ($ind) use ($rubricCounts, $indicatorTagsMap, $baseUrl) {
            $rId = $ind->rubric_id;
            $counts = $rubricCounts[$rId] ?? ['proposition_count' => 0, 'subproposition_count' => 0, 'indicator_count' => 0];

            $rubricObj = [
                'id'                   => $ind->rubric_id,
                'title'                => $ind->rubric_title,
                'description'          => $ind->rubric_description ?? '',
                'rating_scheme'        => $ind->rubric_rating_scheme_id,
                'account'              => $ind->rubric_account_id,
                'rating_scheme_title'  => $ind->rating_scheme_title,
                'rating_scheme_code'   => $ind->rating_scheme_code,
                'is_input_required'    => (bool)$ind->is_input_required,
                'proposition_count'    => $counts['proposition_count'],
                'subproposition_count' => $counts['subproposition_count'],
                'indicator_count'      => $counts['indicator_count'],
                'indicator_url'        => "{$baseUrl}/api/v1/indicators/?rubric={$ind->rubric_id}",
            ];

            return [
                'id'             => $ind->id,
                'title'          => $ind->title,
                'description'    => $ind->description ?? '',
                'subproposition' => $ind->subproposition,
                'created'        => $ind->created ? Carbon::parse($ind->created)->toJSON() : null,
                'updated'        => $ind->updated ? Carbon::parse($ind->updated)->toJSON() : null,
                'tags'           => $indicatorTagsMap[$ind->id] ?? [],
                'rubric'         => $rubricObj,
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
            return $request->fullUrlWithQuery(['offset' => max(0, $offset - $limit), 'limit' => $limit]);
        }
        return null;
    }
}
