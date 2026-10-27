<?php

namespace App\Jobs;

use App\Models\CampaniaWhatsApp;
use App\Models\WatModal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendWhatsAppCampaignJob implements ShouldQueue
{
    /**
     * Segundos de pausa entre el envío de cada mensaje individual.
     */
    private const DELAY_ENTRE_MENSAJES = 45;

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $campania;
    public $destinatarios;
    public $tries = 1; // Un solo intento para evitar que Laravel reintente toda la lista masiva si ocurre un error
    public $timeout = 3600; // 1 hora: procesamiento individual (~45s × 20 destinatarios × chunks + reintentos)

    /**
     * Constructor
     * @param CampaniaWhatsApp $campania
     * @param array $destinatarios Array con los registros de modalservicios
     */
    public function __construct(CampaniaWhatsApp $campania, array $destinatarios)
    {
        $this->campania = $campania;
        $this->destinatarios = $destinatarios;
    }

    /**
     * Ejecuta el Job
     */
    public function handle()
    {
        try {
            Log::info('Iniciando campaña WhatsApp', [
                'campania_id' => $this->campania->id_campania,
                'total_destinatarios' => count($this->destinatarios)
            ]);

            // Resetear contador diario si es un nuevo día
            $this->campania->resetDailyCounterIfNeeded();

            $this->campania->refresh();
            if (in_array($this->campania->estado, ['cancelada', 'eliminada', 'pausada', 'pausada_sin_conexion', 'pausada_hasta_mañana', 'pausada_fuera_horario'])) {
                Log::info("Job SendWhatsAppCampaignJob abortado para campaña #{$this->campania->id_campania} por estado: {$this->campania->estado}");
                return;
            }

            // Actualizar estado de la campaña
            $this->campania->update(['estado' => 'en_proceso']);

            // Dividir destinatarios en chunks de 20 (límite diario considerado)
            $chunks = array_chunk($this->destinatarios, 20);
            $totalChunks = count($chunks);

            Log::info('Campaña dividida en chunks', [
                'campania_id' => $this->campania->id_campania,
                'total_chunks' => $totalChunks,
                'chunk_size' => 20
            ]);

            // Procesar cada chunk
            foreach ($chunks as $index => $chunk) {
                $chunkNumber = $index + 1;

                // VERIFICAR VENTANA HORARIA (8am-11pm Peru GMT-5)
                if (!CampaniaWhatsApp::isWithinAllowedHours()) {
                    $nextStart = CampaniaWhatsApp::getNextStartTime();

                    Log::info('Fuera de horario permitido - pausando campaña', [
                        'campania_id' => $this->campania->id_campania,
                        'hora_actual' => now()->timezone('America/Lima')->format('H:i'),
                        'proxima_reanudacion' => $nextStart->format('Y-m-d H:i'),
                        'chunks_restantes' => $totalChunks - $index
                    ]);

                    // Pausar campaña fuera de horario
                    $this->campania->update(['estado' => 'pausada_fuera_horario']);
                    break; // Salir del loop
                }

                // VERIFICAR LÍMITE DIARIO ANTES DE PROCESAR CHUNK
                $this->campania->refresh(); // Actualizar datos desde DB
                $remainingQuota = $this->campania->getRemainingDailyQuota();

                if ($remainingQuota <= 0) {
                    Log::info('Límite diario alcanzado - pausando campaña', [
                        'campania_id' => $this->campania->id_campania,
                        'envios_hoy' => $this->campania->envios_hoy,
                        'chunks_restantes' => $totalChunks - $index
                    ]);

                    // Pausar campaña hasta mañana
                    $this->campania->update(['estado' => 'pausada_hasta_mañana']);
                    break; // Salir del loop
                }

                // Ajustar chunk si excede el límite diario
                $chunkedRecipients = $chunk;
                if (count($chunk) > $remainingQuota) {
                    $chunkedRecipients = array_slice($chunk, 0, $remainingQuota);
                    Log::info('Chunk ajustado por límite diario', [
                        'campania_id' => $this->campania->id_campania,
                        'original_size' => count($chunk),
                        'adjusted_size' => count($chunkedRecipients),
                        'remaining_quota' => $remainingQuota
                    ]);
                }

                Log::info("Procesando chunk {$chunkNumber}/{$totalChunks}", [
                    'campania_id' => $this->campania->id_campania,
                    'destinatarios_en_chunk' => count($chunkedRecipients),
                    'envios_hoy_actual' => $this->campania->envios_hoy
                ]);

                $this->processChunk($chunkedRecipients, $chunkNumber);

                // Verificar si la campaña fue pausada o cancelada durante el processChunk
                $this->campania->refresh();
                if (in_array($this->campania->estado, ['cancelada', 'eliminada', 'pausada', 'pausada_sin_conexion', 'pausada_hasta_mañana', 'pausada_fuera_horario'])) {
                    Log::info('Campaña detenida o pausada - deteniendo procesamiento de chunks restantes', [
                        'campania_id' => $this->campania->id_campania,
                        'estado' => $this->campania->estado,
                        'chunks_restantes' => $totalChunks - $chunkNumber
                    ]);
                    break; // Salir del loop de chunks
                }

                // PAUSA DE 2 MINUTOS ENTRE CHUNKS
                if ($chunkNumber < $totalChunks) {
                    sleep(120); // 2 minutos
                }
            }

            // 🔁 FASE 3: Procesar reintentos para mensajes fallidos
            // NO procesar reintentos si la campaña fue pausada o cancelada
            $this->campania->refresh();
            if (in_array($this->campania->estado, ['cancelada', 'eliminada', 'pausada', 'pausada_sin_conexion', 'pausada_hasta_mañana', 'pausada_fuera_horario'])) {
                Log::info('Campaña pausada o cancelada - omitiendo procesamiento de reintentos', [
                    'campania_id' => $this->campania->id_campania,
                    'estado' => $this->campania->estado
                ]);
            } else {
                $this->processRetries();
            }

            // Finalizar campaña
            $this->campania->updateStatus();

            Log::info('Campaña WhatsApp finalizada', [
                'campania_id' => $this->campania->id_campania,
                'estado' => $this->campania->estado,
                'exitosos' => $this->campania->envios_exitosos,
                'fallidos' => $this->campania->envios_fallidos
            ]);

        } catch (\Exception $e) {
            Log::error('Error en SendWhatsAppCampaignJob', [
                'campania_id' => $this->campania->id_campania,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->campania->update(['estado' => 'error']);
            throw $e;
        }
    }

    /**
     * Procesa un chunk de destinatarios enviando UN MENSAJE A LA VEZ.
     * Esto permite actualizar los contadores en la BD después de cada envío,
     * logrando que la barra de progreso del frontend se actualice en tiempo real.
     */
    private function processChunk(array $chunk, int $chunkNumber)
    {
        $totalEnChunk = count($chunk);

        Log::info("Iniciando envío individual en chunk {$chunkNumber}", [
            'campania_id'    => $this->campania->id_campania,
            'total_en_chunk' => $totalEnChunk,
        ]);

        foreach ($chunk as $posicion => $destinatario) {
            // --- Verificar conexión / estado antes de cada mensaje ---
            $this->campania->refresh();
            if (in_array($this->campania->estado, ['cancelada', 'eliminada', 'pausada', 'pausada_sin_conexion', 'pausada_hasta_mañana', 'pausada_fuera_horario'])) {
                Log::info("Campaña detenida por estado ({$this->campania->estado}) - deteniendo envíos restantes en chunk", [
                    'campania_id'      => $this->campania->id_campania,
                    'pendientes_chunk' => $totalEnChunk - $posicion,
                ]);
                return;
            }

            // IDEMPOTENCIA: Verificar si ya se envió exitosamente a este destinatario en esta campaña
            $yaEnviado = WatModal::where('campania_id', $this->campania->id_campania)
                ->where('id_modalservicio', $destinatario['id_modalservicio'])
                ->where('estado', 1)
                ->exists();

            if ($yaEnviado) {
                Log::info("Destinatario #{$destinatario['id_modalservicio']} ya recibió mensaje en campaña #{$this->campania->id_campania}, omitiendo duplicado.");
                continue;
            }

            // Verificar límite diario por cada mensaje
            if ($this->campania->getRemainingDailyQuota() <= 0) {
                Log::info('Límite diario alcanzado durante chunk - pausando', [
                    'campania_id' => $this->campania->id_campania,
                    'envios_hoy'  => $this->campania->envios_hoy,
                ]);
                $this->campania->update(['estado' => 'pausada_hasta_mañana']);
                return;
            }

            // --- Preparar payload para UN solo destinatario ---
            $testNumber = config('services.whatsapp.test_number') ?: env('WHATSAPP_TEST_NUMBER');
            if (!empty($testNumber) && $posicion > 0) {
                // En modo Sandbox, si la campaña proviene de datos anteriores con múltiples contactos,
                // enviamos el primer mensaje al número de prueba y los demás se simulan para evitar spam
                Log::info("Modo Sandbox: Omitiendo envío repetido al teléfono de prueba para destinatario #{$destinatario['id_modalservicio']}");
                $this->campania->increment('envios_exitosos');
                $this->campania->decrement('envios_pendientes');
                $this->campania->increment('envios_hoy');
                $this->campania->update([
                    'fecha_ultimo_envio' => now()->timezone('America/Lima')->toDateString()
                ]);

                WatModal::create([
                    'id_modalservicio' => $destinatario['id_modalservicio'],
                    'number_message'   => 1,
                    'estado'           => 1,
                    'error'            => 'Simulado (Modo Sandbox)',
                    'fecha'            => now(),
                    'campania_id'      => $this->campania->id_campania,
                    'intentos'         => 1,
                    'puede_reintentar' => false,
                ]);
                continue;
            }

            $telefonoDestino = !empty($testNumber)
                ? formatearTelefonoWhatsApp($testNumber)
                : formatearTelefonoWhatsApp($destinatario['telefono']);

            $payload = [
                'campania_id'  => $this->campania->id_campania,
                'chunk_number' => $chunkNumber,
                'recipients'   => [
                    [
                        'id_modalservicio' => $destinatario['id_modalservicio'],
                        'nombre'           => $destinatario['nombre'] . (!empty($testNumber) ? ' (Sandbox)' : ''),
                        'telefono'         => $telefonoDestino,
                    ]
                ],
                'message'      => $this->campania->parrafo,
                'image_url'    => $this->campania->imagen_url,
                'id_servicio'  => $this->campania->id_servicio,
            ];

            try {
                // Timeout de 120s por mensaje: 45s envío + margen holgado
                $response = Http::timeout(120)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept'       => 'application/json',
                        'X-API-Key'    => env('WHATSAPP_SERVICE_API_KEY'),
                    ])
                    ->post(env('WHATSAPP_API_URL') . '/api/whatsapp/send-campaign-batch', $payload);

                if ($response->successful()) {
                    $result  = $response->json();
                    $exitoso = ($result['successful'] ?? 0) > 0;
                    $fallido = !$exitoso;

                    // ⚡ Detectar desconexión reportada por el servicio
                    if (isset($result['paused']) && $result['paused'] === true) {
                        Log::warning("WhatsApp desconectado durante envío individual - pausando campaña", [
                            'campania_id'      => $this->campania->id_campania,
                            'id_modalservicio' => $destinatario['id_modalservicio'],
                        ]);
                        $this->campania->update([
                            'estado'            => 'pausada_sin_conexion',
                            'fecha_ultimo_envio' => now()->timezone('America/Lima')->toDateString(),
                        ]);
                        return;
                    }

                    // ✅ Actualizar BD inmediatamente tras cada mensaje
                    if ($exitoso) {
                        $this->campania->increment('envios_exitosos');
                        if ($this->campania->envios_pendientes > 0) {
                            $this->campania->decrement('envios_pendientes');
                        }
                        $this->campania->increment('envios_hoy');
                    } else {
                        $this->campania->increment('envios_fallidos');
                        if ($this->campania->envios_pendientes > 0) {
                            $this->campania->decrement('envios_pendientes');
                        }
                        $this->campania->increment('envios_hoy');
                    }
                    $this->campania->update([
                        'fecha_ultimo_envio' => now()->timezone('America/Lima')->toDateString()
                    ]);

                    $resultadoDestinatario = $result['results'][$destinatario['id_modalservicio']] ?? null;
                    $errorMsg = $exitoso ? null : ($resultadoDestinatario['error'] ?? 'Error desconocido');

                    WatModal::create([
                        'id_modalservicio' => $destinatario['id_modalservicio'],
                        'number_message'   => 1,
                        'estado'           => $exitoso ? 1 : 0,
                        'error'            => $errorMsg,
                        'fecha'            => now(),
                        'campania_id'      => $this->campania->id_campania,
                        'intentos'         => 1,
                        'puede_reintentar' => $fallido,
                    ]);

                    Log::info("📨 Mensaje enviado", [
                        'campania_id'      => $this->campania->id_campania,
                        'id_modalservicio' => $destinatario['id_modalservicio'],
                        'exitoso'          => $exitoso,
                        'progreso'         => ($posicion + 1) . '/' . $totalEnChunk . ' en chunk',
                        'envios_exitosos'  => $this->campania->fresh()->envios_exitosos,
                    ]);

                    // Detectar desconexión por errores en resultado
                    if ($fallido && $resultadoDestinatario) {
                        $errorDestinatario = strtolower($resultadoDestinatario['error'] ?? '');
                        if (
                            str_contains($errorDestinatario, 'not connected') ||
                            str_contains($errorDestinatario, 'no está conectado') ||
                            str_contains($errorDestinatario, 'desconectado')
                        ) {
                            Log::warning("Desconexión detectada en resultado - pausando campaña", [
                                'campania_id' => $this->campania->id_campania,
                            ]);
                            $this->campania->update([
                                'estado'            => 'pausada_sin_conexion',
                                'fecha_ultimo_envio' => now()->timezone('America/Lima')->toDateString(),
                            ]);
                            return;
                        }
                    }

                } else {
                    // Error HTTP del request
                    $responseBody   = $response->body();
                    $responseStatus = $response->status();

                    Log::error("Error HTTP enviando mensaje individual", [
                        'campania_id'      => $this->campania->id_campania,
                        'id_modalservicio' => $destinatario['id_modalservicio'],
                        'status'           => $responseStatus,
                        'body'             => $responseBody,
                    ]);

                    // Detectar desconexión de WhatsApp
                    if (
                        $responseStatus === 500 &&
                        (str_contains(strtolower($responseBody), 'no está conectado') ||
                            str_contains(strtolower($responseBody), 'not connected'))
                    ) {
                        Log::warning("WhatsApp desconectado (HTTP 500) - pausando campaña", [
                            'campania_id' => $this->campania->id_campania,
                        ]);
                        $this->campania->update(['estado' => 'pausada_sin_conexion']);
                        return;
                    }

                    // Otro error: registrar como fallido y continuar con el siguiente
                    $this->campania->increment('envios_fallidos');
                    if ($this->campania->envios_pendientes > 0) {
                        $this->campania->decrement('envios_pendientes');
                    }
                    $this->campania->increment('envios_hoy');

                    WatModal::create([
                        'id_modalservicio' => $destinatario['id_modalservicio'],
                        'number_message'   => 1,
                        'estado'           => 0,
                        'error'            => 'HTTP ' . $responseStatus . ' - ' . substr($responseBody, 0, 200),
                        'fecha'            => now(),
                        'campania_id'      => $this->campania->id_campania,
                        'intentos'         => 1,
                        'puede_reintentar' => true,
                    ]);
                }

            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();

                if (str_contains($errorMessage, 'timeout') || str_contains($errorMessage, 'timed out')) {
                    Log::warning("Timeout enviando mensaje individual - puede haberse enviado", [
                        'campania_id'      => $this->campania->id_campania,
                        'id_modalservicio' => $destinatario['id_modalservicio'],
                        'error'            => $errorMessage,
                    ]);
                } else {
                    Log::error("Error enviando mensaje individual", [
                        'campania_id'      => $this->campania->id_campania,
                        'id_modalservicio' => $destinatario['id_modalservicio'],
                        'error'            => $errorMessage,
                    ]);
                    $this->campania->increment('envios_fallidos');
                    $this->campania->decrement('envios_pendientes');
                    $this->campania->increment('envios_hoy');
                }

                WatModal::create([
                    'id_modalservicio' => $destinatario['id_modalservicio'],
                    'number_message'   => 1,
                    'estado'           => 0,
                    'error'            => substr($errorMessage, 0, 255),
                    'fecha'            => now(),
                    'campania_id'      => $this->campania->id_campania,
                    'intentos'         => 1,
                    'puede_reintentar' => true,
                ]);
            }

            // ⏱ Pausa entre mensajes para evitar bloqueos de WhatsApp por spam.
            // Solo se aplica si NO es el último destinatario del chunk.
            if ($posicion < $totalEnChunk - 1) {
                Log::info('Pausa entre mensajes', [
                    'campania_id' => $this->campania->id_campania,
                    'segundos'    => self::DELAY_ENTRE_MENSAJES,
                    'siguiente'   => ($posicion + 2) . '/' . $totalEnChunk,
                ]);
                sleep(self::DELAY_ENTRE_MENSAJES);
            }
        } // end foreach destinatario

        Log::info("Chunk {$chunkNumber} procesado (envío individual)", [
            'campania_id'    => $this->campania->id_campania,
            'total_en_chunk' => $totalEnChunk,
            'envios_hoy'     => $this->campania->fresh()->envios_hoy,
        ]);
    }

    /**
     * 🔁 FASE 3: Procesa reintentos para mensajes fallidos (máximo 3 intentos)
     */
    private function processRetries()
    {
        $maxRetries = 3;

        for ($intento = 2; $intento <= $maxRetries; $intento++) {
            // Obtener mensajes fallidos del intento anterior
            $fallidosPendientes = WatModal::where('campania_id', $this->campania->id_campania)
                ->where('estado', 0)
                ->where('intentos', $intento - 1)
                ->where('puede_reintentar', true)
                ->with('modalServicio')
                ->get();

            if ($fallidosPendientes->isEmpty()) {
                Log::info("No hay mensajes para reintento #{$intento}", [
                    'campania_id' => $this->campania->id_campania
                ]);
                continue; // No hay nada que reintentar
            }

            Log::info("Iniciando reintento #{$intento}", [
                'campania_id' => $this->campania->id_campania,
                'total_a_reintentar' => $fallidosPendientes->count()
            ]);

            // Dividir en chunks de 20 para reintentos
            $retryChunks = $fallidosPendientes->chunk(20);

            foreach ($retryChunks as $chunkIndex => $retryChunk) {
                // Verificar estado de campaña antes de procesar reintentos
                $this->campania->refresh();
                if (in_array($this->campania->estado, ['cancelada', 'eliminada', 'pausada', 'pausada_sin_conexion', 'pausada_hasta_mañana', 'pausada_fuera_horario'])) {
                    Log::info("Reintentos abortados por estado de campaña ({$this->campania->estado})", [
                        'campania_id' => $this->campania->id_campania
                    ]);
                    return;
                }

                // Verificar límite diario
                $remainingQuota = $this->campania->getRemainingDailyQuota();

                if ($remainingQuota <= 0) {
                    Log::warning("Límite diario alcanzado durante reintento #{$intento}", [
                        'campania_id' => $this->campania->id_campania,
                        'envios_hoy' => $this->campania->envios_hoy
                    ]);
                    $this->campania->update(['estado' => 'pausada_hasta_mañana']);
                    return; // Detener reintentos por hoy
                }

                // Preparar destinatarios para reintento
                $testNumberRetry = config('services.whatsapp.test_number') ?: env('WHATSAPP_TEST_NUMBER');
                $recipients = $retryChunk->map(function ($watModal) use ($testNumberRetry) {
                    $modalServicio = $watModal->modalServicio;
                    $tel = !empty($testNumberRetry)
                        ? formatearTelefonoWhatsApp($testNumberRetry)
                        : formatearTelefonoWhatsApp($modalServicio->telefono);
                    return [
                        'id_modalservicio' => $modalServicio->id_modalservicio,
                        'nombre' => $modalServicio->nombre . (!empty($testNumberRetry) ? ' (Sandbox)' : ''),
                        'telefono' => $tel,
                        'wat_modal_id' => $watModal->id_modal_wat, // Para actualizar después
                    ];
                })->toArray();

                $payload = [
                    'campania_id' => $this->campania->id_campania,
                    'chunk_number' => $chunkIndex + 1,
                    'recipients' => $recipients,
                    'message' => $this->campania->parrafo,
                    'image_url' => $this->campania->imagen_url,
                    'id_servicio' => $this->campania->id_servicio,
                    'is_retry' => true, // 🔁 Marcar como reintento
                    'retry_attempt' => $intento,
                ];

                try {
                    $response = Http::timeout(600)
                        ->withHeaders([
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json',
                            'X-API-Key' => env('WHATSAPP_SERVICE_API_KEY'),
                        ])
                        ->post(env('WHATSAPP_API_URL') . '/api/whatsapp/send-campaign-batch', $payload);

                    if ($response->successful()) {
                        $result = $response->json();
                        $exitosos = $result['successful'] ?? 0;
                        $fallidos = $result['failed'] ?? 0;

                        // Actualizar contadores de campaña
                        $this->campania->increment('envios_exitosos', $exitosos);
                        $this->campania->increment('envios_hoy', $exitosos + $fallidos);
                        $this->campania->update(['fecha_ultimo_envio' => now()->timezone('America/Lima')->toDateString()]);

                        // Actualizar registros de WatModal
                        foreach ($retryChunk as $watModal) {
                            $idModalServicio = $watModal->id_modalservicio;
                            $success = $result['results'][$idModalServicio]['success'] ?? false;

                            if ($success) {
                                // Exitoso en reintento
                                $watModal->update([
                                    'estado' => 1,
                                    'error' => null,
                                    'intentos' => $intento,
                                    'puede_reintentar' => false,
                                ]);
                            } else {
                                // Falló nuevamente
                                $watModal->update([
                                    'estado' => 0,
                                    'error' => $result['results'][$idModalServicio]['error'] ?? "Falló en intento #{$intento}",
                                    'intentos' => $intento,
                                    'puede_reintentar' => $intento < $maxRetries,
                                ]);
                            }
                        }

                        Log::info("Reintento #{$intento} - Chunk procesado", [
                            'campania_id' => $this->campania->id_campania,
                            'exitosos' => $exitosos,
                            'fallidos' => $fallidos
                        ]);

                    } else {
                        // Error en el request de reintento
                        Log::error("Reintento #{$intento} - Request falló", [
                            'campania_id' => $this->campania->id_campania,
                            'status' => $response->status()
                        ]);

                        // Incrementar intentos sin cambiar estado
                        foreach ($retryChunk as $watModal) {
                            $watModal->update([
                                'intentos' => $intento,
                                'error' => "Request falló en intento #{$intento}: HTTP " . $response->status(),
                                'puede_reintentar' => $intento < $maxRetries,
                            ]);
                        }
                    }

                } catch (\Exception $e) {
                    Log::error("Excepción en reintento #{$intento}", [
                        'campania_id' => $this->campania->id_campania,
                        'error' => $e->getMessage()
                    ]);

                    // Marcar como no reintentables si es el último intento
                    foreach ($retryChunk as $watModal) {
                        $watModal->update([
                            'intentos' => $intento,
                            'error' => "Excepción en intento #{$intento}: " . $e->getMessage(),
                            'puede_reintentar' => $intento < $maxRetries,
                        ]);
                    }
                }

                // Pausa entre chunks de reintento
                if ($chunkIndex < $retryChunks->count() - 1) {
                    sleep(120); // 2 minutos
                }
            }

            // Pausa entre intentos
            if ($intento < $maxRetries) {
                Log::info("Pausa de 5 minutos antes del siguiente reintento", [
                    'campania_id' => $this->campania->id_campania
                ]);
                sleep(300); // 5 minutos entre intentos completos
            }
        }

        Log::info('Reintentos completados', [
            'campania_id' => $this->campania->id_campania,
            'retry_stats' => $this->campania->getRetryStats()
        ]);
    }

    /**
     * Manejo de fallos del Job
     */
    public function failed(\Throwable $exception)
    {
        Log::error('SendWhatsAppCampaignJob falló definitivamente', [
            'campania_id' => $this->campania->id_campania,
            'error' => $exception->getMessage()
        ]);

        $this->campania->update([
            'estado' => 'error',
            'fecha_fin' => now()
        ]);
    }
}
