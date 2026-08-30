<?php

namespace App\Modules\System\AccessManagement\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PermissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id'=>(string)$this->id,

            'name'=>$this->name,

            'label'=>$this->label,

            'module'=>$this->module,

            'guardName'=>$this->guard_name,

        ];
    }
}