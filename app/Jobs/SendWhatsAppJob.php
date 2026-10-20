<?php

namespace App\Jobs;

use App\Models\WatModal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendWhatsAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public WatModal $watModal,
        public array $data,
        public int $id_servicio,
        public ?int $id_subservicio = null,
    ) {}

    public function handle(): void
    {
        try {
            if (!$this->watModal) {
                Log::error('WatModal no encontrado');
                return;
            }

            $endpoint = rtrim(config('services.whatsapp.url'), '/') . '/api/whatsapp/send-message';

            $testNumber = config('services.whatsapp.test_number') ?: env('WHATSAPP_TEST_NUMBER');
            $telefonoDestino = !empty($testNumber)
                ? formatearTelefonoWhatsApp($testNumber)
                : ('51' . $this->data['telefono']);

            $payload = [
                'telefono'       => $telefonoDestino,
                'nombre'         => $this->data['nombre'] . (!empty($testNumber) ? ' (Sandbox)' : ''),
                'templateOption' => (int) $this->watModal->number_message,
                'id_servicio'    => (int) $this->id_servicio,
            ];

            if ($this->id_subservicio) {
                $payload['id_subservicio'] = (int) $this->id_subservicio;
            }

            Log::info('Enviando WhatsApp', ['url' => $endpoint, 'payload' => $payload]);

            $response = Http::withHeaders([
                'X-API-Key' => env('WHATSAPP_SERVICE_API_KEY'),
            ])->post($endpoint, $payload);

            Log::info('Respuesta WhatsApp', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            if ($response->failed()) {
                throw new \Exception($response->body());
            }

            $this->watModal->update([
                'estado' => 1,
                'fecha'  => now(),
            ]);

        } catch (\Exception $e) {
            $this->watModal->update([
                'estado' => 1,
                'error'  => $e->getMessage(),
                'fecha'  => now(),
            ]);

            Log::error('Error WhatsApp', [
                'id_modal_wat' => $this->watModal->id_modal_wat,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
