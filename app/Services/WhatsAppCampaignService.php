<?php

namespace App\Services;

use App\Repositories\WhatsAppCampaignRepository;
use App\DTOs\WhatsAppCampaign\CreateCampaignDTO;
use App\Models\CampaniaWhatsApp;
use App\Models\modalservicios;
use App\Jobs\SendWhatsAppCampaignJob;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

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

        if ($dto->recipientCount !== null && $dto->recipientCount > $destinatarios->count()) {
            throw ValidationException::withMessages([
                'cantidad_destinatarios' => [
                    'La cantidad solicitada supera los destinatarios disponibles para este servicio.',
                ],
            ]);
        }

        if ($destinatarios->isEmpty()) {
            throw new \Exception('No hay destinatarios activos con teléfonos válidos para este servicio', 400);
        }

        if ($dto->recipientCount !== null) {
            $destinatarios = $destinatarios->take($dto->recipientCount)->values();
        }

        $totalDestinatarios = $destinatarios->count();

        // Subir imagen a Cloudinary
        $imagenUrl = $this->uploadImageToCloudinary($dto->image, 'campanias_whatsapp');

        if (!$imagenUrl) {
            throw new \Exception('Error al subir la imagen a Cloudinary', 500);
        }

        $campania = DB::transaction(function () use (
            $id_servicio,
            $user,
            $dto,
            $imagenUrl,
            $destinatarios,
            $totalDestinatarios
        ): CampaniaWhatsApp {
            $campania = $this->repository->create([
                'id_servicio' => $id_servicio,
                'user_id' => $user->id,
                'parrafo' => $dto->paragraph,
                'imagen_url' => $imagenUrl,
                'estado' => 'borrador',
                'total_destinatarios' => $totalDestinatarios,
                'envios_pendientes' => $totalDestinatarios,
                'destinatarios_persistidos' => false,
            ]);

            $campania->destinatarios()->attach($destinatarios->pluck('id_modalservicio')->all());

            $totalPersistido = $campania->destinatarios()->count();
            if ($totalPersistido !== $totalDestinatarios) {
                throw new \RuntimeException('No se pudo persistir la selección completa de destinatarios.');
            }

            $campania->destinatarios_persistidos = true;
            if (!$campania->save()) {
                throw new \RuntimeException('No se pudo marcar la selección de destinatarios como persistida.');
            }

            return $campania;
        });

        Log::info('Campaña WhatsApp creada en borrador', [
            'campania_id' => $campania->id_campania,
            'servicio' => $dto->service,
            'total_destinatarios' => $totalDestinatarios,
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

        if ($campania->destinatarios_persistidos) {
            if (!$campania->destinatarios()->exists()) {
                throw new \RuntimeException(
                    'Error de integridad: la campaña está marcada con destinatarios persistidos, pero no tiene asociaciones.'
                );
            }

            $destinatarios = $campania->getPendingPersistedRecipients();
            $campania->reconcilePersistedRecipientCounts($destinatarios);
        } else {
            // Mantener el comportamiento previo para campañas históricas.
            $destinatarios = $this->getDestinatarios($campania->id_servicio);
        }

        if ($destinatarios->isEmpty()) {
            throw new \Exception('No hay destinatarios elegibles pendientes para esta campaña', 400);
        }

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

    public function previewCampaign(string $serviceCode): array
    {
        if (!array_key_exists($serviceCode, self::SERVICE_MAP)) {
            throw new \InvalidArgumentException('Servicio inválido. Usa p1, p2, p3 o p4.', 422);
        }

        $id_servicio = self::SERVICE_MAP[$serviceCode];
        $destinatarios = $this->getDestinatarios($id_servicio);

        return [
            'service' => $serviceCode,
            'total_destinatarios' => $destinatarios->count(),
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
