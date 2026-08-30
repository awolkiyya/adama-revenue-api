<?php

namespace App\Services\Storage;

use App\Jobs\OptimizeImageJob;
use Illuminate\Http\UploadedFile;
use App\Models\File;

class ImageService extends StorageService
{

    /**
     * PROFILE IMAGE UPLOAD
     */
    public function uploadProfileImage(
        UploadedFile $file,
        ?string $uploadedBy
    ): ?File {

        $uploaded = parent::upload(

            uploadedFile: $file,

            folder: 'users/avatars',

            uploadedBy: $uploadedBy,

            category: 'AVATAR',

            visibility: 'public'

        );


        if (!$uploaded) {
            return null;
        }


        /**
         * Async image optimization
         */
        // OptimizeImageJob::dispatch(
        //     $uploaded->disk,
        //     $uploaded->path
        // );


        return $uploaded->refresh();
    }




    /**
     * ORGANIZATION LOGO UPLOAD
     */
    public function uploadOrganizationLogo(
        UploadedFile $file,
        ?string $uploadedBy
    ): ?File {

        $uploaded = parent::upload(

            uploadedFile: $file,

            folder: 'organizations/logos',

            uploadedBy: $uploadedBy,

            category: 'ORGANIZATION_LOGO',

            visibility: 'public'

        );


        if (!$uploaded) {
            return null;
        }



        OptimizeImageJob::dispatch(
            $uploaded->disk,
            $uploaded->path
        );



        return $uploaded->refresh();
    }
}