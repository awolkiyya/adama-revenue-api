<?php

namespace App\Services\Storage;

use App\Jobs\GenerateVideoProcessingJob;
use App\Models\File;
use Exception;

class MediaService
{
    public function __construct(
        protected StorageService $storageService
    ) {}

    /**
     * Upload video and dispatch async processing job
     */
    public function uploadVideo($file, int $userId): ?File
    {
        try {

            /**
             * STEP 1: Store file immediately (fast operation)
             */
            $storedFile = $this->storageService->upload(
                uploadedFile: $file,
                folder: 'media/videos',
                uploadedBy: $userId,
                category: 'video',
                visibility: 'private'
            );

            if (!$storedFile) {
                return null;
            }

            /**
             * STEP 2: Dispatch heavy processing asynchronously
             * (transcoding, thumbnails, metadata extraction, etc.)
             */
            GenerateVideoProcessingJob::dispatch($storedFile->id)
                ->onQueue('media');

            return $storedFile;

        } catch (Exception $e) {

            report($e);

            return null;
        }
    }

    /**
     * Optional: future extension for audio uploads
     */
    public function uploadAudio($file, int $userId): ?File
    {
        try {

            $storedFile = $this->storageService->upload(
                uploadedFile: $file,
                folder: 'media/audio',
                uploadedBy: $userId,
                category: 'audio',
                visibility: 'private'
            );

            if (!$storedFile) {
                return null;
            }

            // Future audio processing job (waveform, duration, etc.)
            // GenerateAudioProcessingJob::dispatch($storedFile->id);

            return $storedFile;

        } catch (Exception $e) {

            report($e);

            return null;
        }
    }
}