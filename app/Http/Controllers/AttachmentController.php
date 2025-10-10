<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Attachment;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;


class AttachmentController extends Controller
{
    public function store(Request $request)
    {
        // Validate: ensure one file is uploaded
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

        // Define folder path (you can change 'global/images' if needed)
        $folder = 'global/uploads/' . date('Y/m/d');

        // Generate unique filename
        $filename = Str::uuid() . '-attach_' . Str::random(8) . '.' . $file->getClientOriginalExtension();

        // Store the file in 'public' disk
        $path = $file->storeAs($folder, $filename, 'public');

        // Save attachment in DB
        $attachment = new Attachment();
        $attachment->path = $path;
        $attachment->name = basename($path);
        $attachment->attachment_type = 'global';
        $attachment->setFileMetadata($file); // your custom function
        $attachment->save();

        // Return single response
        return response()->json([
            'id'   => $attachment->id,
            'name' => $attachment->name,
            'type' => $attachment->file_type,
            'path' => asset($attachment->path),
        ], 201);
    }
}
