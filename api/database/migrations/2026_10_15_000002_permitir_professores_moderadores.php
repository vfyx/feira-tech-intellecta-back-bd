<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Esta atualização exige PostgreSQL.');
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE usuarios DROP CONSTRAINT usuarios_role_valido;
ALTER TABLE usuarios ADD CONSTRAINT usuarios_role_valido
    CHECK (role IN ('aluno', 'professor', 'moderador', 'admin'));
-- Não promove nenhuma conta existente e mantém o padrão aluno.
-- A API deve autorizar atribuições e definir as permissões de cada papel.
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM usuarios WHERE role IN ('professor', 'moderador')) THEN
        RAISE EXCEPTION 'Reversão bloqueada: existem professores ou moderadores. Revise os papéis antes de reverter.';
    END IF;
END $$;
ALTER TABLE usuarios DROP CONSTRAINT usuarios_role_valido;
ALTER TABLE usuarios ADD CONSTRAINT usuarios_role_valido CHECK (role IN ('aluno', 'admin'));
SQL);
    }
};
