<?php

namespace App\Services;

use App\Repositories\RolRepository;
use App\DTOs\Rol\StoreRolDTO;
use App\DTOs\Rol\UpdateRolDTO;
use App\DTOs\Rol\SyncPermisosDTO;
use App\Models\Rol;
use App\Models\Permiso;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class RolService
{
    public function __construct(
        private RolRepository $repository
    ) {}

    /**
     * Get all roles.
     */
    public function getRoles(): Collection
    {
        return $this->repository->getAllWithFields();
    }

    /**
     * Create a new role.
     */
    public function createRol(StoreRolDTO $dto): Rol
    {
        return DB::transaction(function () use ($dto) {
            $rol = $this->repository->create([
                'nombre' => $dto->nombre,
            ]);

            if (!empty($dto->permisos)) {
                $rol->permisos()->attach($dto->permisos);
            }

            return $rol;
        });
    }

    /**
     * Get role by ID with optional relations.
     */
    public function getRolById(int $id, array $relations = []): Rol
    {
        $rol = $this->repository->findById($id, $relations);

        if (!$rol) {
            throw new ModelNotFoundException('Rol no encontrado');
        }

        return $rol;
    }

    /**
     * Update an existing role.
     */
    public function updateRol(int $id, UpdateRolDTO $dto): Rol
{
    return DB::transaction(function () use ($id, $dto) {
        $rol = $this->getRolById($id);

        // 1. BLINDAJE DEL NOMBRE (Solo si NO es Admin)
        if (!$rol->isAdmin()) {
            
            // Aseguramos que nombre sea SIEMPRE un string válido
            $nuevoNombre = is_array($dto->nombre) 
                ? implode(', ', $dto->nombre) // Si llega array, lo unimos a texto
                : trim((string) $dto->nombre); // Si llega string, lo limpiamos
            
            // Validación extra: no permitir nombres vacíos
            if ($nuevoNombre === '') {
                 throw new \InvalidArgumentException('El nombre del rol no puede estar vacío.');
            }

            $this->repository->update($rol, [
                'nombre' => $nuevoNombre,
            ]);
        }

        // 2. BLINDAJE DE PERMISOS
        if ($dto->permisos !== null) {
            
            // Aseguramos que permisos sea un array de IDs enteros válidos
            // Filtramos valores nulos o no numéricos para evitar errores en sync()
            $cleanPermisos = [];
            foreach ((array) $dto->permisos as $permisoId) {
                if (is_numeric($permisoId)) {
                    $cleanPermisos[] = (int) $permisoId;
                }
            }

            // Creamos el DTO seguro
            $syncDto = new SyncPermisosDTO(['permisos' => $cleanPermisos]);
            
            // Llamamos a la lógica protegida
            $this->syncPermisosDeRol($id, $syncDto);
        }

        return $rol->fresh();
    });
}

    /**
     * Delete a role, ensuring no employees are associated.
     */
    public function deleteRol(int $id): void
    {
        DB::transaction(function () use ($id) {
            $rol = $this->getRolById($id);

            // verificar si hay empleados con este rol
            if ($rol->empleados()->count() > 0) {
                throw new \RuntimeException('No se puede eliminar el rol porque tiene empleados asociados');
            }

            // eliminar la relación con permisos
            $rol->permisos()->detach();
            $this->repository->delete($rol);
        });
    }

    /**
     * Get all permissions of a role.
     */
    public function getPermisosDeRol(int $id): Collection
    {
        $rol = $this->getRolById($id, ['permisos']);
        return $rol->permisos;
    }

    /**
     * Sync permissions of a role.
     */
   public function syncPermisosDeRol(int $id, SyncPermisosDTO $dto): Collection
{
    return DB::transaction(function () use ($id, $dto) {
        $rol = $this->getRolById($id);

        $rawData = $dto->permisos;
        
        if (is_array($rawData) && isset($rawData['permisos']) && is_array($rawData['permisos'])) {
            $rawData = $rawData['permisos'];
        }

        if (!is_array($rawData)) {
            $rawData = [];
        }

        $submittedIds = [];
        
        foreach ($rawData as $item) {
            if (is_numeric($item)) {
                $submittedIds[] = (int) $item;
            } elseif (is_object($item) && isset($item->id_permiso)) {
                $submittedIds[] = (int) $item->id_permiso;
            } elseif (is_array($item) && count($item) > 0) {
                $submittedIds[] = (int) reset($item);
            }
        }
        
        $initialCleanIds = array_unique(array_filter($submittedIds, fn($v) => $v > 0));

        if ($rol->isAdmin()) {
            $criticalSlugs = [
                'ver-roles', 'crear-roles', 'editar-roles', 'eliminar-roles',
                'ver-permisos', 'crear-permisos', 'editar-permisos', 'eliminar-permisos',
                'ver-empleados', 'crear-empleados', 'editar-empleados', 'eliminar-empleados'
            ];

            $criticalIds = Permiso::whereIn('slug', $criticalSlugs)
                ->pluck('id_permiso')
                ->map(fn($id) => (int) $id)
                ->toArray();

            $mergedIds = array_merge($initialCleanIds, $criticalIds);
            $finalIds = array_unique(array_filter($mergedIds, fn($v) => is_int($v) && $v > 0));
        } else {
            $finalIds = $initialCleanIds;
        }

        $rol->permisos()->sync($finalIds);

        return $rol->permisos()
            ->select('permisos.*')
            ->get();
    });
}
}
