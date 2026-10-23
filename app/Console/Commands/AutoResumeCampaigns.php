<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CampaniaWhatsApp;
use App\Models\modalservicios;
use App\Models\WatModal;
use App\Jobs\SendWhatsAppCampaignJob;
use Illuminate\Support\Facades\Log;

class AutoResumeCampaigns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'campaign:auto-resume';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reanuda automáticamente campañas pausadas que cumplen los criterios (horario + límite diario)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Buscando campañas pausadas...');
        
        // Verificar si estamos en horario permitido
        if (!CampaniaWhatsApp::isWithinAllowedHours()) {
            $now = now()->timezone('America/Lima')->format('H:i');
            $nextStart = CampaniaWhatsApp::getNextStartTime()->format('Y-m-d H:i');
            
            $this->warn("Fuera de horario permitido (hora actual: {$now})");
            $this->warn("Próxima ventana: {$nextStart}");
            
            Log::info('Auto-resume: Fuera de horario', [
                'hora_actual' => $now,
                'proxima_ventana' => $nextStart
            ]);
            
            return Command::SUCCESS;
        }
        
        // Obtener campañas que pueden reanudarse
        $campaigns = CampaniaWhatsApp::getCampaignsReadyToResume();
        
        if ($campaigns->isEmpty()) {
            $this->info('✅ No hay campañas pendientes de reanudación');
            return Command::SUCCESS;
        }
        
        $this->info("📊 Encontradas {$campaigns->count()} campaña(s) para reanudar");
        
        // Verificar si hay campañas pausadas por falta de conexión
        $connectionCheckNeeded = $campaigns->contains(function ($campania) {
            return $campania->estado === 'pausada_sin_conexion';
        });
        
        // Si hay campañas pausadas por conexión, verificar que WhatsApp esté conectado
        $whatsappConnected = true;
        if ($connectionCheckNeeded) {
            $whatsappConnected = $this->checkWhatsAppConnection();
            
            if (!$whatsappConnected) {
                $this->warn('⚠️ WhatsApp no está conectado - campañas pausadas_sin_conexion no se reanudarán');
                
                // Filtrar campañas para excluir las pausadas_sin_conexion
                $campaigns = $campaigns->filter(function ($campania) {
                    return $campania->estado !== 'pausada_sin_conexion';
                });
                
                if ($campaigns->isEmpty()) {
                    $this->info('ℹ️ No hay otras campañas para reanudar');
                    return Command::SUCCESS;
                }
            } else {
                $this->info('✅ WhatsApp conectado - se reanudarán todas las campañas');
            }
        }
        
        foreach ($campaigns as $campania) {
            $this->info("🚀 Reanudando campaña #{$campania->id_campania}...");
            
            try {
                // Obtener destinatarios pendientes
                $enviadosIds = WatModal::where('campania_id', $campania->id_campania)
                    ->pluck('id_modalservicio')
                    ->toArray();
                
                $destinatariosQuery = modalservicios::where('id_servicio', $campania->id_servicio)
                    ->where('estado', 1)
                    ->whereNotNull('telefono')
                    ->where('telefono', '!=', '')
                    ->whereNotIn('id_modalservicio', $enviadosIds)
                    ->orderBy('id_modalservicio');

                if ($campania->envios_pendientes > 0) {
                    $destinatariosQuery->limit($campania->envios_pendientes);
                }

                $destinatarios = $destinatariosQuery->get()
                    ->unique('telefono')
                    ->map(function ($dest) {
                        return [
                            'id_modalservicio' => $dest->id_modalservicio,
                            'nombre' => $dest->nombre,
                            'telefono' => $dest->telefono,
                        ];
                    })
                    ->values()
                    ->toArray();
                
                if (empty($destinatarios)) {
                    $this->warn("⚠️ Campaña #{$campania->id_campania}: No hay destinatarios pendientes");
                    continue;
                }
                
                // Cambiar estado a 'en_proceso'
                $campania->update(['estado' => 'en_proceso']);
                
                // Despachar el Job
                SendWhatsAppCampaignJob::dispatch($campania, $destinatarios);
                
                $this->info("✅ Campaña #{$campania->id_campania} reanudada ({$campania->envios_pendientes} mensajes pendientes)");
                
                Log::info('Auto-resume: Campaña reanudada', [
                    'campania_id' => $campania->id_campania,
                    'estado_anterior' => $campania->getOriginal('estado'),
                    'pendientes' => $campania->envios_pendientes,
                    'envios_hoy' => $campania->envios_hoy
                ]);
                
            } catch (\Exception $e) {
                $this->error("❌ Error reanudando campaña #{$campania->id_campania}: {$e->getMessage()}");
                
                Log::error('Auto-resume: Error reanudando campaña', [
                    'campania_id' => $campania->id_campania,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
        
        $this->info('✅ Proceso completado');
        return Command::SUCCESS;
    }

    /**
     * Verifica si WhatsApp está conectado
     */
    private function checkWhatsAppConnection(): bool
    {
        try {
            $whatsappServiceUrl = env('WHATSAPP_API_URL', 'http://localhost:5111');
            $apiKey = env('WHATSAPP_SERVICE_API_KEY');

            $response = \Illuminate\Support\Facades\Http::timeout(5)
                ->withHeaders(['X-API-Key' => $apiKey])
                ->get($whatsappServiceUrl . '/api/whatsapp/status');

            if ($response->successful()) {
                $data = $response->json();
                $isConnected = $data['connected'] ?? false;
                
                if ($isConnected) {
                    Log::info('Auto-resume: Verificación de conexión WhatsApp exitosa');
                    return true;
                }
                
                Log::warning('Auto-resume: WhatsApp reporta desconectado');
                return false;
            }

            Log::warning('Auto-resume: WhatsApp no responde correctamente', [
                'status' => $response->status()
            ]);
            return false;

        } catch (\Exception $e) {
            Log::warning('Auto-resume: No se pudo verificar conexión WhatsApp', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
