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

        $token = $user->currentAccessToken();
        if (!$token) {
            return false;
        }

        $userRoles = $token->abilities ?? [];
        if (empty($userRoles)) {
            return false;
        }

        // Si el usuario tiene rol 'administrador', tiene acceso total
        if (in_array('administrador', $userRoles, true)) {
            return true;
        }

        // Verificar si alguno de los roles del token posee el permiso 'ver-blogs'
        $roles = Rol::with('permisos:id_permiso,slug')
            ->whereIn('nombre', $userRoles)
            ->get();

        foreach ($roles as $rol) {
            if ($rol->permisos->contains('slug', 'ver-blogs')) {
                return true;
            }
        }

        return false;
    }
}

