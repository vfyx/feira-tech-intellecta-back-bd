<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('directs_fechados', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('target_id')->constrained('usuarios')->cascadeOnDelete();
            $table->unsignedBigInteger('ultima_mensagem_id')->default(0);
            $table->primary(['user_id','target_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('directs_fechados'); }
};
