<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campanias_whatsapp', function (Blueprint $table) {
            $table->boolean('destinatarios_persistidos')
                ->default(false)
                ->after('total_destinatarios');
        });
    }

    public function down(): void
    {
        Schema::table('campanias_whatsapp', function (Blueprint $table) {
            $table->dropColumn('destinatarios_persistidos');
        });
    }
};
