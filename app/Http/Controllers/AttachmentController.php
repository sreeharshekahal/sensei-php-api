<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Attachment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;


class AttachmentController extends Controller
{
    public function store(Request $request)
    {

        // 1️⃣ Validate file
        $validator = Validator::make($request->all(), [
            'path' => 'required|file|mimetypes:image/*,audio/*,video/*',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $file = $request->file('path');
        $folder = 'global/uploads/' . date('Y/m/d');
        $extension = $file->getClientOriginalExtension() ?: $file->extension();
        $filename = Str::uuid() . '-attach_' . Str::random(8) . '.' . $extension;

        try {
            // 4️⃣ Upload to S3
            $path = Storage::disk('s3')->putFileAs($folder, $file, $filename);

            if (!$path) {
                return response()->json(['success' => false, 'message' => 'Failed to upload file to S3'], 500);
            }

            // 5️⃣ Save in DB
            $attachment = new Attachment();
            $attachment->path = $path; // This is S3 key
            $attachment->name = $filename;
            $attachment->attachment_type = 'global';
            $attachment->setFileMetadata($file);
            $attachment->save();

            // 6️⃣ Return response
            return response()->json([
                'id'   => $attachment->id,
                'name' => $attachment->name,
                'type' => $attachment->file_type,
                'path' => Storage::disk('s3')->url($path), // full URL
            ], 201);
        } catch (\Exception $e) {
            // Catch AWS SDK errors
            return response()->json([
                'success' => false,
                'message' => 'S3 upload failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retrieve a single attachment by UUID and resolve its S3 URL.
     */
    public function show(string $id)
    {
        $id = trim($id);
        if (!Str::isUuid($id)) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        $att = DB::table('common_attachment')
            ->where('id', $id)
            ->select([
                'id',
                'name',
                'path',
                'file_type',
                'size',
                'attachment_type',
                'hash_value',
                'created',
                'updated',
            ])
            ->first();

        if (!$att) {
            return response()->json(['detail' => 'Not found.'], 404);
        }

        $path = $att->path;
        if ($path && !str_starts_with($path, 'http://') && !str_starts_with($path, 'https://')) {
            try {
                $path = Storage::disk('s3')->url($path);
            } catch (\Exception $e) {
                $path = url('storage/' . $path);
            }
        }

        return response()->json([
            'id'              => $att->id,
            'created'         => $att->created ? Carbon::parse($att->created)->toJSON() : null,
            'updated'         => $att->updated ? Carbon::parse($att->updated)->toJSON() : null,
            'attachment_type' => $att->attachment_type,
            'path'            => $path,
            'file_type'       => $att->file_type,
            'size'            => $att->size !== null ? (int) $att->size : null,
            'hash_value'      => $att->hash_value ?? null,
            'name'            => $att->name,
        ], 200);
    }
}
