<?php

// Integração com PostgreSQL real e Gemini simulado; nenhuma chamada externa.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

use App\Models\Post;
use App\Services\AIService;
use App\Services\KnowledgeBase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

if (DB::getDriverName() !== 'pgsql') throw new RuntimeException('Este teste exige PostgreSQL.');
config(['services.ai.provider' => 'gemini', 'services.gemini.key' => 'test-only',
    'services.gemini.embedding_model' => 'rag-test-model', 'services.gemini.embedding_dimensions' => 1536,
    'services.ai.max_distance' => 0.45]);
Http::preventStrayRequests();
$requests = [];
$invalidVector = false;
$upstreamFailure = false;
$emptyGeneration = false;
$transientFailures = 0;
$permanentStatus = null;
Http::fake(function ($request) use (&$requests, &$invalidVector, &$upstreamFailure, &$emptyGeneration, &$transientFailures, &$permanentStatus) {
    $requests[] = $request;
    if ($upstreamFailure) return Http::response(['error' => 'private upstream detail'], 503);
    if (str_ends_with($request->url(), ':embedContent')) {
        if ($permanentStatus) return Http::response(['error' => 'private upstream detail'], $permanentStatus);
        if ($transientFailures > 0) { $transientFailures--; return Http::response([], 503); }
        if ($invalidVector) return Http::response(['embedding' => ['values' => [1, 2]]]);
        $text = $request['content']['parts'][0]['text'];
        $vector = array_fill(0, 1536, 0.0);
        $vector[str_contains($text, 'astronomia') ? 1 : 0] = 2.0;
        return Http::response(['embedding' => ['values' => $vector]]);
    }
    if (str_ends_with($request->url(), ':generateContent')) {
        if ($emptyGeneration) return Http::response(['candidates' => []]);
        return Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Uma fração representa partes de um todo. [Fonte 1]']]]]]]);
    }
    throw new RuntimeException('Chamada HTTP inesperada.');
});
$checks = 0;
function verifyRag(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}
function ragApi(string $method, string $url, array $data = [], ?string $token = null): array {
    global $kernel, $app;
    $app['auth']->forgetGuards();
    $headers = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    if ($token) $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
    $response = $kernel->handle(Illuminate\Http\Request::create('/api/'.$url, $method, [], [], [], $headers, json_encode($data)));
    return [$response->getStatusCode(), json_decode($response->getContent(), true)];
}
function ragPdf(string $text): string {
    $stream = "BT /F1 12 Tf 30 750 Td (".$text.") Tj ET";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 600 800] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream",
    ];
    $pdf = "%PDF-1.4\n"; $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);
    return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
}

$fixtureDir = sys_get_temp_dir().'/intellecta-rag-'.bin2hex(random_bytes(6));
mkdir($fixtureDir);
$textPath = $fixtureDir.'/fracoes.txt';
$pdfPath = $fixtureDir.'/apostila.pdf';
DB::beginTransaction();
try {
    $suffix = bin2hex(random_bytes(5));
    [$status, $auth] = ragApi('POST', 'auth/register', ['name' => 'QA RAG', 'username' => 'rag_'.$suffix,
        'email' => $suffix.'@example.test', 'password' => 'Teste!123456', 'password_confirmation' => 'Teste!123456']);
    verifyRag($status === 201, 'Cadastro para integração');
    $uid = $auth['user']['id']; $token = $auth['token'];
    $subject = DB::table('disciplinas')->value('id');
    $post = Post::create(['user_id' => $uid, 'subject_id' => $subject, 'title' => 'Frações QA',
        'content' => 'Frações representam partes de um todo.', 'status' => 'aprovado']);
    $knowledge = app(KnowledgeBase::class);
    verifyRag($knowledge->indexPost($post) === 1, 'Indexação de publicação');
    $before = count($requests);
    verifyRag($knowledge->indexPost($post) === 0 && count($requests) === $before, 'Indexação incremental sem nova cobrança');
    $post->update(['status' => 'pendente']);
    verifyRag($knowledge->indexPost($post, true) === 0, 'Não indexa pendentes');
    $post->update(['status' => 'aprovado']);

    file_put_contents($textPath, str_repeat('Operações com frações: some numeradores de denominadores iguais. ', 50));
    $document = $knowledge->indexFile($textPath, 'Apostila de frações', $subject);
    verifyRag($document['chunks'] > 1, 'Material dividido em trechos');
    verifyRag($knowledge->indexFile($textPath, 'Apostila de frações', $subject)['chunks'] === 0, 'Documento inalterado ignorado');
    file_put_contents($pdfPath, ragPdf('Uma fracao representa partes de um todo.'));
    $pdf = $knowledge->indexFile($pdfPath, 'PDF de frações', $subject);
    verifyRag($pdf['chunks'] === 1, 'Extração e indexação de PDF');
    verifyRag(DB::table('trechos_conhecimento')->where('source_key', $pdf['source_key'])->value('page') === 1, 'Página da fonte preservada');

    $matches = $knowledge->search('Como somar frações?', $uid, $subject);
    verifyRag(count($matches) > 1 && in_array('post', array_column($matches, 'source_type'))
        && in_array('document', array_column($matches, 'source_type')), 'Busca combina posts e materiais');
    verifyRag($knowledge->search('astronomia', $uid) === [], 'Distância descarta conteúdo sem relação');
    verifyRag($knowledge->search('frações', $uid, 2147483647) === [], 'Filtro de disciplina');

    [$status] = ragApi('POST', 'chat', ['message' => 'Frações']);
    verifyRag($status === 401, 'Chat exige autenticação');
    [$status, $answer] = ragApi('POST', 'chat', ['message' => 'Como somar frações?'], $token);
    verifyRag($status === 200 && $answer['rag']['used'] && str_contains($answer['answer'], '[Fonte 1]'), 'Tutor responde com contexto');
    verifyRag(count($answer['rag']['sources']) <= 5 && isset($answer['rag']['sources'][0]['number']), 'Fontes numeradas e limitadas');
    $generations = array_filter($requests, fn ($r) => str_ends_with($r->url(), ':generateContent'));
    $generation = end($generations);
    verifyRag(json_decode($generation->body())->generationConfig instanceof stdClass, 'Configuração Gemini serializada como objeto JSON');
    verifyRag(str_contains($generation['contents'][0]['parts'][0]['text'], '[Fonte 1:'), 'Contexto enviado ao Gemini');
    [$status, $answer] = ragApi('POST', 'chat', ['message' => 'astronomia'], $token);
    verifyRag($status === 200 && !$answer['rag']['used'] && $answer['rag']['sources'] === [], 'Ausência de material explícita');
    // A Intellia agora responde qualquer pergunta: sem material da escola ela usa o Gemini, mas sem citar fontes.
    verifyRag(count(array_filter($requests, fn ($r) => str_ends_with($r->url(), ':generateContent'))) === count($generations) + 1, 'Sem contexto responde com conhecimento geral');
    $generalRequest = array_values(array_filter($requests, fn ($r) => str_ends_with($r->url(), ':generateContent')));
    $generalContents = end($generalRequest)['contents'];
    verifyRag(!str_contains(end($generalContents)['parts'][0]['text'], 'Material da escola'), 'Sem contexto não envia material inventado');
    $emptyGeneration = true;
    [$status] = ragApi('POST', 'chat', ['message' => 'Frações'], $token);
    verifyRag($status === 503, 'Resposta vazia do provedor não é tratada como sucesso');
    $emptyGeneration = false;

    $post->update(['content' => 'Conteúdo editado.']);
    verifyRag(!in_array($post->id, array_column($knowledge->search('Frações', $uid), 'post_id')), 'Vetor antigo de post editado excluído');
    verifyRag($knowledge->indexPost($post) === 1, 'Reindexa edição');
    $post->update(['status' => 'reprovado']);
    verifyRag(!in_array($post->id, array_column($knowledge->search('Frações', $uid), 'post_id')), 'Reprovado excluído imediatamente');
    $post->update(['status' => 'aprovado']);
    DB::table('preferencias_usuarios')->where('user_id', $uid)->update(['perfil_publico' => false]);
    verifyRag(!in_array($post->id, array_column($knowledge->search('Frações', $uid + 1000000), 'post_id')), 'Perfil privado invisível para terceiro');
    verifyRag($knowledge->indexPost($post, true) === 0, 'Não envia perfil privado ao provedor');
    DB::table('preferencias_usuarios')->where('user_id', $uid)->update(['perfil_publico' => true]);
    [$status, $other] = ragApi('POST', 'auth/register', ['name' => 'QA bloqueio', 'username' => 'rag_b_'.$suffix,
        'email' => 'b'.$suffix.'@example.test', 'password' => 'Teste!123456', 'password_confirmation' => 'Teste!123456']);
    verifyRag($status === 201, 'Segunda conta para teste de bloqueio');
    DB::table('bloqueios')->insert(['bloqueador_id' => $uid, 'bloqueado_id' => $other['user']['id']]);
    verifyRag(!in_array($post->id, array_column($knowledge->search('Frações', $other['user']['id']), 'post_id')), 'Bloqueio respeitado');
    config(['services.gemini.embedding_model' => 'outro-modelo']);
    verifyRag($knowledge->search('Frações', $uid) === [], 'Não mistura modelos de embedding');
    config(['services.gemini.embedding_model' => 'rag-test-model']);

    $prior = DB::table('trechos_conhecimento')->where('source_key', $document['source_key'])->count();
    $invalidVector = true;
    try { $knowledge->indexFile($textPath, 'Outro título', $subject, true); verifyRag(false, 'Deveria rejeitar dimensão inválida'); }
    catch (RuntimeException $e) { verifyRag($e->getMessage() === 'Falha ao gerar embedding.', 'Validação das dimensões'); }
    verifyRag(DB::table('trechos_conhecimento')->where('source_key', $document['source_key'])->count() === $prior, 'Falha preserva índice anterior');
    $invalidVector = false; $upstreamFailure = true;
    [$status, $answer] = ragApi('POST', 'chat', ['message' => 'Frações'], $token);
    verifyRag($status === 503 && !str_contains(json_encode($answer), 'private upstream detail'), 'Falha do provedor explícita sem detalhes privados');
    verifyRag(($answer['code'] ?? '') === 'ai_unavailable', 'Falha da IA identificada para a interface');
    $beforeGreeting = count($requests);
    [$status, $greeting] = ragApi('POST', 'chat', ['message' => 'olá!'], $token);
    verifyRag($status === 200 && str_starts_with($greeting['answer'], 'Olá!'), 'Saudação funciona mesmo com provedor indisponível');
    verifyRag(count($requests) === $beforeGreeting && !$greeting['rag']['used'] && $greeting['rag']['sources'] === [], 'Saudação não chama provedor nem inventa fontes');
    $upstreamFailure = false; $transientFailures = 1;
    $beforeRetry = count($requests);
    verifyRag(count(app(AIService::class)->embedding('Frações')) === 1536 && count($requests) === $beforeRetry + 2, 'Recupera embedding após uma falha 503');
    foreach ([401, 429] as $permanentStatus) {
        $beforePermanent = count($requests);
        try { app(AIService::class)->embedding('Frações'); verifyRag(false, 'Erro permanente deve falhar'); }
        catch (RuntimeException $e) { verifyRag(count($requests) === $beforePermanent + 1, 'Não repete erro '.$permanentStatus); }
    }
    $permanentStatus = null;
    $tasks = array_map(fn ($r) => $r['taskType'], array_values(array_filter($requests, fn ($r) => str_ends_with($r->url(), ':embedContent'))));
    verifyRag(in_array('RETRIEVAL_QUERY', $tasks) && in_array('RETRIEVAL_DOCUMENT', $tasks), 'Tipos de tarefa separados');
    $post->delete();
    verifyRag(!DB::table('trechos_conhecimento')->where('post_id', $post->id)->exists(), 'Exclusão de post remove vetores');
    echo "PASS: $checks verificações de RAG com PostgreSQL e Gemini simulado; dados revertidos.\n";
} finally {
    DB::rollBack();
    foreach ([$textPath, $pdfPath] as $file) if (is_file($file)) unlink($file);
    rmdir($fixtureDir);
}
