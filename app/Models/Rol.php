<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Rol extends Model
{
    use HasFactory;

    protected $table = 'roles';
    protected $primaryKey = 'id_rol';
    public $timestamps = false;

    protected $fillable = [
        'nombre',
    ];

    const ADMIN_IDS = [1];

    public function isAdmin(): bool
    {
        return in_array($this->id_rol, self::ADMIN_IDS);
    }

    public function empleados()
    {
        return $this->hasMany(Empleado::class, 'id_rol', 'id_rol');
    }
    
    public function permisos()
    {
        return $this->belongsToMany(Permiso::class, 'role_permission', 'id_rol', 'id_permiso');
    }
}
