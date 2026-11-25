<?php

namespace App\Http\Controllers;

use App\Models\Proposition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PropositionController extends Controller
{
    /**
     * GET /propositions/ -> Get proposition list
     */
    public function index(Request $request)
    {
        $query = Proposition::query();

        // Filter by rubric if provided
        if ($request->has('rubric')) {
            $query->where('rubric_id', $request->input('rubric'));
        }

        // Apply title search if provided
        if ($request->has('search')) {
            $query->where('title', 'LIKE', '%' . $request->input('search') . '%');
        }

        // Default ordering by created asc (Python Meta: ordering = ('created',))
        $query->orderBy('created', 'asc');

        $total = $query->count();

        // Limit/offset pagination (default limit 10, offset 0)
        $limit = max(1, (int) $request->query('limit', 10));
        $offset = max(0, (int) $request->query('offset', 0));
        if ($request->filled('page') && !$request->filled('offset')) {
            $page = max(1, (int) $request->query('page'));
            $offset = ($page - 1) * $limit;
        }

        $propositions = $query->offset($offset)->limit($limit)->get();

        $next = ($offset + $limit < $total)
            ? $request->url() . '?' . http_build_query(array_merge($request->query(), ['limit' => $limit, 'offset' => $offset + $limit]))
            : null;
        $previous = ($offset > 0)
            ? $request->url() . '?' . http_build_query(array_merge($request->query(), ['limit' => $limit, 'offset' => max(0, $offset - $limit)]))
            : null;

        return response()->json([
            'count'    => $total,
            'next'     => $next,
            'previous' => $previous,
            'results'  => $propositions->map(fn ($prop) => $this->formatProposition($prop))->values(),
        ], 200);
    }

    /**
     * GET /propositions/{id}/ -> Retrieve single proposition
     */
    public function show($id)
    {
        if (!Str::isUuid($id)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Proposition ID format',
            ], 400);
        }

        $proposition = Proposition::find($id);

        if (!$proposition) {
            return response()->json([
                'success' => false,
                'message' => 'Proposition not found',
            ], 404);
        }

        return response()->json($this->formatProposition($proposition), 200);
    }

    /**
     * POST /propositions/ -> Create proposition
     */
    public function store(Request $request)
    {
        $rubricId = $request->input('rubric_id', $request->input('rubric'));
        $payload = array_merge($request->all(), ['rubric_id' => $rubricId]);

        $validator = Validator::make($payload, [
            'title'       => 'required|string',
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

        $proposition = new Proposition();
        $proposition->id = (string) Str::uuid();
        $proposition->title = $request->input('title');
        $proposition->description = $request->input('description');
        $proposition->rubric_id = $rubricId;
        $proposition->save();

        return response()->json($this->formatProposition($proposition), 201);
    }

    /**
     * PUT/PATCH /propositions/{id}/ -> Update or partial update proposition
     */
    public function update(Request $request, $id)
    {
        if (!Str::isUuid($id)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Proposition ID format',
            ], 400);
        }

        $proposition = Proposition::find($id);

        if (!$proposition) {
            return response()->json([
                'success' => false,
                'message' => 'Proposition not found',
            ], 404);
        }

        $payload = $request->all();
        if (!$request->has('rubric_id') && $request->has('rubric')) {
            $payload['rubric_id'] = $request->input('rubric');
        }

        $isPut = $request->isMethod('put');
        $validator = Validator::make($payload, [
            'title'       => $isPut ? 'required|string' : 'sometimes|required|string',
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

        if (array_key_exists('title', $payload)) {
            $proposition->title = $payload['title'];
        }
        if (array_key_exists('description', $payload)) {
            $proposition->description = $payload['description'];
        }
        if (array_key_exists('rubric_id', $payload)) {
            $proposition->rubric_id = $payload['rubric_id'];
        }

        $proposition->save();

        return response()->json($this->formatProposition($proposition), 200);
    }

    /**
     * DELETE /propositions/{id}/ -> Delete proposition
     */
    public function destroy($id)
    {
        if (!Str::isUuid($id)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Proposition ID format',
            ], 400);
        }

        $proposition = Proposition::find($id);

        if (!$proposition) {
            return response()->json([
                'success' => false,
                'message' => 'Proposition not found',
            ], 404);
        }

        $proposition->delete();

        return response()->json(null, 204);
    }

    /**
     * Format a Proposition model to match PropositionSerializer output.
     */
    private function formatProposition(Proposition $prop): array
    {
        return [
            'id'          => $prop->id,
            'title'       => $prop->title,
            'description' => $prop->description,
            'rubric'      => $prop->rubric_id,
            'created'     => $prop->created ? $prop->created->toIso8601String() : null,
            'updated'     => $prop->updated ? $prop->updated->toIso8601String() : null,
        ];
    }
}
