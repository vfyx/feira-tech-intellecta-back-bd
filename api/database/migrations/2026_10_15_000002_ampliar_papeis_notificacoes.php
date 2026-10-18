<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE usuarios DROP CONSTRAINT IF EXISTS usuarios_role_valido');
        DB::statement("
            ALTER TABLE usuarios
            ADD CONSTRAINT usuarios_role_valido
            CHECK (role IN ('aluno', 'moderador', 'professor', 'admin'))
        ");
        DB::statement('ALTER TABLE notificacoes DROP CONSTRAINT IF EXISTS notificacoes_tipo_valido');
        DB::statement("
            ALTER TABLE notificacoes
            ADD CONSTRAINT notificacoes_tipo_valido
            CHECK (tipo IN ('curtida', 'comentario', 'mensagem', 'mencao', 'republicacao', 'seguidor', 'acesso'))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notificacoes DROP CONSTRAINT IF EXISTS notificacoes_tipo_valido');
        DB::statement("
            ALTER TABLE notificacoes
            ADD CONSTRAINT notificacoes_tipo_valido
            CHECK (tipo IN ('curtida', 'comentario', 'mensagem'))
        ");
        DB::statement('ALTER TABLE usuarios DROP CONSTRAINT IF EXISTS usuarios_role_valido');
        DB::statement("
            ALTER TABLE usuarios
            ADD CONSTRAINT usuarios_role_valido
            CHECK (role IN ('aluno', 'admin'))
        ");
    }
};
