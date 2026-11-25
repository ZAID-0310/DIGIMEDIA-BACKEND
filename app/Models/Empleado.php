<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Cloudinary\Cloudinary;
class Empleado extends Model
{
    use HasFactory;

    protected $table = 'empleados';
    protected $primaryKey = 'id_empleado';
    public $timestamps = true;
    const UPDATED_AT = null;

    protected $fillable = [
        'nombre',
        'apellido',
        'email',
        'dni',
        'telefono',
        'imagen_perfil',
        'imagen_perfil_url',
        'id_user',
        'id_rol',
        'id_subtipo_admin'
    ];

    protected $hidden = [
        'id_user',           // FK interna - frontend no la necesita
        'id_subtipo_admin'   // Estructura interna de admin
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'id_user', 'id');
    }

    public function rol()
    {
        return $this->belongsTo(Rol::class, 'id_rol', 'id_rol');
    }

    public function subtipoAdmin()
    {
        return $this->belongsTo(SubtipoAdmin::class, 'id_subtipo_admin', 'id');
    }

    public function cards()
    {
        return $this->hasMany(Card::class, 'id_empleado', 'id_empleado');
    }

    public function getImagenPerfilUrlAttribute()
    {
        if ($this->imagen_perfil) {
            $cloudinary = new Cloudinary([
                'cloud' => [
                    'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
                    'api_key' => env('CLOUDINARY_KEY'),
                    'api_secret' => env('CLOUDINARY_SECRET'),
                ],
                'url' => [
                    'secure' => true
                ]
            ]);

            return $cloudinary->image($this->imagen_perfil)->toUrl();
        }
        return asset('images/default-profile.jpg');
    }

    public function hasSpecialAccess()
    {
        return cache()->remember("special-access-{$this->id_empleado}", 3600, function () {
            $allowedIds = config('special_access.employee_ids', []);
            return in_array($this->id_empleado, $allowedIds);
        });
    }

    public function blog()
    {
        return $this->hasMany(Blog::class, 'id_empleado', 'id_empleado');
    }

    public function getPrivilegeLevel(): int
    {

        if (!$this->relationLoaded('rol')) {
        $this->load('rol');
        }

        if (!$this->rol) {
        return 0; 
        }

        if ($this->rol->isAdmin()) {
            if ($this->subtipoAdmin && isset($this->subtipoAdmin->hierarchy)) {
                return (int) $this->subtipoAdmin->hierarchy;
            }
            return 80;
        }

        $roleNameLower = strtolower(trim($this->rol->nombre));

        return match ($roleNameLower) {
            'ventas' => 30,
            'marketing' => 20,
            default => 0
        };
        

        
    }

    public function scopeActivos($query)
    {
        return $query;
    }

    public function scopeDelRol($query, $rolId)
    {
        return $query->where('id_rol', $rolId);
    }

    public function scopeConRelaciones($query)
    {
        return $query->with(['user', 'rol', 'subtipoAdmin']);
    }

    public function scopeBuscar($query, $termino)
    {
        if (!$termino) {
            return $query;
        }

        return $query->where('email', 'like', "%{$termino}%")
                     ->orWhere('nombre', 'like', "%{$termino}%")
                     ->orWhere('apellido', 'like', "%{$termino}%");
    }

    public function canBeModifiedBy(Empleado $currentEmpleado)
    {
        if(!$currentEmpleado)
        {
            return false;
        }
        
        if($this->id_empleado === $currentEmpleado->id_empleado)
        {
            return true;
        }

        $currentLevel = $currentEmpleado->getPrivilegeLevel();
        $targetLevel = $this->getPrivilegeLevel();

        if($currentLevel === 100)
        {
            return true;
        }

        if($currentLevel >= 80)
        {
            return $targetLevel < $currentLevel;
        }

        return false;
    } 

    public function blogAuditoria()
    {
        return $this->hasMany(BlogAuditoria::class, 'id_empleado', 'id_empleado');
    }
}
