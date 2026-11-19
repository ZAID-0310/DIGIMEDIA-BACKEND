<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permiso extends Model
{
    protected $table = 'permisos';
    protected $primaryKey = 'id_permiso';
    public $timestamps = false;

    protected $fillable = ['nombre', 'slug', 'descripcion'];

    public function roles()
    {
        return $this->belongsToMany(Rol::class, 'role_permission', 'id_permiso', 'id_rol');
    }

    
}
