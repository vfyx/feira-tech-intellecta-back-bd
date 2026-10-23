<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        foreach (['denuncias_perfis', 'denuncias_publicacoes'] as $name) {
            Schema::table($name, function (Blueprint $t) {
                $t->text('resolucao')->nullable();
                $t->foreignId('moderador_id')->nullable()->constrained('usuarios')->nullOnDelete();
            });
        }
        Schema::table('mensagens', fn (Blueprint $t) => $t->index(['conversa_id','remetente_id','lida_em'], 'mensagens_leitura_index'));
    }
    public function down(): void {
        foreach (['denuncias_perfis', 'denuncias_publicacoes'] as $name) {
            Schema::table($name, function (Blueprint $t) { $t->dropConstrainedForeignId('moderador_id'); $t->dropColumn('resolucao'); });
        }
        Schema::table('mensagens', fn (Blueprint $t) => $t->dropIndex('mensagens_leitura_index'));
    }
};
