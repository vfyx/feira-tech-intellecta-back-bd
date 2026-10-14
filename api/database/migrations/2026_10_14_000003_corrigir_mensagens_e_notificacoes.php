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
ALTER TABLE mensagens
    ADD COLUMN publicacao_removida boolean NOT NULL DEFAULT false,
    DROP CONSTRAINT mensagens_publicacao_valida,
    ADD CONSTRAINT mensagens_publicacao_valida CHECK (
        tipo <> 'publicacao' OR (
            ((post_id IS NOT NULL AND NOT publicacao_removida)
             OR (post_id IS NULL AND publicacao_removida))
            AND (conteudo IS NULL OR length(trim(conteudo)) > 0)
        )
    ),
    ADD CONSTRAINT mensagens_remocao_valida CHECK (tipo = 'publicacao' OR NOT publicacao_removida);

-- Preserva a mensagem compartilhada quando o post original é excluído.
CREATE FUNCTION intellecta_preservar_compartilhamentos() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    UPDATE mensagens SET post_id = NULL, publicacao_removida = true
    WHERE post_id = OLD.id AND tipo = 'publicacao';
    RETURN OLD;
END;
$$;
CREATE TRIGGER intellecta_publicacao_excluida BEFORE DELETE ON publicacoes
FOR EACH ROW EXECUTE FUNCTION intellecta_preservar_compartilhamentos();

ALTER TABLE notificacoes
    ADD COLUMN url varchar(2048),
    DROP CONSTRAINT notificacoes_tipo_valido,
    DROP CONSTRAINT notificacoes_referencias_validas,
    ADD CONSTRAINT notificacoes_tipo_valido CHECK (
        tipo IN ('curtida', 'comentario', 'mensagem', 'seguidor', 'publicacao', 'acesso')
    ),
    ADD CONSTRAINT notificacoes_referencias_validas CHECK (
        (tipo IN ('curtida', 'comentario', 'publicacao') AND mensagem_id IS NULL)
        OR (tipo = 'mensagem' AND post_id IS NULL)
        OR (tipo IN ('seguidor', 'acesso') AND post_id IS NULL AND mensagem_id IS NULL)
    );

-- Novos avisos de interação devem nascer com referências válidas.
-- UPDATE permite os NULLs necessários ao ON DELETE SET NULL existente.
CREATE FUNCTION intellecta_validar_nova_notificacao() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.tipo IN ('curtida', 'comentario', 'publicacao')
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
CREATE TRIGGER intellecta_nova_notificacao BEFORE INSERT ON notificacoes
FOR EACH ROW EXECUTE FUNCTION intellecta_validar_nova_notificacao();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
-- Impede perder os novos tipos de aviso ou os compartilhamentos preservados.
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM mensagens WHERE tipo = 'publicacao'
               AND (post_id IS NULL OR conteudo IS NOT NULL))
       OR EXISTS (SELECT 1 FROM notificacoes WHERE
           tipo NOT IN ('curtida', 'comentario', 'mensagem') OR autor_id IS NULL
           OR (tipo IN ('curtida', 'comentario') AND post_id IS NULL)
           OR (tipo = 'mensagem' AND mensagem_id IS NULL)) THEN
        RAISE EXCEPTION 'Rollback incompatível com dados novos. Preserve-os e revise a reversão antes de continuar.';
    END IF;
END $$;
DROP TRIGGER intellecta_publicacao_excluida ON publicacoes;
DROP FUNCTION intellecta_preservar_compartilhamentos();
DROP TRIGGER intellecta_nova_notificacao ON notificacoes;
DROP FUNCTION intellecta_validar_nova_notificacao();
ALTER TABLE mensagens DROP CONSTRAINT mensagens_publicacao_valida,
    DROP CONSTRAINT mensagens_remocao_valida,
    DROP COLUMN publicacao_removida,
    ADD CONSTRAINT mensagens_publicacao_valida CHECK (
        tipo <> 'publicacao' OR (post_id IS NOT NULL AND conteudo IS NULL)
    );
ALTER TABLE notificacoes DROP COLUMN url,
    DROP CONSTRAINT notificacoes_tipo_valido,
    DROP CONSTRAINT notificacoes_referencias_validas,
    ADD CONSTRAINT notificacoes_tipo_valido CHECK (tipo IN ('curtida', 'comentario', 'mensagem')),
    ADD CONSTRAINT notificacoes_referencias_validas CHECK (
        (tipo IN ('curtida', 'comentario') AND post_id IS NOT NULL AND autor_id IS NOT NULL AND mensagem_id IS NULL)
        OR (tipo = 'mensagem' AND mensagem_id IS NOT NULL AND autor_id IS NOT NULL AND post_id IS NULL)
    );
SQL);
    }
};
