<?php

namespace App\Services;

use App\Repositories\PermisoRepository;
use App\DTOs\Permiso\StorePermisoDTO;
use App\DTOs\Permiso\UpdatePermisoDTO;
use App\Models\Permiso;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PermisoService
{
    public function __construct(
        private PermisoRepository $repository
    ) {}

    /**
     * Get all permissions.
     */
    public function getPermisos(): Collection
    {
        return $this->repository->getAll();
    }

    /**
     * Create a permission.
     */
    public function createPermiso(StorePermisoDTO $dto): Permiso
    {
        $data = $dto->toArray();
        $data['slug'] = Str::slug($dto->nombre);

        return $this->repository->create($data);
    }

    /**
     * Get a permission by ID.
     */
    public function getPermisoById(int $id): Permiso
    {
        $permiso = $this->repository->findById($id);

        if (!$permiso) {
            throw new ModelNotFoundException('Permiso no encontrado');
        }

        return $permiso;
    }

    /**
     * Update a permission.
     */
    public function updatePermiso(int $id, UpdatePermisoDTO $dto): Permiso
    {
        $permiso = $this->getPermisoById($id);

        $data = $dto->toArray();
        if ($permiso->nombre !== $dto->nombre) {
            $data['slug'] = Str::slug($dto->nombre);
        }

        $this->repository->update($permiso, $data);

        return $permiso->fresh();
    }

    /**
     * Delete a permission.
     */
    /**
 * Delete a permission safely.
 */
public function deletePermiso(int $id): bool
{
    if ($id <= 0) {
        throw new \InvalidArgumentException("ID inválido para eliminación: {$id}");
    }

    try {
        $permiso = Permiso::findOrFail($id);
        $this->repository->delete($permiso); 

        return true;

    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        Log::warning("Intento de eliminar permiso inexistente ID: {$id}");
        throw new \Exception("El permiso con ID {$id} no existe.");
        
    } catch (\Illuminate\Database\QueryException $e) {
        Log::error("Error de BD al eliminar permiso ID {$id}: " . $e->getMessage());
        throw new \Exception("Error de integridad en la base de datos al intentar eliminar.");
        
    } catch (\Exception $e) {
        Log::error("Error genérico en PermisoService::deletePermiso: " . $e->getMessage());
        throw $e;
    }
}
}
