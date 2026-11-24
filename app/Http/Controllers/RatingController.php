<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RatingController extends Controller
{
    /**
     * List ratings with optional filtering by rubric / rating scheme and pagination.
     */
    public function index(Request $request)
    {
        try {
            $query = DB::table('observations_rating as r')
                ->leftJoin('observations_ratingscheme as rs', 'r.rating_scheme_id', '=', 'rs.id');

            if ($request->filled('rating_scheme__rubrics') && Str::isUuid($request->query('rating_scheme__rubrics'))) {
                $rubricId = $request->query('rating_scheme__rubrics');
                $query->whereIn('r.rating_scheme_id', function ($sub) use ($rubricId) {
                    $sub->select('rating_scheme_id')
                        ->from('rubrics_rubric')
                        ->where('id', $rubricId);
                });
            } elseif ($request->filled('rubric') && Str::isUuid($request->query('rubric'))) {
                $rubricId = $request->query('rubric');
                $query->whereIn('r.rating_scheme_id', function ($sub) use ($rubricId) {
                    $sub->select('rating_scheme_id')
                        ->from('rubrics_rubric')
                        ->where('id', $rubricId);
                });
            }

            if ($request->filled('rating_scheme') && Str::isUuid($request->query('rating_scheme'))) {
                $query->where('r.rating_scheme_id', $request->query('rating_scheme'));
            }

            if ($request->filled('search')) {
                $search = $request->query('search');
                $query->where('r.title', 'ilike', "%{$search}%");
            }

            $ordering = $request->query('ordering');
            if ($ordering) {
                $orderDirection = str_starts_with($ordering, '-') ? 'desc' : 'asc';
                $orderField = ltrim($ordering, '-');
                $allowed = ['score', 'title', 'created'];
                if (in_array($orderField, $allowed)) {
                    $query->orderBy("r.{$orderField}", $orderDirection);
                } else {
                    $query->orderBy('r.rating_scheme_id', 'asc')->orderBy('r.score', 'asc');
                }
            } else {
                $query->orderBy('r.rating_scheme_id', 'asc')
                      ->orderBy('r.score', 'asc');
            }

            $total = $query->count('r.id');

            $limit = (int)$request->query('limit', $request->query('page_size', 1000));
            $limit = max(1, min($limit, 1000));

            $offset = (int)$request->query('offset', 0);
            if ($request->filled('page') && !$request->filled('offset')) {
                $page = max(1, (int)$request->query('page'));
                $offset = ($page - 1) * $limit;
            }

            $ratings = $query->select([
                'r.id',
                'r.title',
                'r.description',
                'r.score',
                'r.rating_scheme_id',
                'rs.title as rating_scheme_title',
                'rs.code as rating_scheme_code',
                'rs.is_input_required',
            ])
            ->offset($offset)
            ->limit($limit)
            ->get();

            $schemeIds = $ratings->pluck('rating_scheme_id')->unique()->filter()->toArray();
            $schemeRubricsMap = [];
            if (!empty($schemeIds)) {
                $rubricRows = DB::table('rubrics_rubric')
                    ->whereIn('rating_scheme_id', $schemeIds)
                    ->select('id', 'rating_scheme_id')
                    ->get();

                foreach ($rubricRows as $row) {
                    $schemeRubricsMap[$row->rating_scheme_id][] = $row->id;
                }
            }

            $results = $ratings->map(function ($r) use ($schemeRubricsMap) {
                return [
                    'id'                  => $r->id,
                    'title'               => $r->title,
                    'description'         => $r->description ?? '',
                    'score'               => (int)$r->score,
                    'rubrics'             => $schemeRubricsMap[$r->rating_scheme_id] ?? [],
                    'rating_scheme_title' => $r->rating_scheme_title ?? '',
                    'rating_scheme_code'  => $r->rating_scheme_code ?? '',
                    'is_input_required'   => (bool)$r->is_input_required,
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
                'error'   => 'Failed to retrieve ratings.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retrieve a specific rating by ID.
     */
    public function show(string $id)
    {
        try {
            $r = DB::table('observations_rating as r')
                ->leftJoin('observations_ratingscheme as rs', 'r.rating_scheme_id', '=', 'rs.id')
                ->where('r.id', $id)
                ->select([
                    'r.id',
                    'r.title',
                    'r.description',
                    'r.score',
                    'r.rating_scheme_id',
                    'rs.title as rating_scheme_title',
                    'rs.code as rating_scheme_code',
                    'rs.is_input_required',
                ])
                ->first();

            if (!$r) {
                return response()->json(['detail' => 'Not found.'], 404);
            }

            $rubrics = DB::table('rubrics_rubric')
                ->where('rating_scheme_id', $r->rating_scheme_id)
                ->pluck('id')
                ->toArray();

            return response()->json([
                'id'                  => $r->id,
                'title'               => $r->title,
                'description'         => $r->description ?? '',
                'score'               => (int)$r->score,
                'rubrics'             => $rubrics,
                'rating_scheme_title' => $r->rating_scheme_title ?? '',
                'rating_scheme_code'  => $r->rating_scheme_code ?? '',
                'is_input_required'   => (bool)$r->is_input_required,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve rating.',
                'details' => $e->getMessage(),
            ], 500);
        }
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
