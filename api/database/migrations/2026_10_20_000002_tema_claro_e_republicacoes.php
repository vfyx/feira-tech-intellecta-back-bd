<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE preferencias_usuarios ADD COLUMN tema_claro boolean NOT NULL DEFAULT false;
ALTER TABLE notificacoes DROP CONSTRAINT notificacoes_referencias_validas;
ALTER TABLE notificacoes ADD CONSTRAINT notificacoes_referencias_validas CHECK (
    (tipo IN ('curtida','comentario','publicacao','mencao','republicacao') AND mensagem_id IS NULL)
    OR (tipo = 'mensagem' AND post_id IS NULL)
    OR (tipo IN ('seguidor','acesso') AND post_id IS NULL AND mensagem_id IS NULL)
);
CREATE OR REPLACE FUNCTION intellecta_validar_nova_notificacao() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.tipo IN ('curtida','comentario','publicacao','mencao','republicacao')
       AND (NEW.post_id IS NULL OR NEW.autor_id IS NULL) THEN
        RAISE EXCEPTION 'Notificação de publicação exige autor e publicação';
    ELSIF NEW.tipo = 'mensagem'
       AND (NEW.mensagem_id IS NULL OR NEW.autor_id IS NULL) THEN
        RAISE EXCEPTION 'Notificação de mensagem exige autor e mensagem';
    ELSIF NEW.tipo = 'seguidor' AND NEW.autor_id IS NULL THEN
        RAISE EXCEPTION 'Notificação de seguidor exige autor';
    END IF;
    RETURN NEW;
END;
$$;
SQL);
    }

    public function down(): void
    {
        // A correção das referências permanece para preservar notificações válidas.
        DB::statement('ALTER TABLE preferencias_usuarios DROP COLUMN tema_claro');
    }
};
