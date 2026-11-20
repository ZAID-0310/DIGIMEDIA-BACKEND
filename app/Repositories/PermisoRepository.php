<?php

namespace App\Repositories;

use App\Models\Permiso;
use Illuminate\Database\Eloquent\Collection;

class PermisoRepository
{
    /**
     * Get all permissions.
     */
    public function getAll(): Collection
    {
        return Permiso::withCount('roles')->get();
    }

    /**
     * Find a permission by ID.
     */
    public function findById(int $id): ?Permiso
    {
        return Permiso::find($id);
    }

    /**
     * Create a new permission.
     */
    public function create(array $data): Permiso
    {
        return Permiso::create($data);
    }

    /**
     * Update an existing permission.
     */
    public function update(Permiso $permiso, array $data): bool
    {
        return $permiso->update($data);
    }

    /**
     * Delete a permission.
     */
    public function delete(Permiso $permiso): ?bool
    {
        return $permiso->delete();
    }
}
