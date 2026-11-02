<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\KnowledgeBase;
use Illuminate\Console\Command;

class KnowledgeIndex extends Command
{
    protected $signature = 'knowledge:index {--posts : Indexar publicações aprovadas} {--file= : Caminho do PDF, TXT ou MD} {--title= : Título do material} {--subject= : ID da disciplina do material} {--force : Regerar vetores existentes}';
    protected $description = 'Importa publicações e materiais para a base vetorial da Intellia';

    public function handle(KnowledgeBase $knowledge): int
    {
        if (!$this->option('posts') && !$this->option('file')) {
            $this->error('Use --posts e/ou --file="caminho do material".');
            return self::FAILURE;
        }
        if ($this->option('subject') !== null && (!ctype_digit((string) $this->option('subject')) || (int) $this->option('subject') < 1)) {
            $this->error('--subject deve ser um ID positivo.');
            return self::FAILURE;
        }
        $count = 0;
        try {
            if ($this->option('posts')) {
                Post::where('status', Post::STATUS_APPROVED)->orderBy('id')->chunkById(50, function ($posts) use ($knowledge, &$count): void {
                    foreach ($posts as $post) {
                        $count += $knowledge->indexPost($post, (bool) $this->option('force'));
                    }
                });
            }
            if ($file = $this->option('file')) {
                $result = $knowledge->indexFile($file, $this->option('title'), $this->option('subject') ? (int) $this->option('subject') : null, (bool) $this->option('force'));
                $count += $result['chunks'];
                $this->line('Fonte: '.$result['source_key']);
            }
        } catch (\Throwable $error) {
            // Mensagens de provedores podem conter dados privados; não imprimir o corpo HTTP.
            $this->error('Importação interrompida: '.$this->safeMessage($error));
            $this->line('Fontes já concluídas foram preservadas; execute novamente para continuar.');
            return self::FAILURE;
        }
        $this->info($count.' trecho(s) indexado(s). Fontes inalteradas foram ignoradas.');
        return self::SUCCESS;
    }

    private function safeMessage(\Throwable $error): string
    {
        return $error instanceof \RuntimeException && !$error instanceof \Illuminate\Database\QueryException
            ? $error->getMessage() : 'Confira o arquivo, o banco e a configuração do Gemini.';
    }
}
