<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Esta atualização exige PostgreSQL com pgvector.');
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE denuncias_perfis (
    id bigserial PRIMARY KEY,
    denunciante_id bigint REFERENCES usuarios(id) ON DELETE SET NULL,
    denunciado_id bigint REFERENCES usuarios(id) ON DELETE SET NULL,
    motivo text NOT NULL CHECK (length(trim(motivo)) > 0),
    status varchar(20) NOT NULL DEFAULT 'nova'
        CHECK (status IN ('nova', 'em_analise', 'resolvida', 'arquivada')),
    created_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (denunciante_id <> denunciado_id)
);
CREATE INDEX denuncias_perfis_status_index ON denuncias_perfis (status, created_at);
CREATE INDEX denuncias_perfis_denunciante_index ON denuncias_perfis (denunciante_id);
CREATE INDEX denuncias_perfis_denunciado_index ON denuncias_perfis (denunciado_id);
CREATE FUNCTION intellecta_validar_nova_denuncia_perfil() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.denunciante_id IS NULL OR NEW.denunciado_id IS NULL THEN
        RAISE EXCEPTION 'Denúncia de perfil exige remetente e perfil denunciado';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER intellecta_nova_denuncia_perfil BEFORE INSERT ON denuncias_perfis
FOR EACH ROW EXECUTE FUNCTION intellecta_validar_nova_denuncia_perfil();

CREATE TABLE mensagens_ocultadas (
    user_id bigint NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    mensagem_id bigint NOT NULL REFERENCES mensagens(id) ON DELETE CASCADE,
    created_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, mensagem_id)
);
CREATE INDEX mensagens_ocultadas_mensagem_index ON mensagens_ocultadas (mensagem_id);
CREATE FUNCTION intellecta_validar_ocultacao_mensagem() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM mensagens m JOIN conversas c ON c.id = m.conversa_id
        WHERE m.id = NEW.mensagem_id
          AND NEW.user_id IN (c.usuario_1_id, c.usuario_2_id)
    ) THEN
        RAISE EXCEPTION 'Somente participantes podem ocultar mensagens da conversa';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER intellecta_ocultacao_mensagem BEFORE INSERT OR UPDATE ON mensagens_ocultadas
FOR EACH ROW EXECUTE FUNCTION intellecta_validar_ocultacao_mensagem();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE mensagens_ocultadas;
DROP FUNCTION intellecta_validar_ocultacao_mensagem();
DROP TABLE denuncias_perfis;
DROP FUNCTION intellecta_validar_nova_denuncia_perfil();
SQL);
    }
};
