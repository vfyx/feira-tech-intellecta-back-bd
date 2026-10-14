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
ALTER TABLE usuarios
    ADD COLUMN nascimento date,
    ADD COLUMN genero varchar(20),
    ADD COLUMN turma varchar(100),
    ADD COLUMN ultimo_acesso_em timestamp(0),
    ADD COLUMN chave_recuperacao_hash varchar(255),
    ADD COLUMN chave_adicional_hash varchar(255),
    ADD CONSTRAINT usuarios_genero_valido CHECK (genero IN ('feminino', 'masculino', 'outro'));

CREATE TABLE preferencias_usuarios (
    user_id bigint PRIMARY KEY REFERENCES usuarios(id) ON DELETE CASCADE,
    movimento boolean NOT NULL DEFAULT true,
    feed_compacto boolean NOT NULL DEFAULT false,
    novidades_email boolean NOT NULL DEFAULT false,
    perfil_publico boolean NOT NULL DEFAULT true,
    mostrar_atividade boolean NOT NULL DEFAULT true,
    feed_personalizado boolean NOT NULL DEFAULT true,
    chave_adicional_ativa boolean NOT NULL DEFAULT false,
    alertas_acesso boolean NOT NULL DEFAULT true,
    created_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO preferencias_usuarios (user_id) SELECT id FROM usuarios;

CREATE TABLE seguidores (
    seguidor_id bigint NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    seguido_id bigint NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    created_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (seguidor_id, seguido_id),
    CONSTRAINT seguidores_pessoas_distintas CHECK (seguidor_id <> seguido_id)
);
CREATE INDEX seguidores_destino_index ON seguidores (seguido_id);

CREATE TABLE bloqueios (
    bloqueador_id bigint NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    bloqueado_id bigint NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    created_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (bloqueador_id, bloqueado_id),
    CONSTRAINT bloqueios_pessoas_distintas CHECK (bloqueador_id <> bloqueado_id)
);
CREATE INDEX bloqueios_destino_index ON bloqueios (bloqueado_id);

CREATE TABLE assinaturas_publicacoes (
    user_id bigint NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    autor_id bigint NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    created_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, autor_id),
    CONSTRAINT assinaturas_publicacoes_pessoas_distintas CHECK (user_id <> autor_id)
);
CREATE INDEX assinaturas_publicacoes_destino_index ON assinaturas_publicacoes (autor_id);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE assinaturas_publicacoes, bloqueios, seguidores, preferencias_usuarios;
ALTER TABLE usuarios DROP CONSTRAINT usuarios_genero_valido,
    DROP COLUMN nascimento, DROP COLUMN genero, DROP COLUMN turma,
    DROP COLUMN ultimo_acesso_em, DROP COLUMN chave_recuperacao_hash,
    DROP COLUMN chave_adicional_hash;
SQL);
    }
};
