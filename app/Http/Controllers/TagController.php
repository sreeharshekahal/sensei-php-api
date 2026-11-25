<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TagController extends Controller
{
    /**
     * List tags with rubric filtering, search, and pagination.
     */
    public function index(Request $request)
    {
        try {
            $query = DB::table('rubrics_tag as t')
                ->leftJoin('rubrics_rubric as r', 't.rubric_id', '=', 'r.id');

            if ($request->filled('rubric') && Str::isUuid($request->query('rubric'))) {
                $query->where('t.rubric_id', $request->query('rubric'));
            }

            if ($request->filled('search')) {
                $search = $request->query('search');
                $query->where('t.title', 'ilike', "%{$search}%");
            }

            $ordering = $request->query('ordering', '-created');
            $orderDirection = str_starts_with($ordering, '-') ? 'desc' : 'asc';
            $orderField = ltrim($ordering, '-');
            $orderField = in_array($orderField, ['title', 'created']) ? $orderField : 'created';
            $query->orderBy("t.{$orderField}", $orderDirection);

            $total = $query->count('t.id');

            $limit = (int)$request->query('limit', $request->query('page_size', 1000));
            $limit = max(1, min($limit, 1000));

            $offset = (int)$request->query('offset', 0);
            if ($request->filled('page') && !$request->filled('offset')) {
                $page = max(1, (int)$request->query('page'));
                $offset = ($page - 1) * $limit;
            }

            $tags = $query->select([
                't.id',
                't.title',
                't.description',
                't.rubric_id as rubric',
                'r.title as rubric_title',
            ])
            ->offset($offset)
            ->limit($limit)
            ->get();

            $tagIds = $tags->pluck('id')->toArray();
            $indicatorCounts = [];
            if (!empty($tagIds)) {
                $indicatorCounts = DB::table('rubrics_indicator_tags')
                    ->whereIn('tag_id', $tagIds)
                    ->select('tag_id', DB::raw('COUNT(DISTINCT indicator_id) as cnt'))
                    ->groupBy('tag_id')
                    ->pluck('cnt', 'tag_id')
                    ->toArray();
            }

            $results = $tags->map(function ($t) use ($indicatorCounts) {
                return [
                    'id'               => $t->id,
                    'title'            => $t->title,
                    'description'      => $t->description ?? '',
                    'rubric'           => $t->rubric,
                    'rubric_title'     => $t->rubric_title,
                    'indicators_count' => (int)($indicatorCounts[$t->id] ?? 0),
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
                'error'   => 'Failed to retrieve tags.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retrieve a specific tag by ID.
     */
    public function show(string $id)
    {
        try {
            $tag = DB::table('rubrics_tag as t')
                ->leftJoin('rubrics_rubric as r', 't.rubric_id', '=', 'r.id')
                ->where('t.id', $id)
                ->select([
                    't.id',
                    't.title',
                    't.description',
                    't.rubric_id as rubric',
                    'r.title as rubric_title',
                ])
                ->first();

            if (!$tag) {
                return response()->json(['detail' => 'Not found.'], 404);
            }

            $indicatorCount = (int) DB::table('rubrics_indicator_tags')
                ->where('tag_id', $tag->id)
                ->count('indicator_id');

            return response()->json([
                'id'               => $tag->id,
                'title'            => $tag->title,
                'description'      => $tag->description ?? '',
                'rubric'           => $tag->rubric,
                'rubric_title'     => $tag->rubric_title,
                'indicators_count' => $indicatorCount,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve tag.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new tag (POST /tags/).
     */
    public function store(Request $request)
    {
        $rubricId = $request->input('rubric_id', $request->input('rubric'));
        $payload = array_merge($request->all(), ['rubric_id' => $rubricId]);

        $validator = Validator::make($payload, [
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'rubric_id'   => 'required|uuid|exists:rubrics_rubric,id',
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

            DB::table('rubrics_tag')->insert([
                'id'          => $id,
                'title'       => $request->input('title'),
                'description' => $request->input('description') ?? '',
                'rubric_id'   => $rubricId,
                'created'     => $now,
                'updated'     => $now,
            ]);

            $rubricTitle = DB::table('rubrics_rubric')->where('id', $rubricId)->value('title');

            return response()->json([
                'id'               => $id,
                'title'            => $request->input('title'),
                'description'      => $request->input('description') ?? '',
                'rubric'           => $rubricId,
                'rubric_title'     => $rubricTitle,
                'indicators_count' => 0,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to create tag.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update or partially update a tag (PUT/PATCH /tags/{id}/).
     */
    public function update(Request $request, string $id)
    {
        $id = trim($id);
        if (!Str::isUuid($id)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Tag ID format',
            ], 400);
        }

        $existing = DB::table('rubrics_tag')->where('id', $id)->first();
        if (!$existing) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        $payload = $request->all();
        if (!$request->has('rubric_id') && $request->has('rubric')) {
            $payload['rubric_id'] = $request->input('rubric');
        }

        $isPut = $request->isMethod('put');
        $validator = Validator::make($payload, [
            'title'       => $isPut ? 'required|string|max:255' : 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string',
            'rubric_id'   => $isPut ? 'required|uuid|exists:rubrics_rubric,id' : 'sometimes|required|uuid|exists:rubrics_rubric,id',
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
            if (array_key_exists('rubric_id', $payload)) {
                $updateData['rubric_id'] = $payload['rubric_id'];
            }

            DB::table('rubrics_tag')->where('id', $id)->update($updateData);

            return $this->show($id);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to update tag.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a tag (DELETE /tags/{id}/).
     */
    public function destroy(string $id)
    {
        $id = trim($id);
        if (!Str::isUuid($id)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Tag ID format',
            ], 400);
        }

        $existing = DB::table('rubrics_tag')->where('id', $id)->first();
        if (!$existing) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        try {
            DB::transaction(function () use ($id) {
                DB::table('rubrics_indicator_tags')->where('tag_id', $id)->delete();
                DB::table('rubrics_tag')->where('id', $id)->delete();
            });

            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to delete tag.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk-delete tags (POST /tags/bulk_delete/).
     */
    public function bulkDelete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tags'   => 'required|array|min:1',
            'tags.*' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 400);
        }

        try {
            $tagIds = $request->input('tags', []);

            DB::transaction(function () use ($tagIds) {
                DB::table('rubrics_indicator_tags')->whereIn('tag_id', $tagIds)->delete();
                DB::table('rubrics_tag')->whereIn('id', $tagIds)->delete();
            });

            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to bulk-delete tags.',
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
