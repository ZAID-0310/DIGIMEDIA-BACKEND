<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PermisoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'id_permiso'  => $this->id_permiso,
            'nombre'      => $this->nombre,
            'slug'        => $this->slug,
            'descripcion' => $this->descripcion,
            'is_assigned' => ($this->resource->roles_count ?? 0) > 0,
        ];
    }
}
