<?php
try {
    foreach (['pdo_pgsql','mbstring','openssl','curl','fileinfo','intl','dom','xml'] as $extension) {
        if (!extension_loaded($extension)) throw new RuntimeException('Extensao PHP ausente: '.$extension);
    }
    echo 'PHP pronto: '.PHP_VERSION.PHP_EOL;
    if (in_array('--runtime-only',$argv,true)) exit(0);
    require __DIR__.'/api/vendor/autoload.php';
    $app=require __DIR__.'/api/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=Illuminate\Support\Facades\DB::connection();
    if ($db->getDriverName()!=='pgsql') throw new RuntimeException('Banco diferente de PostgreSQL.');
    if (!$db->table('pg_extension')->where('extname','vector')->exists()) throw new RuntimeException('pgvector ausente.');
    foreach (['usuarios','publicacoes','mensagens','preferencias_usuarios','enquetes','bloqueios'] as $table) {
        $db->table($table)->limit(1)->get();
    }
    echo 'PostgreSQL, pgvector e tabelas da integracao confirmados.'.PHP_EOL;
} catch (Throwable $error) {
    // Nao imprime configuracao privada nem detalhes da conexao.
    fwrite(STDERR,'Falha na validacao. Confira o Docker, a preparacao e a configuracao local.'.PHP_EOL);
    exit(1);
}
