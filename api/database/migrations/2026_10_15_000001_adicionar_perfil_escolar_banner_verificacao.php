<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table): void {
            $table->string('curso', 40)->nullable();
            $table->string('ano_escolar', 10)->nullable();
            $table->text('banner_url')->nullable();
            $table->timestamp('verificado_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table): void {
            $table->dropColumn(['curso', 'ano_escolar', 'banner_url', 'verificado_em']);
        });
    }
};
