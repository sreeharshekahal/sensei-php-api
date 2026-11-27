<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RatingSchemeController extends Controller
{
    /**
     * List rating schemes (GET /rating-schemes/).
     */
    public function index(Request $request)
    {
        try {
            $query = DB::table('observations_ratingscheme');

            if ($request->filled('search')) {
                $search = $request->query('search');
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'ilike', "%{$search}%")
                      ->orWhere('code', 'ilike', "%{$search}%");
                });
            }

            $ordering = $request->query('ordering', 'title');
            $orderDirection = str_starts_with($ordering, '-') ? 'desc' : 'asc';
            $orderField = ltrim($ordering, '-');
            $orderField = in_array($orderField, ['title', 'code', 'created', 'updated']) ? $orderField : 'title';
            $query->orderBy($orderField, $orderDirection);

            $total = $query->count('id');

            $limit = (int) $request->query('limit', $request->query('page_size', 1000));
            $limit = max(1, min($limit, 1000));

            $offset = max(0, (int) $request->query('offset', 0));
            if ($request->filled('page') && !$request->filled('offset')) {
                $page = max(1, (int) $request->query('page'));
                $offset = ($page - 1) * $limit;
            }

            $schemes = $query->select([
                    'id',
                    'title',
                    'description',
                    'code',
                    'is_input_required',
                    'created',
                    'updated',
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
                'results'  => $schemes->map(fn ($s) => $this->formatScheme($s))->values(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve rating schemes.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a rating scheme (POST /rating-schemes/).
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title'             => 'required|string|max:255',
            'description'       => 'nullable|string',
            'code'              => 'required|string|max:100',
            'is_input_required' => 'nullable|boolean',
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

            DB::table('observations_ratingscheme')->insert([
                'id'                => $id,
                'title'             => $request->input('title'),
                'description'       => $request->input('description') ?? '',
                'code'              => $request->input('code'),
                'is_input_required' => $request->boolean('is_input_required', false),
                'created'           => $now,
                'updated'           => $now,
            ]);

            $scheme = DB::table('observations_ratingscheme')->where('id', $id)->first();

            return response()->json($this->formatScheme($scheme), 201);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to create rating scheme.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retrieve a single rating scheme (GET /rating-schemes/{id}/).
     */
    public function show(string $id)
    {
        $id = trim($id);
        if (!Str::isUuid($id)) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        try {
            $scheme = DB::table('observations_ratingscheme')
                ->where('id', $id)
                ->select(['id', 'title', 'description', 'code', 'is_input_required', 'created', 'updated'])
                ->first();

            if (!$scheme) {
                return response()->json(['detail' => 'Not found.'], 404);
            }

            return response()->json($this->formatScheme($scheme), 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve rating scheme.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update or partially update a rating scheme (PUT/PATCH /rating-schemes/{id}/).
     */
    public function update(Request $request, string $id)
    {
        $id = trim($id);
        if (!Str::isUuid($id)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Rating Scheme ID format',
            ], 400);
        }

        $existing = DB::table('observations_ratingscheme')->where('id', $id)->first();
        if (!$existing) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        $isPut = $request->isMethod('put');
        $validator = Validator::make($request->all(), [
            'title'             => $isPut ? 'required|string|max:255' : 'sometimes|required|string|max:255',
            'description'       => 'sometimes|nullable|string',
            'code'              => $isPut ? 'required|string|max:100' : 'sometimes|required|string|max:100',
            'is_input_required' => 'sometimes|boolean',
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

            if ($request->has('title')) {
                $updateData['title'] = $request->input('title');
            }
            if ($request->has('description')) {
                $updateData['description'] = $request->input('description') ?? '';
            }
            if ($request->has('code')) {
                $updateData['code'] = $request->input('code');
            }
            if ($request->has('is_input_required')) {
                $updateData['is_input_required'] = $request->boolean('is_input_required');
            }

            DB::table('observations_ratingscheme')->where('id', $id)->update($updateData);

            $scheme = DB::table('observations_ratingscheme')->where('id', $id)->first();

            return response()->json($this->formatScheme($scheme), 200);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to update rating scheme.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a rating scheme (DELETE /rating-schemes/{id}/).
     */
    public function destroy(string $id)
    {
        $id = trim($id);
        if (!Str::isUuid($id)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Rating Scheme ID format',
            ], 400);
        }

        $existing = DB::table('observations_ratingscheme')->where('id', $id)->first();
        if (!$existing) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        try {
            DB::table('observations_ratingscheme')->where('id', $id)->delete();

            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to delete rating scheme.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Format a rating scheme row to match RatingSchemeSerializer output.
     */
    private function formatScheme(object $scheme): array
    {
        return [
            'id'                => $scheme->id,
            'created'           => $scheme->created ? Carbon::parse($scheme->created)->toJSON() : null,
            'updated'           => $scheme->updated ? Carbon::parse($scheme->updated)->toJSON() : null,
            'title'             => $scheme->title,
            'description'       => $scheme->description ?? '',
            'code'              => $scheme->code,
            'is_input_required' => (bool) $scheme->is_input_required,
        ];
    }
}
