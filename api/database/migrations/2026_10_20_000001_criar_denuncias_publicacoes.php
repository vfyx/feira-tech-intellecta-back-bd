<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('denuncias_publicacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('denunciante_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->foreignId('post_id')->nullable()->constrained('publicacoes')->nullOnDelete();
            $table->foreignId('autor_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->text('conteudo');
            $table->text('motivo');
            $table->string('status', 20)->default('nova');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->index(['status', 'created_at']);
            $table->index('post_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('denuncias_publicacoes');
    }
};
