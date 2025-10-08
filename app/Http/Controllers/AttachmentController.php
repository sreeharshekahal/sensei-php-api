<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Attachment;
use Illuminate\Support\Str;

class AttachmentController extends Controller
{
    public function store(Request $request)
    {
        // Validate: ensure at least one file is uploaded
        $request->validate([
            'path.*' => 'required|file', // 'path' is an array of files
        ]);

        $attachments = [];

        // Loop through all uploaded files
        $files = $request->file('path');
        foreach ($files as $file) {

            $folder = 'global/images/' . date('Y/m/d');

            // Generate unique filename
            $filename = Str::uuid() . '-attach_' . Str::random(8) . '.' . $file->getClientOriginalExtension();

            // Store the file
            $path = $file->storeAs($folder, $filename, 'public');

            // Create attachment record
            $attachment = new Attachment();
            $attachment->path = $path;                  // Stored path
            $attachment->name = basename($path);       // Just the filename
            $attachment->attachment_type = 'global';
            $attachment->setFileMetadata($file);       // Fill type, size, hash
            $attachment->save();

            $response[] = [
                'name' => $attachment->name,
                'type' => $attachment->file_type,
                'uri'  => url('storage/' . $attachment->path),
            ];
        }
        // Return all saved attachments
        return response()->json($response, 201);
    }
}
