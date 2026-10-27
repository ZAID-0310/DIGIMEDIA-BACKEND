<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CampaniaWhatsApp extends Model
{
    use HasFactory;

    protected $table = 'campanias_whatsapp';
    protected $primaryKey = 'id_campania';

    protected $fillable = [
        'id_servicio',
        'user_id',
        'deleted_by',
        'parrafo',
        'imagen_url',
        'estado',
        'total_destinatarios',
        'envios_exitosos',
        'envios_fallidos',
        'envios_pendientes',
        'envios_hoy',
        'fecha_ultimo_envio',
        'fecha_inicio',
        'fecha_fin',
        'fecha_eliminada',
    ];

    protected $casts = [
        'fecha_inicio' => 'datetime',
        'fecha_fin' => 'datetime',
        'fecha_eliminada' => 'datetime',
        'fecha_ultimo_envio' => 'date',
        'total_destinatarios' => 'integer',
        'envios_exitosos' => 'integer',
        'envios_fallidos' => 'integer',
        'envios_pendientes' => 'integer',
        'envios_hoy' => 'integer',
    ];

    /**
     * Relación con servicios
     */
    public function servicio()
    {
        return $this->belongsTo(servicios::class, 'id_servicio', 'id_servicio');
    }

    /**
     * Relación con usuario (quién creó la campaña)
     */
    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Relación con usuario (quién eliminó la campaña)
     */
    public function usuarioEliminador()
    {
        return $this->belongsTo(User::class, 'deleted_by', 'id');
    }

    /**
     * Verifica si la campaña está completada
     */
    public function isCompleted(): bool
    {
        return $this->estado === 'completada';
    }

    /**
     * Verifica si la campaña está en proceso
     */
    public function isInProgress(): bool
    {
        return $this->estado === 'en_proceso';
    }

    /**
     * Calcula el porcentaje de avance
     */
    public function getProgressPercentage(): float
    {
        if ($this->total_destinatarios === 0) {
            return 0;
        }

        $procesados = $this->envios_exitosos + $this->envios_fallidos;
        return round(($procesados / $this->total_destinatarios) * 100, 2);
    }

    /**
     * Actualiza el estado según el progreso
     */
    public function updateStatus(): void
    {
        // Refresh para obtener datos actualizados
        $this->refresh();

        // Si ya está en un estado final o pausado, no sobreescribir
        if (in_array($this->estado, ['cancelada', 'eliminada', 'pausada', 'pausada_sin_conexion', 'pausada_hasta_mañana', 'pausada_fuera_horario'])) {
            return;
        }

        $procesados = $this->envios_exitosos + $this->envios_fallidos;

        // Caso 1: Ya se procesaron todos los destinatarios → COMPLETADA
        if ($procesados >= $this->total_destinatarios) {
            $this->estado = 'completada';
            $this->fecha_fin = now();
            $this->save();
            return;
        }

        // Caso 2: Quedan pendientes pero alcanzó límite diario → PAUSAR HASTA MAÑANA
        if ($this->envios_pendientes > 0 && $this->envios_hoy >= 50) {
            $this->estado = 'pausada_hasta_mañana';
            $this->save();
            return;
        }

        // Caso 3: Quedan pendientes pero está fuera de horario → PAUSAR FUERA DE HORARIO
        if ($this->envios_pendientes > 0 && !self::isWithinAllowedHours()) {
            $this->estado = 'pausada_fuera_horario';
            $this->save();
            return;
        }

        // Caso 4: Quedan pendientes, dentro de horario y con cuota → MANTENER EN_PROCESO
        // (Esto puede pasar si el Job terminó prematuramente por error)
        $this->save();
    }

    /**
     * Verifica si se alcanzó el límite diario (50 mensajes)
     */
    public function hasReachedDailyLimit(): bool
    {
        return $this->envios_hoy >= 50;
    }

    /**
     * Resetea el contador diario si cambió de día
     */
    public function resetDailyCounterIfNeeded(): void
    {
        $today = now()->timezone('America/Lima')->format('Y-m-d');
        $lastSendDate = $this->fecha_ultimo_envio ? \Carbon\Carbon::parse($this->fecha_ultimo_envio)->format('Y-m-d') : null;

        if ($lastSendDate !== $today) {
            $this->envios_hoy = 0;
            $this->save();
        }
    }

    /**
     * Obtiene cuántos mensajes puede enviar hoy
     */
    public function getRemainingDailyQuota(): int
    {
        $this->resetDailyCounterIfNeeded();
        return max(0, 50 - $this->envios_hoy);
    }

    /**
     * Verifica si hay alguna campaña activa en proceso (FIFO)
     */
    public static function hasActiveCampaign(): bool
    {
        return self::whereIn('estado', ['en_proceso', 'pendiente', 'pausada', 'pausada_hasta_mañana', 'pausada_fuera_horario', 'pausada_sin_conexion'])
            ->exists();
    }

    /**
     * Obtiene la campaña activa actualmente (si existe)
     * Prioriza la que está efectivamente en proceso/pendiente, y luego la pausada más reciente
     */
    public static function getActiveCampaign(): ?self
    {
        // 1. Prioridad: la que realmente está en proceso de envío
        $inProcess = self::whereIn('estado', ['en_proceso', 'pendiente'])
            ->orderBy('updated_at', 'desc')
            ->first();

        if ($inProcess) {
            return $inProcess;
        }

        // 2. Si no hay en ejecución, la que está en estado pausado más reciente
        return self::whereIn('estado', ['pausada', 'pausada_sin_conexion', 'pausada_hasta_mañana', 'pausada_fuera_horario'])
            ->orderBy('updated_at', 'desc')
            ->first();
    }

    /**
     * Verifica si esta campaña puede ser iniciada (FIFO)
     */
    public function canBeStarted(): bool
    {
        // Solo campañas en borrador pueden iniciarse como nuevas
        if (!in_array($this->estado, ['borrador'])) {
            return false;
        }

        // Si hay otra campaña actualmente en proceso/pendiente, no se puede iniciar directamente sin pausarla
        $inProcess = self::whereIn('estado', ['en_proceso', 'pendiente'])->first();
        if ($inProcess && $inProcess->id_campania !== $this->id_campania) {
            return false;
        }

        return true;
    }

    /**
     * Verifica si esta campaña puede ser pausada manualmente
     */
    public function canBePaused(): bool
    {
        return in_array($this->estado, ['en_proceso', 'pendiente']);
    }

    /**
     * Verifica si esta campaña puede ser reanudada
     */
    public function canBeResumed(): bool
    {
        return in_array($this->estado, ['pausada', 'pausada_hasta_mañana', 'pausada_fuera_horario', 'pausada_sin_conexion']) && $this->envios_pendientes > 0;
    }

    /**
     * Verifica si esta campaña puede ser cancelada
     */
    public function canBeCancelled(): bool
    {
        return !in_array($this->estado, ['completada', 'cancelada', 'eliminada']);
    }

    /**
     * Verifica si esta campaña puede ser eliminada
     */
    public function canBeDeleted(): bool
    {
        return $this->estado !== 'eliminada';
    }

    /**
     * Verifica si la campaña está eliminada
     */
    public function isDeleted(): bool
    {
        return $this->estado === 'eliminada';
    }

    /**
     * Verifica si la campaña está en borrador
     */
    public function isDraft(): bool
    {
        return $this->estado === 'borrador';
    }

    /**
     * Verifica si la campaña está pausada hasta mañana
     */
    public function isPausedUntilTomorrow(): bool
    {
        return $this->estado === 'pausada_hasta_mañana';
    }

    /**
     * Relación con mensajes de WhatsApp enviados (FASE 3)
     */
    public function mensajesWhatsApp()
    {
        return $this->hasMany(WatModal::class, 'campania_id', 'id_campania');
    }

    /**
     * Obtiene los mensajes fallidos que pueden reintentarse (FASE 3)
     */
    public function getFailedMessagesForRetry()
    {
        return WatModal::where('campania_id', $this->id_campania)
            ->where('estado', 0)
            ->where('puede_reintentar', true)
            ->where('intentos', '<', 3)
            ->with('modalServicio')
            ->get();
    }

    /**
     * Obtiene la cuenta de mensajes por intento (FASE 3)
     */
    public function getRetryStats(): array
    {
        $stats = WatModal::where('campania_id', $this->id_campania)
            ->selectRaw('intentos, COUNT(*) as total, SUM(CASE WHEN estado = 0 THEN 1 ELSE 0 END) as fallidos')
            ->groupBy('intentos')
            ->get()
            ->keyBy('intentos')
            ->toArray();

        return [
            'intento_1' => $stats[1] ?? ['total' => 0, 'fallidos' => 0],
            'intento_2' => $stats[2] ?? ['total' => 0, 'fallidos' => 0],
            'intento_3' => $stats[3] ?? ['total' => 0, 'fallidos' => 0],
        ];
    }

    /**
     * Verifica si hay mensajes pendientes de reintento (FASE 3)
     */
    public function hasFailedMessagesToRetry(): bool
    {
        return WatModal::where('campania_id', $this->id_campania)
            ->where('estado', 0)
            ->where('puede_reintentar', true)
            ->where('intentos', '<', 3)
            ->exists();
    }

    // Ventana Horaria y Auto-Resume

    /**
     * Verifica si estamos dentro del horario permitido (8am-11pm Peru GMT-5)
     */
    public static function isWithinAllowedHours(): bool
    {
        $now = now()->timezone('America/Lima'); // Peru GMT-5
        $hour = $now->hour;

        // Horario permitido: 8:00 AM a 10:59 PM
        return $hour >= 8 && $hour < 23;
    }

    /**
     * Obtiene la próxima hora de inicio (8am del día actual o siguiente)
     */
    public static function getNextStartTime(): \Carbon\Carbon
    {
        $now = now()->timezone('America/Lima');
        $nextStart = $now->copy()->setTime(8, 0, 0);

        // Si ya pasaron las 11pm, la próxima vez es mañana a las 8am
        if ($now->hour >= 23) {
            $nextStart->addDay();
        }
        // Si es antes de las 8am, hoy a las 8am
        elseif ($now->hour < 8) {
            // nextStart ya está en 8am de hoy
        }

        return $nextStart;
    }

    /**
     * Verifica si la campaña está pausada por horario
     */
    public function isPausedOutsideHours(): bool
    {
        return $this->estado === 'pausada_fuera_horario';
    }

    /**
     * Verifica si esta campaña puede reanudarse automáticamente
     */
    public function canAutoResume(): bool
    {
        // Debe estar en uno de estos estados
        if (!in_array($this->estado, ['pausada_hasta_mañana', 'pausada_fuera_horario', 'pausada_sin_conexion'])) {
            return false;
        }

        // Debe tener mensajes pendientes
        if ($this->envios_pendientes <= 0) {
            return false;
        }

        // Debe estar dentro del horario permitido
        if (!self::isWithinAllowedHours()) {
            return false;
        }

        // Si está pausada hasta mañana, verificar que el día cambió
        if ($this->estado === 'pausada_hasta_mañana') {
            $this->resetDailyCounterIfNeeded();
            $this->refresh();

            // Después del reset, debe tener cuota disponible
            return $this->envios_hoy < 50;
        }

        // Si está pausada por falta de conexión, no verificamos cuota
        // (El Cron Job verificará la conexión antes de reanudar)
        if ($this->estado === 'pausada_sin_conexion') {
            // Resetear contador diario si es necesario
            $this->resetDailyCounterIfNeeded();
            $this->refresh();

            // Verificar que haya cuota disponible
            return $this->envios_hoy < 50;
        }

        return true;
    }

    /**
     * Obtiene todas las campañas que pueden reanudarse automáticamente
     */
    public static function getCampaignsReadyToResume()
    {
        return self::whereIn('estado', ['pausada_hasta_mañana', 'pausada_fuera_horario', 'pausada_sin_conexion'])
            ->where('envios_pendientes', '>', 0)
            ->get()
            ->filter(function ($campania) {
                return $campania->canAutoResume();
            });
    }
}
