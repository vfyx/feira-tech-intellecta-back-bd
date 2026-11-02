<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('respostas_conversa', function (Blueprint $table): void {
            $table->id();
            $table->string('pergunta', 160)->unique();
            $table->text('resposta');
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        // Dados iniciais apenas: em execução, a Intellia lê as respostas do banco.
        $replies = [
            'oi' => 'Oi! Tudo bem com você?',
            'olá' => 'Olá! Tudo bem com você?',
            'opa' => 'Opa! Como você está?',
            'e aí' => 'E aí! Tudo certo com você?',
            'bom dia' => 'Bom dia! Como você está hoje?',
            'boa tarde' => 'Boa tarde! Tudo bem por aí?',
            'boa noite' => 'Boa noite! Como você está?',
            'tudo bem' => 'Tudo certo por aqui! E com você?',
            'como você tá' => 'Tudo certo por aqui! E você, como está?',
            'como você está' => 'Tudo certo por aqui! E você, como está?',
            'obrigado' => 'De nada! Estou por aqui se precisar.',
            'obrigada' => 'De nada! Estou por aqui se precisar.',
            'tchau' => 'Tchau! Até a próxima!',
            'até mais' => 'Até mais! Foi bom conversar com você.',
        ];
        foreach ($replies as $question => $answer) {
            DB::table('respostas_conversa')->insert([
                'pergunta' => $question, 'resposta' => $answer, 'ativo' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('respostas_conversa');
    }
};
