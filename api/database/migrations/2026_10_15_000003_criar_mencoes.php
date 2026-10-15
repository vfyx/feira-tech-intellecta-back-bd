
]<?php

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
CREATE TABLE mencoes_publicacoes (
    post_id bigint NOT NULL REFERENCES publicacoes(id) ON DELETE CASCADE,
    mencionado_id bigint NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    created_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (post_id, mencionado_id)
);
CREATE INDEX mencoes_publicacoes_destino_index ON mencoes_publicacoes (mencionado_id, created_at);
CREATE TABLE mencoes_comentarios (
    comentario_id bigint NOT NULL REFERENCES comentarios(id) ON DELETE CASCADE,
    mencionado_id bigint NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    created_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (comentario_id, mencionado_id)
);
CREATE INDEX mencoes_comentarios_destino_index ON mencoes_comentarios (mencionado_id, created_at);
-- Uma menção por pessoa e conteúdo, mesmo que o @ se repita no texto.
-- Armazena o ID da pessoa, sem depender de futuras mudanças no username.
-- Extração do texto, bloqueios, visibilidade e notificações ficam na API.
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM mencoes_publicacoes) OR EXISTS (SELECT 1 FROM mencoes_comentarios) THEN
        RAISE EXCEPTION 'Reversão bloqueada: existem menções registradas. Preserve os dados antes de reverter.';
    END IF;
END $$;
DROP TABLE mencoes_comentarios, mencoes_publicacoes;
SQL);
    }
};
