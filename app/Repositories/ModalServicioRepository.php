<?php

namespace App\Repositories;

use App\Models\modalservicios;

class ModalServicioRepository
{
    public function findById(int $id): ?modalservicios
    {
        return modalservicios::find($id);
    }

    public function findByIdWithServicio(int $id): ?modalservicios
    {
        return modalservicios::where('id_modalservicio', $id)->with(['servicio', 'subservicio'])->first();
    }

    public function getPaginated(string $search, int $perPage)
    {
        $query = modalservicios::with(['servicio', 'subservicio']);

        if (!empty($search)) {
            $query->where(function($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                  ->orWhere('correo', 'like', "%{$search}%")
                  ->orWhere('id_modalservicio', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('id_modalservicio', 'asc')->paginate($perPage);
    }

    public function create(array $data): modalservicios
    {
        return modalservicios::create($data);
    }

    public function update(modalservicios $modal, array $data): bool
    {
        return $modal->update($data);
    }

    public function delete(modalservicios $modal): ?bool
    {
        return $modal->delete();
    }

    public function getLeadsWithWhatsAppStatus(array $validLeadIds, int $campaniaId, array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $query = modalservicios::query()
            ->whereIn('modalservicios.id_modalservicio', $validLeadIds)
            ->leftJoin('modal_wats', function ($join) use ($campaniaId) {
                $join->on('modalservicios.id_modalservicio', '=', 'modal_wats.id_modalservicio')
                    ->where('modal_wats.campania_id', $campaniaId);
            })
            ->select([
                'modalservicios.id_modalservicio',
                'modalservicios.nombre',
                'modalservicios.telefono',
                'modal_wats.id_modal_wat',
                'modal_wats.estado',
                'modal_wats.intentos',
                'modal_wats.puede_reintentar',
                'modal_wats.error',
                'modal_wats.fecha',
            ]);

        // Filtro por estado
        if (!empty($filters['estado'])) {
            if ($filters['estado'] === 'pendiente') {
                $query->whereNull('modal_wats.id_modal_wat');
            } elseif ($filters['estado'] === 'enviado') {
                $query->where('modal_wats.estado', 1);
            } elseif ($filters['estado'] === 'fallido') {
                $query->where('modal_wats.estado', 0);
            }
        }

        // Filtro de búsqueda
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('modalservicios.nombre', 'like', "%{$search}%")
                    ->orWhere('modalservicios.telefono', 'like', "%{$search}%");
            });
        }

        return $query;
    }
}