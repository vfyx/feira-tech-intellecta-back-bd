<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('A base de conhecimento exige PostgreSQL com pgvector.');
        }
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        Schema::create('trechos_conhecimento', function (Blueprint $table): void {
            $table->id();
            $table->string('source_key', 80);
            $table->string('source_type', 16);
            $table->foreignId('post_id')->nullable()->constrained('publicacoes')->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('disciplinas')->nullOnDelete();
            $table->string('title', 255);
            $table->string('filename', 255)->nullable();
            $table->unsignedInteger('page')->nullable();
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->string('content_hash', 32);
            $table->string('provider', 20);
            $table->string('embedding_model', 100);
            $table->timestamps();
            $table->unique(['source_key', 'chunk_index']);
            $table->index(['provider', 'embedding_model']);
        });
        DB::statement('ALTER TABLE trechos_conhecimento ADD COLUMN embedding vector(1536) NOT NULL');
        DB::statement("ALTER TABLE trechos_conhecimento ADD CONSTRAINT conhecimento_origem_valida CHECK ((source_type = 'post' AND post_id IS NOT NULL) OR (source_type = 'document' AND post_id IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('trechos_conhecimento');
    }
};
