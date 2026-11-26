<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChatRequest;
use App\Services\AIService;
use App\Services\KnowledgeBase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    private function normalize(string $text): string
    {
        $text = Str::lower(Str::ascii($text));
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $text));
    }

    private function basicReply(string $question): ?string
    {
        $normalized = $this->normalize($question);
        if ($normalized === '') return null;
        foreach (DB::table('respostas_conversa')->where('ativo', true)->orderBy('id')->get(['pergunta', 'resposta']) as $reply) {
            if ($this->normalize($reply->pergunta) === $normalized && trim($reply->resposta) !== '') {
                return $reply->resposta;
            }
        }
        return null;
    }

    /** Memória da conversa: até 20 mensagens recentes (últimos 30 min) do próprio usuário. */
    private function history(int $userId): array
    {
        return DB::table('mensagens_ia')
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->subMinutes(30))
            ->orderByDesc('id')
            ->limit(20)
            ->get(['papel', 'conteudo'])
            ->reverse()
            ->map(fn ($m) => [
                'role' => $m->papel === 'assistente' ? 'model' : 'user',
                'text' => Str::limit($m->conteudo, 4000),
            ])
            ->values()
            ->all();
    }

    private function remember(int $userId, string $question, string $answer): void
    {
        if (trim($question) === '' || trim($answer) === '') return;
        try {
            DB::table('mensagens_ia')->insert([
                ['user_id' => $userId, 'papel' => 'usuario', 'conteudo' => $question],
                ['user_id' => $userId, 'papel' => 'assistente', 'conteudo' => $answer],
            ]);
        } catch (\Throwable $exception) {
            // Falha ao salvar a memória não deve impedir a resposta.
            report($exception);
        }
    }

    /** Botão "Nova conversa": apaga a memória da Intellia do próprio usuário. */
    public function reset(\Illuminate\Http\Request $request)
    {
        DB::table('mensagens_ia')->where('user_id', (int) $request->user()->id)->delete();
        return ['ok' => true];
    }

    public function __invoke(ChatRequest $request, AIService $ai, KnowledgeBase $knowledge)
    {
        set_time_limit(65);
        try {
            $question = $request->string('message')->toString();
            $userId = (int) $request->user()->id;
            if (($reply = $this->basicReply($question)) !== null) {
                $this->remember($userId, $question, $reply);
                return [
                    'answer' => $reply,
                    'rag' => ['used' => false, 'sources' => []],
                ];
            }
            try {
                $matches = $knowledge->search($question, $userId, $request->integer('subject_id') ?: null);
            } catch (\Throwable $exception) {
                // Se a busca no material da escola falhar, a Intellia responde mesmo assim, sem fontes.
                logger()->warning('Intellia: busca na base falhou; respondendo sem material.', ['exception_class' => get_class($exception)]);
                $matches = [];
            }
            $context = [];
            $sources = [];
            foreach ($matches as $index => $match) {
                $number = $index + 1;
                $label = $match['title'];
                if ($match['author']) $label .= ' — '.$match['author'];
                if ($match['page']) $label .= ', página '.$match['page'];
                $context[] = '[Fonte '.$number.': '.$label."]\n".$match['content'];
                $sources[] = [
                    'number' => $number, 'type' => $match['source_type'], 'title' => $match['title'],
                    'post_id' => $match['post_id'], 'filename' => $match['filename'], 'page' => $match['page'],
                    'author' => $match['author'], 'subject' => $match['subject'], 'distance' => (float) $match['distance'],
                    'url' => $match['post_id'] ? '?page=home&post='.$match['post_id'] : null,
                ];
            }
            $answer = $ai->answer($question, $context, $this->history($userId));
            $this->remember($userId, $question, $answer);
            return ['answer' => $answer, 'rag' => ['used' => $context !== [], 'sources' => $sources]];
        } catch (\RuntimeException $exception) {
            $previous = $exception->getPrevious();
            preg_match('/cURL error (\d+)/', $previous?->getMessage() ?? '', $curlError);
            logger()->warning('Intellia: falha ao consultar o provedor.', [
                'exception_class' => get_class($previous ?? $exception),
                'http_status' => $previous instanceof \Illuminate\Http\Client\RequestException ? $previous->response->status() : null,
                'curl_error' => isset($curlError[1]) ? (int) $curlError[1] : null,
            ]);
            if ($exception->getCode() === 429) {
                return response()->json([
                    'code' => 'ai_quota',
                    'message' => 'A Intellia atingiu o limite gratuito de perguntas por hoje. Tente novamente mais tarde.',
                ], 429);
            }
            return response()->json([
                'code' => 'ai_unavailable',
                'message' => 'O serviço de IA está indisponível no momento. Tente novamente em instantes.',
            ], 503);
        } catch (\Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'Não foi possível consultar a base de conhecimento.'], 503);
        }
    }
}
