<?php

namespace App\Modules\CashReconciliation\Services;

use App\Models\CashReconciliation;
use App\Models\File;
use App\Services\Storage\StorageService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CashReconciliationEvidenceService
{
    protected const STORAGE_FOLDER = 'cash-reconciliations/evidence';

    protected const FILE_VISIBILITY = 'private';

    public function __construct(
        protected StorageService $storageService
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Upload and Attach Evidence
    |--------------------------------------------------------------------------
    */

    /**
     * Upload and attach evidence files to a reconciliation.
     *
     * The caller must execute this operation inside a database transaction.
     *
     * @param array<int, UploadedFile> $files
     */
    public function attach(
        CashReconciliation $reconciliation,
        array $files
    ): void {
        $this->assertPersistedReconciliation($reconciliation);

        if ($files === []) {
            return;
        }

        $uploadedFiles = [];

        try {
            foreach ($files as $uploadedFile) {
                if (! $uploadedFile instanceof UploadedFile) {
                    throw ValidationException::withMessages([
                        'evidence' =>
                            'One or more evidence files are invalid.',
                    ]);
                }

                /*
                 * Upload the physical file and create its File record.
                 */
                $file = $this->storageService->upload(
                    uploadedFile: $uploadedFile,
                    folder: self::STORAGE_FOLDER,
                    uploadedBy: (string) $reconciliation->collector_id,
                    category: CashReconciliation::FILE_COLLECTION_EVIDENCE,
                    visibility: self::FILE_VISIBILITY,
                );

                $uploadedFiles[] = $file;

                /*
                 * Explicitly set the evidence collection.
                 *
                 * StorageService may default the collection to
                 * "attachment", even when category is supplied.
                 */
                $file->collection = CashReconciliation::FILE_COLLECTION_EVIDENCE;
                $file->save();

                /*
                 * Attach the File record to the reconciliation.
                 */
                $this->storageService->attachToModel(
                    $file,
                    $reconciliation
                );
            }

            /*
             * Refresh the relationship so subsequent serialization
             * includes newly uploaded evidence.
             */
            $reconciliation->unsetRelation('evidence');
            $reconciliation->load('evidence');

        } catch (Throwable $exception) {
            /*
             * Clean up physical files.
             *
             * Database changes are rolled back by the caller's
             * transaction. Do not delete File records here.
             */
            foreach ($uploadedFiles as $uploadedFile) {
                try {
                    $this->storageService->deletePhysical(
                        $uploadedFile
                    );
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Remove Evidence
    |--------------------------------------------------------------------------
    */

    /**
     * Detach selected evidence files from this reconciliation.
     *
     * Physical files and File registry records are preserved.
     *
     * @param array<int, string> $evidenceIds UUID file IDs
     */
    public function remove(
        CashReconciliation $reconciliation,
        array $evidenceIds
    ): void {
        $this->assertPersistedReconciliation($reconciliation);

        if ($evidenceIds === []) {
            return;
        }

        $normalizedIds = [];

        foreach ($evidenceIds as $evidenceId) {
            $id = trim((string) $evidenceId);

            if (! Str::isUuid($id)) {
                throw ValidationException::withMessages([
                    'remove_evidence_ids' =>
                        'One or more evidence file IDs are invalid.',
                ]);
            }

            $normalizedIds[] = $id;
        }

        $normalizedIds = array_values(
            array_unique($normalizedIds)
        );

        /*
         * Retrieve only evidence belonging to this reconciliation.
         * Lock selected records to prevent concurrent modifications.
         */
        $files = $this->evidenceQuery($reconciliation)
            ->whereIn('id', $normalizedIds)
            ->lockForUpdate()
            ->get();

        if ($files->count() !== count($normalizedIds)) {
            throw ValidationException::withMessages([
                'remove_evidence_ids' =>
                    'One or more selected evidence files do not belong to this reconciliation.',
            ]);
        }

        /*
         * Detach without deleting physical files or File records.
         */
        foreach ($files as $file) {
            $this->storageService->detach($file);
        }

        /*
         * Ensure the in-memory relationship does not contain
         * files that have just been detached.
         */
        $reconciliation->unsetRelation('evidence');
        $reconciliation->load('evidence');
    }

    /*
    |--------------------------------------------------------------------------
    | Retrieve Evidence
    |--------------------------------------------------------------------------
    */

    /**
     * Retrieve all evidence attached to a reconciliation.
     *
     * @return Collection<int, File>
     */
    public function getEvidence(
        CashReconciliation $reconciliation
    ): Collection {
        $this->assertPersistedReconciliation($reconciliation);

        return $this->evidenceQuery($reconciliation)
            ->orderBy('created_at')
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Evidence Query
    |--------------------------------------------------------------------------
    */

    /**
     * Build the reconciliation evidence relationship query.
     *
     * Filters using the model's evidence collection relationship.
     */
    protected function evidenceQuery(
        CashReconciliation $reconciliation
    ): MorphMany {
        return $reconciliation->evidence();
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    /**
     * Ensure the reconciliation has been persisted.
     */
    protected function assertPersistedReconciliation(
        CashReconciliation $reconciliation
    ): void {
        if (! $reconciliation->exists) {
            throw ValidationException::withMessages([
                'reconciliation' =>
                    'Evidence cannot be managed for an unsaved reconciliation.',
            ]);
        }

        if (! Str::isUuid((string) $reconciliation->getKey())) {
            throw ValidationException::withMessages([
                'reconciliation' =>
                    'The reconciliation identifier is invalid.',
            ]);
        }
    }
}
