<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class IntellectaStateController extends Controller
{
    private function ids($values): array
    {
        return $values->map(static fn ($id): string => (string) $id)->values()->all();
    }

    private function time($value): string
    {
        return $value ? Carbon::parse($value)->timezone('America/Sao_Paulo')->format('d/m H:i') : '';
    }

    public function __invoke(Request $request): array
    {
        $user = $request->user();
        $uid = (int) $user->id;
        $outgoing = DB::table('bloqueios')->where('bloqueador_id', $uid)->pluck('bloqueado_id');
        $hidden = $outgoing->merge(DB::table('bloqueios')->where('bloqueado_id', $uid)->pluck('bloqueador_id'))->unique()->all();
        $prefs = DB::table('preferencias_usuarios')->where('user_id', $uid)->first();
        $settings = [];
        foreach ([
            'light-theme' => ['tema_claro', false], 'motion' => ['movimento', true], 'compact-feed' => ['feed_compacto', false],
            'email-updates' => ['novidades_email', false], 'profile-public' => ['perfil_publico', true],
            'activity-status' => ['mostrar_atividade', true], 'personalized-feed' => ['feed_personalizado', true],
            'two-factor' => ['chave_adicional_ativa', false], 'login-alerts' => ['alertas_acesso', true],
        ] as $key => [$column, $default]) {
            $settings[$key] = $prefs ? (bool) $prefs->$column : $default;
        }

        $visible = DB::table('usuarios as u')->leftJoin('preferencias_usuarios as p', 'p.user_id', '=', 'u.id')
            ->whereNotIn('u.id', $hidden)
            ->where(function ($query) use ($uid): void {
                $query->where('u.id', $uid)->orWhere('p.perfil_publico', true)->orWhereNull('p.user_id');
            })
            ->orderBy('u.id')->get(['u.id', 'u.name', 'u.username', 'u.bio', 'u.avatar_url', 'u.banner_url',
                'u.curso', 'u.ano_escolar', 'u.role', 'u.verificado_em', 'u.created_at']);
        $visibleIds = $visible->pluck('id')->all();
        $following = DB::table('seguidores')->where('seguidor_id', $uid)->whereNotIn('seguido_id', $hidden)->pluck('seguido_id');
        $account = [
            'id' => (string) $uid, 'name' => $user->name, 'username' => $user->username,
            'email' => $user->email, 'bio' => $user->bio ?? '', 'class' => $user->turma ?? '',
            'avatar' => str_starts_with((string) ($user->avatar_url ?? ''), 'data:image/') ? $user->avatar_url : null,
            'banner' => str_starts_with((string) ($user->banner_url ?? ''), 'data:image/') ? $user->banner_url : null,
            'course' => $user->curso ?? '', 'schoolYear' => $user->ano_escolar ?? '',
            'role' => $user->role, 'verified' => $user->role === 'admin' || ($user->role === 'professor' && $user->verificado_em !== null),
            'birthdate' => $user->nascimento ? substr((string) $user->nascimento, 0, 10) : '',
            'birthdate_label' => $user->nascimento ? Carbon::parse($user->nascimento)->format('d/m/Y') : '',
            'gender' => $user->genero ?? '', 'country' => $user->pais ?? '', 'phone' => $user->telefone ?? '',
            'settings' => $settings, 'demo' => false, 'created' => $user->created_at?->toIso8601String(),
            'following' => $this->ids($following), 'blocked' => $this->ids($outgoing),
            'notify' => $this->ids(DB::table('assinaturas_publicacoes')->where('user_id', $uid)->pluck('autor_id')),
            'saved' => $this->ids(DB::table('publicacoes_salvas')->where('user_id', $uid)->pluck('post_id')),
            'muted' => $this->ids(DB::table('publicacoes_ocultadas')->where('user_id', $uid)->pluck('post_id')),
            'reposts' => $this->ids(DB::table('republicacoes')->where('user_id', $uid)->pluck('post_id')),
            'closedDirect' => $this->ids(DB::table('directs_fechados as f')->where('f.user_id',$uid)
                ->whereNotExists(function ($q) use ($uid) {
                    $q->selectRaw('1')->from('mensagens as m')->join('conversas as c','c.id','=','m.conversa_id')
                        ->whereColumn('m.remetente_id','f.target_id')->whereColumn('m.id','>','f.ultima_mensagem_id')
                        ->where(fn($q)=>$q->where('c.usuario_1_id',$uid)->orWhere('c.usuario_2_id',$uid));
                })->pluck('f.target_id')),
            'chatHidden' => [],
        ];
        $followCounts = DB::table('seguidores')->selectRaw('seguidor_id, count(*) as total')->groupBy('seguidor_id')->pluck('total', 'seguidor_id');
        $followerCounts = DB::table('seguidores')->selectRaw('seguido_id, count(*) as total')->groupBy('seguido_id')->pluck('total', 'seguido_id');
        $people = $visible->map(fn ($person): array => [
            'id' => (string) $person->id, 'name' => $person->name, 'username' => $person->username,
            'bio' => $person->bio ?? '', 'demo' => false, 'created' => $person->created_at,
            'avatar' => str_starts_with((string) ($person->avatar_url ?? ''), 'data:image/') ? $person->avatar_url : null,
            'banner' => str_starts_with((string) ($person->banner_url ?? ''), 'data:image/') ? $person->banner_url : null,
            'course' => $person->curso ?? '', 'schoolYear' => $person->ano_escolar ?? '',
            'role' => $person->role, 'verified' => $person->role === 'admin' || ($person->role === 'professor' && $person->verificado_em !== null),
            'online' => false, 'following' => (int) ($followCounts[$person->id] ?? 0),
            'followers' => (int) ($followerCounts[$person->id] ?? 0),
        ])->all();
        $followers = DB::table('seguidores as f')->join('usuarios as u', 'u.id', '=', 'f.seguidor_id')
            ->where('f.seguido_id', $uid)->whereIn('u.id', $visibleIds)
            ->get(['u.id', 'u.name', 'u.username'])->map(fn ($p): array => [
                'id' => (string) $p->id, 'name' => $p->name, 'username' => $p->username,
            ])->all();

        $posts = DB::table('publicacoes as p')->join('usuarios as u', 'u.id', '=', 'p.user_id')
            ->join('disciplinas as d', 'd.id', '=', 'p.subject_id')->where(fn ($q) => $q->where('p.status', 'aprovado')->orWhere('p.user_id', $uid))
            ->whereIn('p.user_id', $visibleIds)->orderByDesc('p.created_at')->orderByDesc('p.id')->limit(50)
            ->get(['p.*', 'u.name as author_name', 'u.username as author_username', 'd.name as topic']);
        $postIds = $posts->pluck('id')->all();
        $likes = DB::table('curtidas')->whereIn('post_id', $postIds)->get()->groupBy('post_id');
        $comments = DB::table('comentarios as c')->join('usuarios as u', 'u.id', '=', 'c.user_id')
            ->whereIn('c.post_id', $postIds)->whereNotIn('c.user_id', $hidden)->orderBy('c.id')
            ->get(['c.*', 'u.name as author_name', 'u.username as author_username', 'u.role as author_role', 'u.verificado_em as author_verified'])->groupBy('post_id');
        $media = DB::table('midias_publicacoes')->whereIn('post_id', $postIds)->orderBy('id')->get()->groupBy('post_id');
        $polls = DB::table('enquetes')->whereIn('post_id', $postIds)->get()->keyBy('post_id');
        $pollIds = $polls->pluck('id')->all();
        $options = DB::table('opcoes_enquetes')->whereIn('enquete_id', $pollIds)->orderBy('ordem')->get()->groupBy('enquete_id');
        $votes = DB::table('votos_enquetes')->whereIn('enquete_id', $pollIds)->get()->groupBy('enquete_id');
        $feed = $posts->map(function ($post) use ($likes, $comments, $media, $polls, $options, $votes, $uid): array {
            $liked = $likes->get($post->id, collect());
            $replies = $comments->get($post->id, collect())->map(fn ($c): array => [
                'id' => (string) $c->id, 'author' => (string) $c->user_id,
                'name' => $c->author_name, 'username' => $c->author_username,
                'verified' => $c->author_role === 'professor' && $c->author_verified !== null,
                'body' => $c->content, 'time' => $this->time($c->created_at),
            ])->all();
            $poll = null;
            if ($question = $polls->get($post->id)) {
                $choices = $options->get($question->id, collect())->values();
                $allVotes = $votes->get($question->id, collect());
                $own = $allVotes->firstWhere('user_id', $uid);
                $choice = $own ? $choices->search(fn ($option) => (int) $option->id === (int) $own->opcao_id) : false;
                $poll = [
                    'question' => $question->pergunta, 'options' => $choices->pluck('texto')->all(),
                    'counts' => $choices->map(fn ($o): int => $allVotes->where('opcao_id', $o->id)->count())->all(),
                    'choice' => $choice === false ? null : $choice,
                ];
            }
            $attachment = $media->get($post->id, collect())->first();
            $mediaUrl = $attachment?->url;
            $mediaType = $attachment?->tipo;
            $pattern = $mediaType === 'video'
                ? '#^(?:https?://|/(?!/)|data:video/(?:mp4|webm|ogg);base64,)#i'
                : '#^(?:https?://|/(?!/)|data:image/(?:png|jpeg|gif|webp);base64,)#i';
            if ($mediaUrl && (!$mediaType || !preg_match($pattern, $mediaUrl))) $mediaUrl = null;
            return [
                'id' => (string) $post->id, 'author' => (string) $post->user_id,
                'name' => $post->author_name, 'username' => $post->author_username,
                'body' => $post->content, 'created' => $post->created_at, 'time' => $this->time($post->created_at),
                'topic' => $post->topic, 'status' => $post->status, 'likes' => $liked->count(), 'comments' => count($replies),
                'isLiked' => $liked->contains('user_id', $uid), 'replies' => $replies,
                'media' => $mediaUrl, 'mediaType' => $mediaUrl ? $mediaType : null, 'poll' => $poll,
            ];
        })->all();

        $messages = DB::table('mensagens as m')->join('conversas as c', 'c.id', '=', 'm.conversa_id')
            ->where(fn ($q) => $q->where('c.usuario_1_id', $uid)->orWhere('c.usuario_2_id', $uid))
            ->whereNotIn('c.usuario_1_id', $hidden)->whereNotIn('c.usuario_2_id', $hidden)
            ->whereNotExists(function ($q) use ($uid): void {
                $q->selectRaw('1')->from('mensagens_ocultadas as h')->whereColumn('h.mensagem_id', 'm.id')->where('h.user_id', $uid);
            })->orderByDesc('m.id')->limit(300)
            ->get(['m.*', 'c.usuario_1_id', 'c.usuario_2_id'])->reverse()->values()
            ->map(fn ($m): array => [
                'id' => (string) $m->id, 'from' => (string) $m->remetente_id,
                'to' => (string) ((int) $m->remetente_id === (int) $m->usuario_1_id ? $m->usuario_2_id : $m->usuario_1_id),
                'text' => $m->tipo === 'publicacao'
                    ? ($m->publicacao_removida ? 'Publicação removida' : 'Publicação compartilhada') . ($m->conteudo ? "\n" . $m->conteudo : '')
                    : $m->conteudo,
                'time' => $this->time($m->created_at),
            ])->all();
        $labels = ['curtida' => 'curtiu sua publicação', 'comentario' => 'comentou na sua publicação', 'mensagem' => 'enviou uma mensagem', 'mencao' => 'mencionou você em uma publicação', 'republicacao' => 'republicou sua publicação', 'seguidor' => 'começou a seguir você', 'publicacao' => 'publicou uma ideia', 'acesso' => 'Novo acesso à conta'];
        $notifications = DB::table('notificacoes as n')->leftJoin('usuarios as u', 'u.id', '=', 'n.autor_id')
            ->where('n.user_id', $uid)->where(fn ($q) => $q->whereNull('n.autor_id')->orWhereNotIn('n.autor_id', $hidden))
            ->orderByDesc('n.id')->limit(100)->get(['n.*', 'u.name as author_name'])
            ->map(fn ($n): array => [
                'id' => (string) $n->id, 'actor' => $n->author_name ?? 'Intellecta',
                'type' => $labels[$n->tipo] ?? 'Notificação', 'time' => $this->time($n->created_at),
                'read' => $n->lida_em !== null,
                'url' => $n->url && str_starts_with($n->url, '?page=') ? $n->url : '?page=notifications',
            ])->all();
        $subjects = DB::table('disciplinas')->orderBy('name')->get(['id', 'name'])->all();
        return compact('account', 'people', 'followers', 'feed', 'messages', 'notifications', 'subjects');
    }
}
