<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CampaniaWhatsAppDetailsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'id_campania' => $this->id_campania,
            'servicio' => [
                'id_servicio' => $this->servicio->id_servicio ?? null,
                'nombre' => $this->servicio->nombre ?? null,
            ],
            'usuario' => [
                'id' => $this->usuario->id ?? null,
                'name' => $this->usuario->name ?? null,
            ],
            'estado'                     => $this->estado,
            'parrafo'                    => $this->parrafo,
            'imagen_url'                 => $this->imagen_url,
            'total_destinatarios'        => $this->total_destinatarios,
            'envios_exitosos'            => $this->envios_exitosos,
            'envios_fallidos'            => $this->envios_fallidos,
            'envios_pendientes'          => $this->envios_pendientes,
            'envios_hoy'                 => $this->envios_hoy,
            'porcentaje_progreso'        => $this->getProgressPercentage(),
            'fecha_inicio'               => $this->fecha_inicio,
            'fecha_fin'                  => $this->fecha_fin,
            'fecha_ultimo_envio'         => $this->fecha_ultimo_envio,
            'created_at'                 => $this->created_at,
            'is_completed'               => $this->isCompleted(),
            'is_in_progress'             => $this->isInProgress(),
            'is_draft'                   => $this->isDraft(),
            'is_paused_until_tomorrow'   => $this->isPausedUntilTomorrow(),
            'is_paused_outside_hours'    => $this->isPausedOutsideHours(),
            'remaining_daily_quota'      => $this->getRemainingDailyQuota(),
            'has_reached_daily_limit'    => $this->hasReachedDailyLimit(),
            'retry_stats'                => $this->getRetryStats(),
            'has_failed_messages_to_retry' => $this->hasFailedMessagesToRetry(),
            'total_mensajes'             => $this->mensajesWhatsApp->count(),
            'can_auto_resume'            => $this->canAutoResume(),
            'usuario_eliminador'         => $this->usuarioEliminador ? [
                'id' => $this->usuarioEliminador->id,
                'name' => $this->usuarioEliminador->name,
            ] : null,
            'fecha_eliminada'            => $this->fecha_eliminada,
            'is_deleted'                 => $this->isDeleted(),
        ];
    }
}
