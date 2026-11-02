<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

if (DB::getDriverName() !== 'pgsql') throw new RuntimeException('Este teste exige PostgreSQL.');
config(['services.ai.provider' => 'gemini', 'services.gemini.key' => 'test-only']);
Http::preventStrayRequests();
Http::fake(fn () => Http::response([], 503));
$checks = 0;
function basicCheck(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}
function basicApi(string $route, array $data, ?string $token = null): array {
    global $app, $kernel;
    $app['auth']->forgetGuards();
    $headers = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    if ($token) $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
    $response = $kernel->handle(Illuminate\Http\Request::create('/api/'.$route, 'POST', [], [], [], $headers, json_encode($data)));
    return [$response->getStatusCode(), json_decode($response->getContent(), true)];
}

DB::beginTransaction();
try {
    $suffix = bin2hex(random_bytes(5));
    [$status, $auth] = basicApi('auth/register', ['name' => 'QA conversa', 'username' => 'basic_'.$suffix,
        'email' => $suffix.'@example.test', 'password' => 'Teste!123456', 'password_confirmation' => 'Teste!123456']);
    basicCheck($status === 201, 'Conta de teste');
    $token = $auth['token'];
    [$status] = basicApi('chat', ['message' => 'oi']);
    basicCheck($status === 401, 'Conversa mantém autenticação');
    foreach (['Oi!!!' => 'oi', 'OLÁ' => 'olá', 'tudo   bem?' => 'tudo bem', 'como voce ta?' => 'como você tá'] as $input => $stored) {
        [$status, $answer] = basicApi('chat', ['message' => $input], $token);
        basicCheck($status === 200 && $answer['answer'] === DB::table('respostas_conversa')->where('pergunta', $stored)->value('resposta'), 'Lê do banco e normaliza: '.$input);
        basicCheck(!$answer['rag']['used'] && $answer['rag']['sources'] === [], 'Conversa básica não traz publicações como fontes');
    }
    Http::assertNothingSent();
    basicCheck(true, 'Respostas funcionam sem Gemini');
    DB::table('respostas_conversa')->where('pergunta', 'oi')->update(['resposta' => 'Resposta editada no banco.']);
    [$status, $answer] = basicApi('chat', ['message' => 'oi'], $token);
    basicCheck($status === 200 && $answer['answer'] === 'Resposta editada no banco.', 'Edição no banco tem efeito imediato');
    DB::table('respostas_conversa')->insert(['pergunta' => 'Tudo tranquilo?', 'resposta' => 'Tudo tranquilo!', 'ativo' => true]);
    [$status, $answer] = basicApi('chat', ['message' => 'tudo tranquilo'], $token);
    basicCheck($status === 200 && $answer['answer'] === 'Tudo tranquilo!', 'Nova frase adicionada pelo banco');
    DB::table('respostas_conversa')->where('pergunta', 'oi')->update(['ativo' => false]);
    [$status] = basicApi('chat', ['message' => 'oi'], $token);
    basicCheck($status === 503, 'Resposta desativada deixa de ser usada');
    [$status] = basicApi('chat', ['message' => 'oi, explique frações'], $token);
    basicCheck($status === 503, 'Pergunta de estudo não é confundida com cumprimento');
    echo "PASS: $checks verificações de conversa básica; dados revertidos.\n";
} finally {
    DB::rollBack();
}
