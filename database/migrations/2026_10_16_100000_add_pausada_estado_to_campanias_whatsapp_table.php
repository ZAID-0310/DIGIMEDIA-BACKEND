<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE campanias_whatsapp 
            MODIFY COLUMN estado ENUM(
                'borrador',
                'pendiente',
                'en_proceso',
                'pausada',
                'pausada_hasta_mañana',
                'pausada_fuera_horario',
                'pausada_sin_conexion',
                'completada',
                'cancelada',
                'error'
            ) NOT NULL DEFAULT 'borrador'"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE campanias_whatsapp 
            MODIFY COLUMN estado ENUM(
                'borrador',
                'pendiente',
                'en_proceso',
                'pausada_hasta_mañana',
                'pausada_fuera_horario',
                'pausada_sin_conexion',
                'completada',
                'cancelada',
                'error'
            ) NOT NULL DEFAULT 'borrador'"
        );
    }
};
