<?php

namespace App\Repositories;

use App\Models\CampaniaWhatsApp;
use App\DTOs\Campania\CampaniaFiltersDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CampaniaRepository
{
    public function getPaginatedWithFilters(CampaniaFiltersDTO $filters): LengthAwarePaginator
    {
        $query = CampaniaWhatsApp::with('servicio');

        if ($filters->estado) {
            $query->where('estado', $filters->estado);
        }

        return $query->orderBy('created_at', 'desc')->paginate($filters->perPage);
    }

    public function findById(int $id): ?CampaniaWhatsApp
    {
        return CampaniaWhatsApp::with(['servicio', 'usuario', 'usuarioEliminador', 'mensajesWhatsApp'])->find($id);
    }

    public function getActiveCampaign(): ?CampaniaWhatsApp
    {
        return CampaniaWhatsApp::getActiveCampaign();
    }

    public function getValidLeadsIdsForCampaign(CampaniaWhatsApp $campania): array
    {
        $processedIds = !empty($campania->id_campania)
            ? \App\Models\WatModal::where('campania_id', $campania->id_campania)
                ->pluck('id_modalservicio')
                ->unique()
                ->values()
                ->toArray()
            : [];

        // Si la campaña ya completó o alcanzó el total de destinatarios con los procesados,
        // no hay más destinatarios pendientes que correspondan a esta campaña
        if ($campania->total_destinatarios > 0 && count($processedIds) >= $campania->total_destinatarios) {
            return $processedIds;
        }

        // Si la campaña está completada y no registra envíos pendientes, retornamos solo los procesados
        if (in_array($campania->estado, ['completada', 'error']) && $campania->envios_pendientes <= 0) {
            return $processedIds;
        }

        // 2. Determinar la cantidad de destinatarios pendientes que faltan para completar la campaña
        $pendingLimit = null;
        if ($campania->total_destinatarios > 0) {
            $pendingLimit = max(0, $campania->total_destinatarios - count($processedIds));
            if ($pendingLimit === 0 && !empty($processedIds)) {
                return $processedIds;
            }
        } elseif ($campania->envios_pendientes > 0) {
            $pendingLimit = $campania->envios_pendientes;
        }

        // 3. Buscar los leads activos de modalservicios para este servicio que aún no fueron procesados
        $query = \App\Models\modalservicios::query()
            ->where('id_servicio', $campania->id_servicio)
            ->where('estado', 1)
            ->whereNotNull('telefono')
            ->where('telefono', '!=', '');

        if (!empty($processedIds)) {
            $query->whereNotIn('id_modalservicio', $processedIds);
        }

        if ($campania->fecha_inicio) {
            $query->where('modalservicios.fecha', '<=', $campania->fecha_inicio);
        }

        $pendingQuery = $query->orderBy('id_modalservicio')
            ->get()
            ->unique('telefono');

        if ($pendingLimit !== null) {
            $pendingQuery = $pendingQuery->take($pendingLimit);
        }

        $pendingIds = $pendingQuery->pluck('id_modalservicio')->toArray();

        return array_values(array_unique(array_merge($processedIds, $pendingIds)));
    }
}