<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reclamacoes_professores', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('aluno_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('professor_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('nome', 100);
            $table->string('rm', 30)->nullable();
            $table->string('curso', 120);
            $table->string('turma', 60);
            $table->string('assunto', 150);
            $table->text('descricao');
            $table->text('solicitacao')->nullable();
            $table->text('resposta')->nullable();
            $table->timestamp('respondida_em')->nullable();
            $table->timestamps();
            $table->index(['aluno_id', 'created_at']);
            $table->index(['professor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reclamacoes_professores');
    }
};
