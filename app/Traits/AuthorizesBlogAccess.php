<?php

namespace App\Traits;

use App\Models\Rol;

trait AuthorizesBlogAccess
{
    /**
     * Determina si el usuario autenticado tiene permisos para visualizar
     * blogs no publicados (borradores / invisibles).
     */
    protected function canViewUnpublishedBlogs(): bool
    {
        $user = auth('sanctum')->user();
        if (!$user) {
            return false;
        }

        $empleado = $user->empleado; 
    
        if (!$empleado || !$empleado->rol) {
            return false;
        }

        $currentRole = $empleado->rol;

        if ($currentRole->isAdmin()) {
        return true;
        }

        $currentRole->loadMissing('permisos');

        return $currentRole->permisos->contains('slug', 'ver-blogs');
        
    }
}

