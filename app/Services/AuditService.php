<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuditService
{

    /**
     * Create audit log manually
     *
     * Example:
     * $auditService->log(
     *     'UPDATE',
     *     'TARIFF',
     *     $tariff,
     *     $old,
     *     $new
     * );
     */
    public function log(
        string $action,
        string $module,
        ?Model $model = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null
    ): AuditLog {


        return AuditLog::create([

            /**
             * Who performed action
             */
            'user_id' => Auth::id(),



            /**
             * Action
             *
             * CREATE
             * UPDATE
             * DELETE
             * LOGIN
             * LOGOUT
             * APPROVE
             * REJECT
             */
            'action' => strtoupper($action),



            /**
             * Business module
             *
             * USER
             * REVENUE
             * TARIFF
             * CITIZEN
             */
            'module' => strtoupper($module),



            /**
             * Affected table
             */
            'table_name' => $model?->getTable(),



            /**
             * Record UUID
             */
            'record_id' => $model?->id,



            /**
             * Previous data
             */
            'old_values' => $oldValues,



            /**
             * New data
             */
            'new_values' => $newValues,



            /**
             * Security information
             */
            'ip_address' => request()->ip(),


            'user_agent' => request()->userAgent(),



            /**
             * Human readable message
             */
            'description' => $description,

        ]);

    }





    /**
     * Create audit from model changes
     *
     * Smart update tracking
     *
     * Example:
     *
     * $auditService->logModelUpdate(
     *      $oldUser,
     *      $user
     * );
     */
    public function logModelUpdate(
        Model $oldModel,
        Model $newModel,
        string $module
    ): ?AuditLog {


        $old = $oldModel->getAttributes();


        $new = $newModel->getAttributes();



        /**
         * Detect only changed fields
         */
        $changes = [];

        foreach($new as $key => $value){

            if(
                array_key_exists($key,$old)
                &&
                $old[$key] != $value
            ){

                $changes[$key] = [
                    'old' => $old[$key],
                    'new' => $value,
                ];

            }

        }



        if(empty($changes)){

            return null;

        }



        return $this->log(

            action:'UPDATE',

            module:$module,

            model:$newModel,

            oldValues:collect($changes)
                ->mapWithKeys(
                    fn($item,$key)=>[
                        $key=>$item['old']
                    ]
                )
                ->toArray(),


            newValues:collect($changes)
                ->mapWithKeys(
                    fn($item,$key)=>[
                        $key=>$item['new']
                    ]
                )
                ->toArray(),


            description:
                "{$module} updated"

        );

    }





    /**
     * Login audit
     */
    public function login(
        Model $user
    ): AuditLog {


        return $this->log(

            action:'LOGIN',

            module:'AUTH',

            model:$user,

            description:
                'User logged in'

        );

    }





    /**
     * Logout audit
     */
    public function logout(
        Model $user
    ): AuditLog {


        return $this->log(

            action:'LOGOUT',

            module:'AUTH',

            model:$user,

            description:
                'User logged out'

        );

    }





    /**
     * Approval workflow audit
     */
    public function approval(
        string $module,
        Model $model,
        string $status,
        ?string $comment = null
    ): AuditLog {


        return $this->log(

            action:strtoupper($status),

            module:$module,

            model:$model,

            description:
                $comment ??
                "{$module} {$status}"

        );

    }

}