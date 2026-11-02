<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('knowledge:status', function (): void {
    $rows = DB::table('trechos_conhecimento')->selectRaw('source_key, source_type, title, provider, embedding_model, count(*) as chunks')
        ->groupBy('source_key', 'source_type', 'title', 'provider', 'embedding_model')->orderBy('source_key')->get();
    $this->table(['Fonte', 'Tipo', 'Título', 'Provedor', 'Modelo', 'Trechos'], $rows->map(fn ($row) => (array) $row)->all());
    $this->info($rows->sum('chunks').' trecho(s) no total.');
})->purpose('Mostra os materiais e publicações indexados');

Artisan::command('knowledge:forget {source : Chave document:... mostrada na importação}', function (): int {
    $source = $this->argument('source');
    if (!preg_match('/^document:[a-f0-9]{64}$/', $source)) {
        $this->error('Informe a chave de um documento listado em knowledge:status.');
        return 1;
    }
    $count = DB::transaction(function () use ($source): int {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$source]);
        return DB::table('trechos_conhecimento')->where('source_key', $source)->delete();
    });
    $this->info($count.' trecho(s) removido(s) da base; o arquivo original foi preservado.');
    return 0;
})->purpose('Remove um material da base da Intellia');
