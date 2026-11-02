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
ALTER TABLE usuarios ALTER COLUMN avatar_url TYPE text;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS banner_url text;
COMMENT ON COLUMN usuarios.avatar_url IS 'URL/caminho ou data URI, conforme armazenamento utilizado pela API.';
COMMENT ON COLUMN usuarios.banner_url IS 'Banner opcional do perfil; upload e validação são responsabilidade da API.';
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM usuarios WHERE banner_url IS NOT NULL OR length(avatar_url) > 2048) THEN
        RAISE EXCEPTION 'Reversão bloqueada: há banners ou avatares maiores que o limite antigo. Preserve os dados antes de reverter.';
    END IF;
END $$;
ALTER TABLE usuarios DROP COLUMN banner_url;
ALTER TABLE usuarios ALTER COLUMN avatar_url TYPE varchar(2048);
COMMENT ON COLUMN usuarios.avatar_url IS NULL;
SQL);
    }
};
