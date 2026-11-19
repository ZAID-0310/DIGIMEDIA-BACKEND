<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campania_destinatarios', function (Blueprint $table) {
            $table->foreignId('campania_id');
            $table->foreignId('id_modalservicio');

            $table->unique(
                ['campania_id', 'id_modalservicio'],
                'campania_destinatarios_campaign_contact_unique'
            );
            $table->index(
                'id_modalservicio',
                'campania_destinatarios_contact_index'
            );

            $table->foreign('campania_id')
                ->references('id_campania')
                ->on('campanias_whatsapp')
                ->restrictOnDelete();

            $table->foreign('id_modalservicio')
                ->references('id_modalservicio')
                ->on('modalservicios')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campania_destinatarios');
    }
};
