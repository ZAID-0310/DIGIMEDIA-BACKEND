<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Permiso;
use App\Models\Rol;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$permissions): Response
    {

     
        if (!$user = $request->user()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No autorizado',
            ], 401);
        }

        $requiredSlugs = array_values($permissions);


        $hasAnyPermission = false;
        foreach ($requiredSlugs as $slug) {
            if ($user->hasPermission($slug)) {
                $hasAnyPermission = true;
                break;
            }
        }

        if ($hasAnyPermission) {
            return $next($request);
        }

        return response()->json([
            'status'  => 'error',
            'message' => 'No tiene los permisos necesarios para acceder a este recurso',
        ], 403);
    }
}
