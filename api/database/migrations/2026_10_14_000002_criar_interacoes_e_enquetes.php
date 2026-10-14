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
CREATE TABLE publicacoes_salvas (
    user_id bigint NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    post_id bigint NOT NULL REFERENCES publicacoes(id) ON DELETE CASCADE,
    created_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, post_id)
);
CREATE INDEX publicacoes_salvas_post_index ON publicacoes_salvas (post_id);

CREATE TABLE republicacoes (
    user_id bigint NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    post_id bigint NOT NULL REFERENCES publicacoes(id) ON DELETE CASCADE,
    created_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, post_id)
);
CREATE INDEX republicacoes_post_index ON republicacoes (post_id);

CREATE TABLE publicacoes_ocultadas (
    user_id bigint NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    post_id bigint NOT NULL REFERENCES publicacoes(id) ON DELETE CASCADE,
    created_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, post_id)
);
CREATE INDEX publicacoes_ocultadas_post_index ON publicacoes_ocultadas (post_id);

CREATE TABLE enquetes (
    id bigserial PRIMARY KEY,
    post_id bigint NOT NULL UNIQUE REFERENCES publicacoes(id) ON DELETE CASCADE,
    pergunta varchar(300) NOT NULL CHECK (length(trim(pergunta)) > 0),
    created_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE opcoes_enquetes (
    id bigserial PRIMARY KEY,
    enquete_id bigint NOT NULL REFERENCES enquetes(id) ON DELETE CASCADE,
    texto varchar(150) NOT NULL CHECK (length(trim(texto)) > 0),
    ordem smallint NOT NULL CHECK (ordem BETWEEN 0 AND 5),
    UNIQUE (enquete_id, ordem),
    UNIQUE (enquete_id, texto),
    UNIQUE (id, enquete_id)
);
CREATE TABLE votos_enquetes (
    enquete_id bigint NOT NULL REFERENCES enquetes(id) ON DELETE CASCADE,
    user_id bigint NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    opcao_id bigint NOT NULL,
    created_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (enquete_id, user_id),
    FOREIGN KEY (opcao_id, enquete_id) REFERENCES opcoes_enquetes(id, enquete_id) ON DELETE CASCADE
);
CREATE INDEX votos_enquetes_opcao_index ON votos_enquetes (opcao_id, enquete_id);
CREATE INDEX votos_enquetes_user_index ON votos_enquetes (user_id);

-- A pergunta e as opções devem ser criadas na mesma transação.
-- A validação acontece no COMMIT, permitindo inserir todas as opções primeiro.
CREATE FUNCTION intellecta_validar_quantidade_opcoes() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE ids bigint[]; alvo bigint; quantidade integer;
BEGIN
    IF TG_TABLE_NAME = 'enquetes' THEN
        ids := ARRAY[NEW.id];
    ELSIF TG_OP = 'INSERT' THEN
        ids := ARRAY[NEW.enquete_id];
    ELSIF TG_OP = 'DELETE' THEN
        ids := ARRAY[OLD.enquete_id];
    ELSE
        ids := ARRAY[OLD.enquete_id, NEW.enquete_id];
    END IF;
    FOREACH alvo IN ARRAY ids LOOP
        PERFORM id FROM enquetes WHERE id = alvo FOR NO KEY UPDATE;
        IF FOUND THEN
            SELECT count(*) INTO quantidade FROM opcoes_enquetes WHERE enquete_id = alvo;
            IF quantidade < 2 OR quantidade > 6 THEN
                RAISE EXCEPTION 'Uma enquete deve ter entre 2 e 6 opções';
            END IF;
        END IF;
    END LOOP;
    RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER intellecta_enquete_com_opcoes
AFTER INSERT OR UPDATE ON enquetes DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION intellecta_validar_quantidade_opcoes();
CREATE CONSTRAINT TRIGGER intellecta_quantidade_opcoes
AFTER INSERT OR UPDATE OR DELETE ON opcoes_enquetes DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION intellecta_validar_quantidade_opcoes();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE votos_enquetes, opcoes_enquetes, enquetes;
DROP FUNCTION intellecta_validar_quantidade_opcoes();
DROP TABLE publicacoes_ocultadas, republicacoes, publicacoes_salvas;
SQL);
    }
};
