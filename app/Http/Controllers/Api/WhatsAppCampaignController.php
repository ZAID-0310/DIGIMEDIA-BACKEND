<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WhatsAppCampaignService;
use App\DTOs\WhatsAppCampaign\CreateCampaignDTO;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class WhatsAppCampaignController extends Controller
{
    public function __construct(
        private WhatsAppCampaignService $whatsAppCampaignService
    ) {}

    /**
     * Crea una campaña de WhatsApp en estado BORRADOR
     */
    public function createCampaign(Request $request): JsonResponse
    {
        try {
            // Validar datos de entrada
            $request->validate([
                'service' => 'required|string|in:p1,p2,p3,p4',
                'paragraph' => 'required|string|min:10|max:1000',
                'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048', // 2MB
            ]);

            $dto = CreateCampaignDTO::fromRequest($request);
            $campania = $this->whatsAppCampaignService->createCampaign($dto, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Campaña creada exitosamente en borrador',
                'data' => [
                    'campania_id' => $campania->id_campania,
                    'total_destinatarios' => $campania->total_destinatarios,
                    'estado' => $campania->estado,
                    'servicio' => $campania->servicio->nombre ?? 'Desconocido'
                ]
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errores de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            $code = $e->getCode();
            $statusCode = in_array($code, [400, 422]) ? $code : 500;
            return response()->json([
                'success' => false,
                'message' => $statusCode === 500 ? 'Error interno del servidor' : $e->getMessage(),
                'error' => $e->getMessage()
            ], $statusCode);
        }
    }

    /**
     * Inicia una campaña desde BORRADOR o PAUSADA (con validación FIFO)
     */
    public function startCampaign(Request $request, $id): JsonResponse
    {
        try {
            $campania = $this->whatsAppCampaignService->startCampaign((int)$id, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Campaña iniciada exitosamente',
                'data' => [
                    'campania_id' => $campania->id_campania,
                    'estado' => $campania->estado,
                    'total_destinatarios' => $campania->total_destinatarios
                ]
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Campaña no encontrada'
            ], 404);
        } catch (\App\Exceptions\CampaignConflictException $e) {
            $activeCampaign = $e->getActiveCampaign();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 'campaign_active',
                'active_campaign' => $activeCampaign ? [
                    'id' => $activeCampaign->id_campania,
                    'servicio' => $activeCampaign->servicio->nombre ?? 'Desconocido',
                    'estado' => $activeCampaign->estado,
                    'progreso' => $activeCampaign->getProgressPercentage() . '%'
                ] : null
            ], 409);
        } catch (\App\Exceptions\WhatsAppConnectionException $e) {
            $status = $e->getWhatsappStatus();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 'whatsapp_not_connected',
                'details' => $status['message'] ?? 'Servicio WhatsApp no disponible'
            ], 400);
        } catch (\Exception $e) {
            $code = $e->getCode();
            $statusCode = in_array($code, [400, 403, 422]) ? $code : 500;
            return response()->json([
                'success' => false,
                'message' => $statusCode === 500 ? 'Error al iniciar campaña' : $e->getMessage(),
                'error' => $e->getMessage()
            ], $statusCode);
        }
    }

    /**
     * Devuelve el total de destinatarios para un servicio (sin crear campaña)
     */
    public function previewCampaign(Request $request, $service): JsonResponse
    {
        try {
            $result = $this->whatsAppCampaignService->previewCampaign($service);

            return response()->json([
                'success' => true,
                'data' => [
                    'service' => $result['service'],
                    'total_destinatarios' => $result['total_destinatarios'],
                ]
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al previsualizar la campaña',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtiene el estado de una campaña
     */
    public function getCampaignStatus($id): JsonResponse
    {
        try {
            $campania = $this->whatsAppCampaignService->getCampaignStatus((int)$id);

            return response()->json([
                'success' => true,
                'data' => [
                    'id_campania' => $campania->id_campania,
                    'servicio' => $campania->servicio->nombre ?? 'Desconocido',
                    'estado' => $campania->estado,
                    'progreso' => [
                        'total' => $campania->total_destinatarios,
                        'exitosos' => $campania->envios_exitosos,
                        'fallidos' => $campania->envios_fallidos,
                        'pendientes' => $campania->envios_pendientes,
                        'porcentaje' => $campania->getProgressPercentage()
                    ],
                    'envios_hoy' => $campania->envios_hoy ?? 0,
                    'limite_diario' => $campania->limite_diario ?? 50,
                    'fechas' => [
                        'inicio' => $campania->fecha_inicio,
                        'fin' => $campania->fecha_fin,
                        'creacion' => $campania->created_at
                    ]
                ]
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Campaña no encontrada'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estado de campaña',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Lista las campañas recientes con info de campaña activa
     */
    public function listCampaigns(Request $request): JsonResponse
    {
        try {
            $limit = $request->get('limit', 10);
            $result = $this->whatsAppCampaignService->listCampaigns((int)$limit);

            $activeCampaign = $result['active_campaign'];
            $campanias = $result['campanias'];

            return response()->json([
                'success' => true,
                'active_campaign' => $activeCampaign ? [
                    'id_campania' => $activeCampaign->id_campania,
                    'servicio' => $activeCampaign->servicio->nombre ?? 'Desconocido',
                    'estado' => $activeCampaign->estado,
                    'progreso' => $activeCampaign->getProgressPercentage(),
                    'envios_hoy' => $activeCampaign->envios_hoy,
                ] : null,
                'data' => [
                    'campanias' => $campanias->map(function ($campania) {
                        return [
                            'id_campania' => $campania->id_campania,
                            'servicio' => $campania->servicio->nombre ?? 'Desconocido',
                            'estado' => $campania->estado,
                            'total_destinatarios' => $campania->total_destinatarios,
                            'envios_exitosos' => $campania->envios_exitosos,
                            'envios_fallidos' => $campania->envios_fallidos,
                            'envios_pendientes' => $campania->envios_pendientes,
                            'envios_hoy' => $campania->envios_hoy,
                            'porcentaje' => $campania->getProgressPercentage(),
                            'fecha_inicio' => $campania->fecha_inicio,
                            'fecha_fin' => $campania->fecha_fin,
                            'fecha_ultimo_envio' => $campania->fecha_ultimo_envio,
                            'can_be_started' => $campania->canBeStarted(),
                            'created_at' => $campania->created_at,
                        ];
                    })
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al listar campañas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * TEST: Endpoint para probar subida de imágenes a Cloudinary
     * URL: POST /api/test-cloudinary
     */
    public function testCloudinaryUpload(Request $request): JsonResponse
    {
        try {
            // Validar que se envió una imagen
            $request->validate([
                'image' => 'required|image|mimes:jpg,jpeg,png,webp,gif|max:5120'
            ]);

            $result = $this->whatsAppCampaignService->testCloudinaryUpload($request->file('image'));

            return response()->json([
                'success' => true,
                'message' => 'Imagen subida exitosamente a Cloudinary',
                'data' => $result
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Archivo inválido',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al subir imagen',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Pausa manualmente una campaña activa
     */
    public function pauseCampaign(int $id, Request $request): JsonResponse
    {
        try {
            $campania = $this->whatsAppCampaignService->pauseCampaign($id, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Campaña pausada exitosamente',
                'data' => [
                    'id_campania' => $campania->id_campania,
                    'estado' => $campania->estado,
                ]
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Campaña no encontrada'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Reanuda manualmente una campaña pausada
     */
    public function resumeCampaign(int $id, Request $request): JsonResponse
    {
        try {
            $campania = $this->whatsAppCampaignService->resumeCampaign($id, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Campaña reanudada exitosamente',
                'data' => [
                    'id_campania' => $campania->id_campania,
                    'estado' => $campania->estado,
                ]
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Campaña no encontrada'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Cancela definitivamente una campaña
     */
    public function cancelCampaign(int $id, Request $request): JsonResponse
    {
        try {
            $campania = $this->whatsAppCampaignService->cancelCampaign($id, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Campaña cancelada exitosamente',
                'data' => [
                    'id_campania' => $campania->id_campania,
                    'estado' => $campania->estado,
                ]
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Campaña no encontrada'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}


