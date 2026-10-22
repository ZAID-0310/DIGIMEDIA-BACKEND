<?php

namespace App\Services;

use App\Repositories\WhatsAppCampaignRepository;
use App\DTOs\WhatsAppCampaign\CreateCampaignDTO;
use App\Models\CampaniaWhatsApp;
use App\Models\modalservicios;
use App\Models\WatModal;
use App\Jobs\SendWhatsAppCampaignJob;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

class WhatsAppCampaignService
{
    private const SERVICE_MAP = [
        'p1' => 1, // Diseño y Desarrollo Web
        'p2' => 2, // Gestión de Redes Sociales
        'p3' => 3, // Marketing y Gestión Digital
        'p4' => 4, // Branding y Diseño
    ];

    public function __construct(
        private WhatsAppCampaignRepository $repository
    ) {}

    public function createCampaign(CreateCampaignDTO $dto, User $user): CampaniaWhatsApp
    {
        if (!array_key_exists($dto->service, self::SERVICE_MAP)) {
            throw new \InvalidArgumentException('Servicio inválido. Use p1, p2, p3 o p4.', 422);
        }

        $id_servicio = self::SERVICE_MAP[$dto->service];

        // Verificar que hay destinatarios disponibles
        $destinatarios = $this->getDestinatarios($id_servicio);

        if ($destinatarios->isEmpty()) {
            throw new \Exception('No hay destinatarios activos con teléfonos válidos para este servicio', 400);
        }

        // Si se especificó una cantidad de destinatarios personalizada, limitar
        if ($dto->cantidadDestinatarios !== null && $dto->cantidadDestinatarios > 0) {
            if ($dto->cantidadDestinatarios > $destinatarios->count()) {
                throw new \Exception("La cantidad solicitada ({$dto->cantidadDestinatarios}) supera el total de destinatarios disponibles ({$destinatarios->count()}).", 422);
            }
            $destinatarios = $destinatarios->take($dto->cantidadDestinatarios);
        }

        // Subir imagen a Cloudinary
        $imagenUrl = $this->uploadImageToCloudinary($dto->image, 'campanias_whatsapp');

        if (!$imagenUrl) {
            throw new \Exception('Error al subir la imagen a Cloudinary', 500);
        }

        // Crear registro de campaña en BORRADOR con auditoría de usuario
        $campania = $this->repository->create([
            'id_servicio' => $id_servicio,
            'user_id' => $user->id,
            'parrafo' => $dto->paragraph,
            'imagen_url' => $imagenUrl,
            'estado' => 'borrador',
            'total_destinatarios' => $destinatarios->count(),
            'envios_pendientes' => $destinatarios->count(),
        ]);

        Log::info('Campaña WhatsApp creada en borrador', [
            'campania_id' => $campania->id_campania,
            'servicio' => $dto->service,
            'total_destinatarios' => $destinatarios->count(),
            'creado_por_user_id' => $user->id,
            'creado_por_nombre' => $user->name
        ]);

        return $campania;
    }

    public function startCampaign(int $id, User $user): CampaniaWhatsApp
    {
        $campania = $this->repository->findById($id);

        if (!$campania) {
            throw new ModelNotFoundException('Campaña no encontrada');
        }

        // 🔒 VALIDACIÓN 1: Verificar que WhatsApp está conectado
        $whatsappStatus = $this->checkWhatsAppConnection();
        if (!$whatsappStatus['connected']) {
            throw new \App\Exceptions\WhatsAppConnectionException(
                '📱 WhatsApp no está conectado. Por favor, escanea el código QR en la pestaña "Conexión" primero.',
                400,
                $whatsappStatus
            );
        }

        // 🔒 VALIDACIÓN 2 (FIFO): Verificar que no hay otra campaña activa
        if (!$campania->canBeStarted()) {
            $activeCampaign = $this->repository->getActiveCampaign();
            
            if ($activeCampaign && $activeCampaign->id_campania !== $campania->id_campania) {
                throw new \App\Exceptions\CampaignConflictException(
                    'Ya hay una campaña en proceso. Espera a que finalice.',
                    409,
                    $activeCampaign
                );
            }

            throw new \Exception('La campaña no puede ser iniciada. Estado actual: ' . $campania->estado, 400);
        }

        // Obtener destinatarios pendientes
        $destinatarios = $this->getDestinatarios($campania->id_servicio);

        if ($destinatarios->isEmpty()) {
            throw new \Exception('No hay destinatarios disponibles para esta campaña', 400);
        }

        // Si la campaña fue creada con una cantidad limitada de destinatarios, respetarla
        if ($campania->total_destinatarios > 0) {
            $destinatarios = $destinatarios->take($campania->total_destinatarios);
        }

        // Garantizar que ninguna otra campaña quede activa al iniciar esta
        CampaniaWhatsApp::whereIn('estado', ['en_proceso', 'pendiente'])
            ->where('id_campania', '!=', $campania->id_campania)
            ->update(['estado' => 'pausada']);

        // Actualizar estado y fecha de inicio
        $this->repository->update($campania, [
            'estado' => 'pendiente',
            'fecha_inicio' => now()
        ]);

        // 🚀 Despachar Job para envío
        SendWhatsAppCampaignJob::dispatch($campania, $destinatarios->toArray());

        Log::info('Campaña WhatsApp iniciada', [
            'campania_id' => $campania->id_campania,
            'iniciada_por_user_id' => $user->id,
            'iniciada_por_nombre' => $user->name
        ]);

        return $campania;
    }

    public function pauseCampaign(int $id, User $user): CampaniaWhatsApp
    {
        $campania = $this->repository->findById($id);

        if (!$campania) {
            throw new ModelNotFoundException('Campaña no encontrada');
        }

        if (!$campania->canBePaused()) {
            throw new \Exception("La campaña no puede ser pausada. Estado actual: {$campania->estado}", 400);
        }

        $this->repository->update($campania, [
            'estado' => 'pausada',
        ]);

        Log::info('Campaña WhatsApp pausada manualmente', [
            'campania_id' => $campania->id_campania,
            'pausada_por_user_id' => $user->id,
            'pausada_por_nombre' => $user->name,
        ]);

        return $campania->fresh();
    }

    public function resumeCampaign(int $id, User $user): CampaniaWhatsApp
    {
        $campania = $this->repository->findById($id);

        if (!$campania) {
            throw new ModelNotFoundException('Campaña no encontrada');
        }

        if (!$campania->canBeResumed()) {
            throw new \Exception("La campaña no puede ser reanudada. Estado actual: {$campania->estado}", 400);
        }

        // Verificar conexión de WhatsApp
        $whatsappStatus = $this->checkWhatsAppConnection();
        if (!$whatsappStatus['connected']) {
            throw new \App\Exceptions\WhatsAppConnectionException(
                '📱 WhatsApp no está conectado. Por favor, conecta WhatsApp antes de reanudar.',
                400,
                $whatsappStatus
            );
        }

        // Obtener leads ya enviados exitosamente para omitirlos
        $enviadosIds = WatModal::where('campania_id', $campania->id_campania)
            ->where('estado', 1)
            ->pluck('id_modalservicio')
            ->toArray();

        $destinatariosBase = $this->getDestinatarios($campania->id_servicio);
        if ($campania->total_destinatarios > 0) {
            $destinatariosBase = $destinatariosBase->take($campania->total_destinatarios);
        }

        $destinatarios = $destinatariosBase
            ->filter(function ($dest) use ($enviadosIds) {
                return !in_array($dest->id_modalservicio, $enviadosIds);
            })
            ->values();

        if ($destinatarios->isEmpty()) {
            $campania->update([
                'estado' => 'completada',
                'fecha_fin' => now(),
            ]);
            return $campania->fresh();
        }

        // Garantizar que ninguna otra campaña quede activa al reanudar esta
        CampaniaWhatsApp::whereIn('estado', ['en_proceso', 'pendiente'])
            ->where('id_campania', '!=', $campania->id_campania)
            ->update(['estado' => 'pausada']);

        $this->repository->update($campania, [
            'estado' => 'en_proceso',
        ]);

        SendWhatsAppCampaignJob::dispatch($campania, $destinatarios->toArray());

        Log::info('Campaña WhatsApp reanudada manualmente', [
            'campania_id' => $campania->id_campania,
            'reanudada_por_user_id' => $user->id,
            'reanudada_por_nombre' => $user->name,
            'destinatarios_pendientes' => $destinatarios->count(),
        ]);

        return $campania->fresh();
    }

    public function cancelCampaign(int $id, User $user): CampaniaWhatsApp
    {
        $campania = $this->repository->findById($id);

        if (!$campania) {
            throw new ModelNotFoundException('Campaña no encontrada');
        }

        if (!$campania->canBeCancelled()) {
            throw new \Exception("La campaña no puede ser cancelada. Estado actual: {$campania->estado}", 400);
        }

        $this->repository->update($campania, [
            'estado' => 'cancelada',
            'fecha_fin' => now(),
        ]);

        Log::info('Campaña WhatsApp cancelada manualmente', [
            'campania_id' => $campania->id_campania,
            'cancelada_por_user_id' => $user->id,
            'cancelada_por_nombre' => $user->name,
        ]);

        return $campania->fresh();
    }

    public function previewCampaign(string $serviceCode): array
    {
        if (!array_key_exists($serviceCode, self::SERVICE_MAP)) {
            throw new \InvalidArgumentException('Servicio inválido. Usa p1, p2, p3 o p4.', 422);
        }

        $id_servicio = self::SERVICE_MAP[$serviceCode];
        $destinatarios = $this->getDestinatarios($id_servicio);
        $testNumber = config('services.whatsapp.test_number') ?: env('WHATSAPP_TEST_NUMBER');

        return [
            'service' => $serviceCode,
            'total_destinatarios' => $destinatarios->count(),
            'destinatarios_disponibles' => $destinatarios->count(),
            'is_sandbox' => !empty($testNumber),
            'test_number' => !empty($testNumber) ? $testNumber : null,
        ];
    }

    public function getCampaignStatus(int $id): CampaniaWhatsApp
    {
        $campania = $this->repository->findByIdWithServicio($id);

        if (!$campania) {
            throw new ModelNotFoundException('Campaña no encontrada');
        }

        return $campania;
    }

    public function listCampaigns(int $limit): array
    {
        $campanias = $this->repository->listRecent($limit);
        $activeCampaign = $this->repository->getActiveCampaign();

        return [
            'active_campaign' => $activeCampaign,
            'campanias' => $campanias
        ];
    }

    public function testCloudinaryUpload($imageFile): array
    {
        try {
            $result = Cloudinary::uploadApi()->upload($imageFile->getRealPath(), [
                'folder' => 'test_uploads'
            ]);

            return [
                'url' => $result['secure_url'],
                'public_id' => $result['public_id'],
                'format' => $result['format'],
                'width' => $result['width'],
                'height' => $result['height'],
                'size_bytes' => $result['bytes'],
                'created_at' => $result['created_at']
            ];
        } catch (\Exception $e) {
            Log::error('Error al subir imagen a Cloudinary en test', [
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Error al subir imagen: ' . $e->getMessage(), 500);
        }
    }

    public function uploadImageToCloudinary($imageFile, string $folder): ?string
    {
        try {
            $result = Cloudinary::uploadApi()->upload($imageFile->getRealPath(), [
                'folder' => $folder
            ]);
            
            return $result['secure_url'];
        } catch (\Exception $e) {
            Log::error('Error al subir imagen a Cloudinary', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function checkWhatsAppConnection(): array
    {
        try {
            $whatsappServiceUrl = env('WHATSAPP_API_URL', 'http://localhost:5111');
            $apiKey = env('WHATSAPP_SERVICE_API_KEY');

            $response = Http::timeout(5)
                ->withHeaders(['X-API-Key' => $apiKey])
                ->get($whatsappServiceUrl . '/api/whatsapp/status');

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'connected' => true,
                    'message' => 'Servicio WhatsApp disponible',
                    'status' => $data
                ];
            }

            return [
                'connected' => false,
                'message' => 'Servicio WhatsApp no responde correctamente',
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::warning('No se pudo verificar conexión WhatsApp', [
                'error' => $e->getMessage()
            ]);

            return [
                'connected' => false,
                'message' => 'No se pudo conectar al servicio WhatsApp: ' . $e->getMessage()
            ];
        }
    }

    private function getDestinatarios(int $id_servicio): Collection
    {
        $testNumber = config('services.whatsapp.test_number') ?: env('WHATSAPP_TEST_NUMBER');

        if (!empty($testNumber)) {
            // Modo Sandbox / Prueba: usamos el primer lead para conservar integridad relacional (FK)
            $lead = modalservicios::where('id_servicio', $id_servicio)
                ->where('estado', 1)
                ->orderBy('id_modalservicio')
                ->first();

            if (!$lead) {
                $lead = modalservicios::where('estado', 1)->first() ?? modalservicios::first();
            }

            if ($lead) {
                $testLead = clone $lead;
                $testLead->telefono = $testNumber;
                $testLead->nombre = $lead->nombre . ' (Prueba Sandbox)';

                Log::info("Modo Sandbox WhatsApp activo: Campaña dirigida únicamente al número de prueba: {$testNumber}");
                return collect([$testLead]);
            }
        }

        return modalservicios::where('id_servicio', $id_servicio)
            ->where('estado', 1) // Solo activos
            ->whereNotNull('telefono')
            ->where('telefono', '!=', '')
            ->orderBy('id_modalservicio')
            ->get()
            ->unique('telefono')
            ->values();
    }
}
