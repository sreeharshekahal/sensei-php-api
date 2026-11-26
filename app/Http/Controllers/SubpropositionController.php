<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SubpropositionController extends Controller
{
    /**
     * List subpropositions with limit/offset pagination (GET /subpropositions/).
     */
    public function index(Request $request)
    {
        try {
            $query = DB::table('rubrics_subproposition as sp')
                ->leftJoin('rubrics_proposition as p', 'sp.proposition_id', '=', 'p.id');

            if ($request->filled('proposition') && Str::isUuid($request->query('proposition'))) {
                $query->where('sp.proposition_id', $request->query('proposition'));
            }

            if ($request->filled('rubric') && Str::isUuid($request->query('rubric'))) {
                $query->where('p.rubric_id', $request->query('rubric'));
            }

            if ($request->filled('search')) {
                $search = $request->query('search');
                $query->where('sp.title', 'ilike', "%{$search}%");
            }

            $query->orderBy('sp.created', 'asc');

            $total = (clone $query)->count('sp.id');

            $limit = max(1, (int) $request->query('limit', 10));
            $offset = max(0, (int) $request->query('offset', 0));
            if ($request->filled('page') && !$request->filled('offset')) {
                $page = max(1, (int) $request->query('page'));
                $offset = ($page - 1) * $limit;
            }

            $subpropositions = $query->select([
                    'sp.id',
                    'sp.title',
                    'sp.description',
                    'sp.proposition_id',
                    'sp.created',
                    'sp.updated',
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

            return response()->json([
                'count'    => $total,
                'next'     => $next,
                'previous' => $previous,
                'results'  => $subpropositions->map(fn ($sp) => $this->formatSubproposition($sp))->values(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve subpropositions.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a subproposition (POST /subpropositions/).
     */
    public function store(Request $request)
    {
        $propositionId = $request->input('proposition_id', $request->input('proposition'));
        $payload = array_merge($request->all(), ['proposition_id' => $propositionId]);

        $validator = Validator::make($payload, [
            'title'          => 'required|string|max:255',
            'description'    => 'nullable|string',
            'proposition_id' => 'required|uuid|exists:rubrics_proposition,id',
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

            DB::table('rubrics_subproposition')->insert([
                'id'             => $id,
                'title'          => $request->input('title'),
                'description'    => $request->input('description') ?? '',
                'proposition_id' => $propositionId,
                'created'        => $now,
                'updated'        => $now,
            ]);

            $subproposition = DB::table('rubrics_subproposition')->where('id', $id)->first();

            return response()->json($this->formatSubproposition($subproposition), 201);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to create subproposition.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retrieve a single subproposition with detail fields (GET /subpropositions/{id}/).
     */
    public function show(string $id)
    {
        $id = trim($id);
        if (!Str::isUuid($id)) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        try {
            $sp = DB::table('rubrics_subproposition as sp')
                ->leftJoin('rubrics_proposition as p', 'sp.proposition_id', '=', 'p.id')
                ->where('sp.id', $id)
                ->select([
                    'sp.id',
                    'sp.title',
                    'sp.description',
                    'sp.proposition_id',
                    'sp.created',
                    'sp.updated',
                    'p.title as proposition_title',
                    'p.description as proposition_description',
                    'p.rubric_id',
                ])
                ->first();

            if (!$sp) {
                return response()->json(['detail' => 'Not found.'], 404);
            }

            $indicators = DB::table('rubrics_indicator')
                ->where('subproposition_id', $id)
                ->orderBy('created', 'asc')
                ->get()
                ->map(fn ($ind) => [
                    'id'             => $ind->id,
                    'title'          => $ind->title,
                    'description'    => $ind->description ?? '',
                    'subproposition' => $ind->subproposition_id,
                    'created'        => $ind->created ? Carbon::parse($ind->created)->toJSON() : null,
                    'updated'        => $ind->updated ? Carbon::parse($ind->updated)->toJSON() : null,
                ])
                ->values();

            $result = $this->formatSubproposition($sp);
            $result['rubric'] = $sp->rubric_id;
            $result['proposition_details'] = [
                'id'          => $sp->proposition_id,
                'title'       => $sp->proposition_title,
                'description' => $sp->proposition_description ?? '',
                'rubric'      => $sp->rubric_id,
            ];
            $result['indicators'] = $indicators;

            return response()->json($result, 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve subproposition.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update or partially update a subproposition (PUT/PATCH /subpropositions/{id}/).
     */
    public function update(Request $request, string $id)
    {
        $id = trim($id);
        if (!Str::isUuid($id)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Subproposition ID format',
            ], 400);
        }

        $existing = DB::table('rubrics_subproposition')->where('id', $id)->first();
        if (!$existing) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        $payload = $request->all();
        if (!$request->has('proposition_id') && $request->has('proposition')) {
            $payload['proposition_id'] = $request->input('proposition');
        }

        $isPut = $request->isMethod('put');
        $validator = Validator::make($payload, [
            'title'          => $isPut ? 'required|string|max:255' : 'sometimes|required|string|max:255',
            'description'    => 'sometimes|nullable|string',
            'proposition_id' => $isPut ? 'required|uuid|exists:rubrics_proposition,id' : 'sometimes|required|uuid|exists:rubrics_proposition,id',
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
            if (array_key_exists('proposition_id', $payload)) {
                $updateData['proposition_id'] = $payload['proposition_id'];
            }

            DB::table('rubrics_subproposition')->where('id', $id)->update($updateData);

            $subproposition = DB::table('rubrics_subproposition')->where('id', $id)->first();

            return response()->json($this->formatSubproposition($subproposition), 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to update subproposition.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a subproposition (DELETE /subpropositions/{id}/).
     */
    public function destroy(string $id)
    {
        $id = trim($id);
        if (!Str::isUuid($id)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Subproposition ID format',
            ], 400);
        }

        $existing = DB::table('rubrics_subproposition')->where('id', $id)->first();
        if (!$existing) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        try {
            DB::table('rubrics_subproposition')->where('id', $id)->delete();

            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to delete subproposition.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    private function formatSubproposition(object $sp): array
    {
        return [
            'id'          => $sp->id,
            'title'       => $sp->title,
            'description' => $sp->description ?? '',
            'proposition' => $sp->proposition_id,
            'created'     => $sp->created ? Carbon::parse($sp->created)->toJSON() : null,
            'updated'     => $sp->updated ? Carbon::parse($sp->updated)->toJSON() : null,
        ];
    }
}
