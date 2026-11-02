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

    public function __invoke(ChatRequest $request, AIService $ai, KnowledgeBase $knowledge)
    {
        set_time_limit(65);
        try {
            $question = $request->string('message')->toString();
            if (($reply = $this->basicReply($question)) !== null) {
                return [
                    'answer' => $reply,
                    'rag' => ['used' => false, 'sources' => []],
                ];
            }
            $matches = $knowledge->search($question, (int) $request->user()->id, $request->integer('subject_id') ?: null);
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
            return ['answer' => $ai->answer($question, $context), 'rag' => ['used' => $context !== [], 'sources' => $sources]];
        } catch (\RuntimeException $exception) {
            $previous = $exception->getPrevious();
            preg_match('/cURL error (\d+)/', $previous?->getMessage() ?? '', $curlError);
            logger()->warning('Intellia: falha ao consultar o provedor.', [
                'exception_class' => get_class($previous ?? $exception),
                'http_status' => $previous instanceof \Illuminate\Http\Client\RequestException ? $previous->response->status() : null,
                'curl_error' => isset($curlError[1]) ? (int) $curlError[1] : null,
            ]);
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
