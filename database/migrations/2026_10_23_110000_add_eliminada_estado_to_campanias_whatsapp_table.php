<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Ampliar el ENUM del campo estado para incluir 'eliminada'
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
                'eliminada',
                'error'
            ) NOT NULL DEFAULT 'borrador'"
        );

        // 2. Agregar campos para auditoría de eliminación
        Schema::table('campanias_whatsapp', function (Blueprint $table) {
            $table->unsignedBigInteger('deleted_by')->nullable()->after('user_id');
            $table->timestamp('fecha_eliminada')->nullable()->after('fecha_fin');

            $table->foreign('deleted_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campanias_whatsapp', function (Blueprint $table) {
            $table->dropForeign(['deleted_by']);
            $table->dropColumn(['deleted_by', 'fecha_eliminada']);
        });

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
};
