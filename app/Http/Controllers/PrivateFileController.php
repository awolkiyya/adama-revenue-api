<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class PrivateFileController extends Controller
{
    public function show(File $file)
    {
        Log::info('Serving file request', [
            'id' => $file->id,
            'uuid' => $file->uuid,
            'disk' => $file->disk,
            'path' => $file->path,
        ]);

        $disk = $file->disk;

        if (!Storage::disk($disk)->exists($file->path)) {
            Log::error('File not found', [
                'disk' => $disk,
                'path' => $file->path,
            ]);

            abort(404, 'File not found');
        }

        return response()->file(
            Storage::disk($disk)->path($file->path),
            [
                'Content-Type' =>
                    $file->mime_type ??
                    'application/octet-stream',

                'Content-Disposition' =>
                    'inline; filename="' .
                    $file->original_name .
                    '"',
            ]
        );
    }
}