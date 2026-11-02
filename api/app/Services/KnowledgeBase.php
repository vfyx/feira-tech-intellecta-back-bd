<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\Config;

class KnowledgeBase
{
    public function __construct(private AIService $ai) {}

    private function chunks(string $text, ?int $page = null): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        $chunks = [];
        for ($offset = 0, $length = mb_strlen($text); $offset < $length; $offset += 1800) {
            $chunks[] = ['content' => mb_substr($text, $offset, 2000), 'page' => $page];
            if ($offset + 2000 >= $length) break;
        }
        return $chunks;
    }

    public function indexPost(Post $post, bool $force = false): int
    {
        if ($post->status !== Post::STATUS_APPROVED
            || DB::table('preferencias_usuarios')->where('user_id', $post->user_id)->where('perfil_publico', false)->exists()
            || DB::table('usuarios')->where('id', $post->user_id)->whereNotNull('suspended_at')->exists()) return 0;
        $text = $post->title . "\n" . $post->content;
        return $this->store('post:'.$post->id, [
            'source_type' => 'post', 'post_id' => $post->id, 'subject_id' => $post->subject_id,
            'title' => mb_substr($post->title, 0, 255), 'filename' => null,
        ], md5($text), $this->chunks($text), $force);
    }

    public function indexFile(string $path, ?string $title = null, ?int $subject = null, bool $force = false): array
    {
        $real = realpath($path);
        if (!$real || !is_file($real) || !is_readable($real) || filesize($real) > 20 * 1024 * 1024) {
            throw new RuntimeException('Informe um arquivo legível de até 20 MB.');
        }
        if ($subject && !DB::table('disciplinas')->where('id', $subject)->exists()) {
            throw new RuntimeException('Disciplina não encontrada.');
        }
        $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $chunks = [];
        if ($extension === 'pdf') {
            $config = new Config();
            $config->setDecodeMemoryLimit(20 * 1024 * 1024);
            $pages = (new Parser([], $config))->parseFile($real)->getPages();
            if (count($pages) > 500) throw new RuntimeException('O limite é de 500 páginas por PDF.');
            foreach ($pages as $number => $page) {
                array_push($chunks, ...$this->chunks($page->getText(), $number + 1));
                if (count($chunks) > 500) throw new RuntimeException('Divida o material em arquivos menores (até 500 trechos).');
            }
        } elseif (in_array($extension, ['txt', 'md'], true)) {
            $text = file_get_contents($real);
            if (!mb_check_encoding($text, 'UTF-8')) throw new RuntimeException('Salve o texto como UTF-8.');
            $chunks = $this->chunks($text);
        } else {
            throw new RuntimeException('Formatos aceitos: PDF com texto, TXT e Markdown.');
        }
        if (!$chunks) throw new RuntimeException('Nenhum texto encontrado. PDFs digitalizados precisam de OCR antes da importação.');
        if (count($chunks) > 500) throw new RuntimeException('Divida o material em arquivos menores (até 500 trechos).');
        $key = 'document:'.hash('sha256', PHP_OS_FAMILY === 'Windows' ? mb_strtolower($real) : $real);
        $metadata = [
            'source_type' => 'document', 'post_id' => null, 'subject_id' => $subject,
            'title' => mb_substr($title ?: pathinfo($real, PATHINFO_FILENAME), 0, 255),
            'filename' => mb_substr(basename($real), 0, 255),
        ];
        return ['source_key' => $key, 'chunks' => $this->store(
            $key, $metadata, md5(json_encode([$metadata, $chunks], JSON_THROW_ON_ERROR)), $chunks, $force
        )];
    }

    private function store(string $key, array $metadata, string $hash, array $chunks, bool $force): int
    {
        [$provider, $model] = $this->ai->embeddingProfile();
        $current = DB::table('trechos_conhecimento')->where('source_key', $key)->first();
        if (!$force && $current && $current->content_hash === $hash
            && $current->provider === $provider && $current->embedding_model === $model) return 0;

        // As chamadas externas acontecem antes da troca atômica dos trechos.
        $rows = [];
        foreach ($chunks as $index => $chunk) {
            $vector = $this->ai->embedding($chunk['content'], 'RETRIEVAL_DOCUMENT');
            $rows[] = $metadata + $chunk + [
                'source_key' => $key, 'chunk_index' => $index, 'content_hash' => $hash,
                'provider' => $provider, 'embedding_model' => $model,
                'embedding' => json_encode($vector, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::transaction(function () use ($key, $rows): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$key]);
            DB::table('trechos_conhecimento')->where('source_key', $key)->delete();
            foreach ($rows as $row) DB::table('trechos_conhecimento')->insert($row);
        });
        return count($rows);
    }

    public function search(string $question, int $viewer, ?int $subject = null): array
    {
        [$provider, $model] = $this->ai->embeddingProfile();
        $vector = json_encode($this->ai->embedding($question, 'RETRIEVAL_QUERY'), JSON_THROW_ON_ERROR);
        $distance = 't.embedding <=> ?::vector';
        $query = DB::table('trechos_conhecimento as t')
            ->leftJoin('publicacoes as p', 'p.id', '=', 't.post_id')
            ->leftJoin('usuarios as u', 'u.id', '=', 'p.user_id')
            ->leftJoin('preferencias_usuarios as prefs', 'prefs.user_id', '=', 'u.id')
            ->leftJoin('disciplinas as d', 'd.id', '=', 't.subject_id')
            ->where('t.provider', $provider)->where('t.embedding_model', $model)
            ->when($subject, fn ($q) => $q->where('t.subject_id', $subject))
            ->where(function ($q) use ($viewer): void {
                $q->where('t.source_type', 'document')->orWhere(function ($posts) use ($viewer): void {
                    $posts->where('t.source_type', 'post')->where('p.status', Post::STATUS_APPROVED)->whereNull('u.suspended_at')
                        // Uma edição deixa o vetor antigo invisível até a reindexação.
                        ->whereRaw("t.content_hash = md5(p.title || chr(10) || p.content)")
                        ->where(fn ($visibility) => $visibility->where('u.id', $viewer)
                            ->orWhere('prefs.perfil_publico', true)->orWhereNull('prefs.user_id'))
                        ->whereNotExists(function ($blocks) use ($viewer): void {
                            $blocks->selectRaw('1')->from('bloqueios as b')->where(fn ($b) => $b
                                ->where(fn ($pair) => $pair->where('b.bloqueador_id', $viewer)->whereColumn('b.bloqueado_id', 'u.id'))
                                ->orWhere(fn ($pair) => $pair->whereColumn('b.bloqueador_id', 'u.id')->where('b.bloqueado_id', $viewer)));
                        });
                });
            })
            ->whereRaw($distance.' <= ?', [$vector, max(0, min(2, (float) config('services.ai.max_distance', 0.45)))])
            ->select(['t.id', 't.source_type', 't.post_id', 't.title', 't.filename', 't.page', 't.content', 'u.name as author', 'd.name as subject'])
            ->selectRaw($distance.' AS distance', [$vector])->orderBy('distance')->orderBy('t.id')->limit(5);
        return $query->get()->map(static fn ($row): array => (array) $row)->all();
    }
}
