<?php

namespace App\Services;

use App\Repositories\CampaniaRepository;
use App\Repositories\WatModalRepository;
use App\Repositories\ModalServicioRepository;
use App\Services\WhatsAppExternalService;
use App\Models\CampaniaWhatsApp;

class CampaniaLeadService
{
    public function __construct(
        private CampaniaRepository $campaniaRepository,
        private WatModalRepository $watModalRepository,
        private ModalServicioRepository $modalServicioRepository,
        private WhatsAppExternalService $whatsAppService
    ) {}

    public function getLeadsList(int $campaniaId, array $filters): array
    {
        $campania = $this->campaniaRepository->findById($campaniaId);
        
        if (!$campania) {
            throw new \Exception('Campaña no encontrada', 404);
        }

        $validLeadIds = $this->campaniaRepository->getValidLeadsIdsForCampaign($campania);
        
        $query = $this->modalServicioRepository->getLeadsWithWhatsAppStatus($validLeadIds, $campaniaId, $filters);
        
        $leads = $query->orderByRaw('modal_wats.id_modal_wat IS NULL ASC')
            ->orderBy('modal_wats.fecha', 'desc')
            ->orderBy('modalservicios.id_modalservicio', 'asc')
            ->paginate($filters['perPage'] ?? 15);

        return [
            'campania' => $campania,
            'leads' => $leads
        ];
    }

    public function retryLead(int $campaniaId, int $watModalId): array
    {
        $campania = $this->campaniaRepository->findById($campaniaId);
        
        if (!$campania) {
            throw new \Exception('Campaña no encontrada', 404);
        }

        $watModal = $this->watModalRepository->findByCampaniaAndLead($campaniaId, $watModalId);
        
        if (!$watModal) {
            throw new \Exception('Lead no encontrado en esta campaña', 404);
        }

        if (!$watModal->canRetry()) {
            throw new \Exception('Este lead no puede reintentarse (máximo 3 intentos o ya fue enviado)', 400);
        }

        if (!CampaniaWhatsApp::isWithinAllowedHours()) {
            throw new \Exception('Fuera del horario permitido (8am–11pm hora Lima)', 400);
        }

        if ($campania->hasReachedDailyLimit()) {
            throw new \Exception('La campaña alcanzó el límite diario de envíos', 400);
        }

        $modalServicio = $this->modalServicioRepository->findById($watModal->id_modalservicio);
        
        $telefonoFormateado = $this->formatearTelefonoWhatsApp($modalServicio->telefono);
        
        $result = $this->whatsAppService->sendMessage(
            $telefonoFormateado,
            $campania->parrafo,
            $campania->imagen_url
        );

        if ($result['success']) {
            $this->watModalRepository->update($watModal, [
                'estado' => 1,
                'error' => null,
                'intentos' => $watModal->intentos + 1,
                'puede_reintentar' => false,
                'fecha' => now(),
            ]);
            
            $campania->increment('envios_exitosos');
            $campania->increment('envios_hoy');
            $campania->decrement('envios_fallidos');
            
            return ['success' => true, 'message' => 'Mensaje reenviado correctamente'];
        }

        $newIntentos = $watModal->intentos + 1;
        $this->watModalRepository->update($watModal, [
            'estado' => 0,
            'intentos' => $newIntentos,
            'error' => $result['error'],
            'puede_reintentar' => $newIntentos < 3,
        ]);

        throw new \Exception('No se pudo reenviar el mensaje', 500);
    }

    private function formatearTelefonoWhatsApp(string $telefono): string
    {
        // Llamada a la función global formatearTelefonoWhatsApp si existe
        if (function_exists('formatearTelefonoWhatsApp')) {
            return formatearTelefonoWhatsApp($telefono);
        }
        return $telefono;
    }
}