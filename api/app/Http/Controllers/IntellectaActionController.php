<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class IntellectaActionController extends Controller
{
    private function toggle(string $table, array $key): bool
    {
        $query = DB::table($table)->where($key);
        if ($query->exists()) { $query->delete(); return false; }
        DB::table($table)->insert($key);
        return true;
    }

    private function visible(int $viewer, int $target): bool
    {
        return !DB::table('bloqueios')->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('bloqueador_id', $viewer)->where('bloqueado_id', $target))
            ->orWhere(fn ($q) => $q->where('bloqueador_id', $target)->where('bloqueado_id', $viewer)))->exists();
    }

    private function publicProfile(int $target): bool
    {
        $prefs = DB::table('preferencias_usuarios')->where('user_id', $target)->first();
        return !$prefs || $prefs->perfil_publico;
    }

    private function notice(int $to, int $actor, string $type, ?int $post = null, ?int $message = null): void
    {
        if ($to === $actor || !$this->visible($to, $actor)) return;
        DB::table('notificacoes')->insert(['user_id'=>$to, 'autor_id'=>$actor, 'tipo'=>$type,
            'post_id'=>$post, 'mensagem_id'=>$message, 'url'=>$post ? '?page=home&post='.$post : ($message ? '?page=chat' : '?page=notifications')]);
    }

    public function __invoke(Request $request): array
    {
        $action = $request->validate(['action'=>['required', Rule::in([
            'profile','setting','post','like','save','comment','vote','repost','mute','delete-post',
            'follow','block','notify','report','message','clear-chat','read','password',
        ])]])['action'];
        $uid = (int) $request->user()->id;
        return DB::transaction(function () use ($request, $action, $uid): array {
            // Serializa alternâncias e votos da mesma conta.
            DB::table('usuarios')->where('id', $uid)->lockForUpdate()->first();
            $result = ['message'=>'Alteração salva.'];
            if ($action === 'profile') {
                $request->merge(['username'=>strtolower(ltrim(trim((string) $request->input('username')), '@'))]);
                $data = $request->validate([
                    'name'=>'required|string|max:100', 'username'=>['required','regex:/\A[a-z0-9_]{3,30}\z/',Rule::unique('usuarios')->ignore($uid)],
                    'bio'=>'nullable|string|max:1000','birthdate'=>'nullable|date_format:Y-m-d|before_or_equal:today',
                    'gender'=>['nullable',Rule::in(['feminino','masculino','outro'])],
                    'avatar'=>'nullable|string|max:2800000',
                ]);
                $profileUpdate = ['name'=>$data['name'],'username'=>$data['username'],
                    'bio'=>$data['bio']??'', 'nascimento'=>$data['birthdate']??null,'genero'=>$data['gender']??null,'updated_at'=>now()];
                if (array_key_exists('avatar', $data)) {
                    $avatar = $data['avatar'];
                }
                if (isset($avatar)) {
                    abort_unless(preg_match('#\Adata:image/(png|jpeg|gif|webp);base64,([A-Za-z0-9+/=]+)\z#', $avatar, $match), 422, 'Imagem inválida.');
                    $bytes = base64_decode($match[2], true);
                    $info = $bytes ? @getimagesizefromstring($bytes) : false;
                    abort_unless($info && $info['mime'] === 'image/' . $match[1] && strlen($bytes) <= 2097152 && $info[0] * $info[1] <= 25000000, 422, 'Imagem inválida ou muito grande.');
                    $profileUpdate['avatar_url'] = $avatar;
                }
                DB::table('usuarios')->where('id',$uid)->update($profileUpdate);
                return ['message'=>'Perfil atualizado.'];
            }
            if ($action === 'password') {
                $data=$request->validate(['current_password'=>'required|string','new_password'=>'required|string|min:8|max:200|same:confirm_password','confirm_password'=>'required|string']);
                abort_unless(Hash::check($data['current_password'],$request->user()->password),422,'Senha atual incorreta.');
                $request->user()->forceFill(['password'=>$data['new_password']])->save();
                $request->user()->tokens()->where('id','!=',$request->user()->currentAccessToken()->id)->delete();
                return ['message'=>'Senha alterada. Outros acessos foram encerrados.'];
            }
            if ($action === 'setting') {
                $map=['motion'=>'movimento','compact-feed'=>'feed_compacto','profile-public'=>'perfil_publico',
                    'activity-status'=>'mostrar_atividade','personalized-feed'=>'feed_personalizado','login-alerts'=>'alertas_acesso'];
                $data=$request->validate(['key'=>['required',Rule::in(array_keys($map))],'value'=>'required|boolean']);
                DB::table('preferencias_usuarios')->updateOrInsert(['user_id'=>$uid],[$map[$data['key']]=>$data['value'],'updated_at'=>now()]);
                return ['message'=>'Preferência salva.'];
            }
            if ($action === 'read') {
                DB::table('notificacoes')->where('user_id',$uid)->whereNull('lida_em')->update(['lida_em'=>now()]);
                return ['message'=>'Notificações marcadas como lidas.'];
            }
            if ($action === 'post') {
                $data=$request->validate(['body'=>'nullable|string|max:5000','topic'=>'nullable|string|max:100',
                    'media'=>'nullable|string|max:2800000','poll'=>'nullable|array','poll.question'=>'required_with:poll|string|max:300',
                    'poll.options'=>'required_with:poll|array|min:2|max:6','poll.options.*'=>'required|string|max:150|distinct']);
                $body=trim($data['body']??''); $poll=$data['poll']??null; $image=$data['media']??null;
                abort_if($body==='' && !$poll && !$image,422,'Escreva ou anexe algo antes de publicar.');
                if ($image) {
                    abort_unless(preg_match('#\Adata:image/(png|jpeg|gif|webp);base64,([A-Za-z0-9+/=]+)\z#',$image,$match),422,'Imagem inválida.');
                    $bytes=base64_decode($match[2],true); $info=$bytes ? @getimagesizefromstring($bytes) : false;
                    abort_unless($info && $info['mime']==='image/'.$match[1] && strlen($bytes)<=2097152 && $info[0]*$info[1]<=25000000,422,'Imagem inválida ou muito grande.');
                }
                $subject=DB::table('disciplinas')->where('name',$data['topic']??'')->value('id');
                if (!$subject && !empty($data['topic']) && $data['topic']!=='Geral') abort(422,'Escolha uma disciplina disponível.');
                if (!$subject) $subject=DB::table('disciplinas')->where('name','Ideias')->value('id') ?? DB::table('disciplinas')->orderBy('id')->value('id');
                abort_unless($subject,422,'Cadastre uma disciplina antes de publicar.');
                $content=$body ?: ($poll['question']??'Publicação com imagem');
                $id=DB::table('publicacoes')->insertGetId(['user_id'=>$uid,'subject_id'=>$subject,'title'=>mb_substr($content,0,200),
                    'content'=>$content,'status'=>'aprovado','created_at'=>now(),'updated_at'=>now()]);
                if ($image) DB::table('midias_publicacoes')->insert(['post_id'=>$id,'tipo'=>'imagem','url'=>$image]);
                if ($poll) {
                    $qid=DB::table('enquetes')->insertGetId(['post_id'=>$id,'pergunta'=>trim($poll['question'])]);
                    foreach ($poll['options'] as $order=>$text) DB::table('opcoes_enquetes')->insert(['enquete_id'=>$qid,'texto'=>trim($text),'ordem'=>$order]);
                }
                return ['id'=>(string)$id,'message'=>'Publicação criada.'];
            }
            if (in_array($action,['like','save','comment','vote','repost','mute','delete-post'],true)) {
                $id=$request->validate(['id'=>'required|integer|min:1'])['id'];
                $post=DB::table('publicacoes')->where('id',$id)->lockForUpdate()->first();
                abort_unless($post && $this->visible($uid,$post->user_id) && ($post->user_id===$uid || ($post->status==='aprovado' && $this->publicProfile($post->user_id))),404,'Publicação não encontrada.');
                if ($action==='delete-post') { abort_unless($post->user_id===$uid,403); DB::table('publicacoes')->where('id',$id)->delete(); }
                elseif ($action==='comment') {
                    $body=trim($request->validate(['body'=>'required|string|max:2000'])['body']); abort_if($body==='',422);
                    DB::table('comentarios')->insert(['post_id'=>$id,'user_id'=>$uid,'content'=>$body,'created_at'=>now(),'updated_at'=>now()]);
                    $this->notice($post->user_id,$uid,'comentario',$id);
                } elseif ($action==='vote') {
                    $index=$request->validate(['option'=>'required|integer|min:0|max:5'])['option'];
                    $poll=DB::table('enquetes')->where('post_id',$id)->first();
                    $option=$poll ? DB::table('opcoes_enquetes')->where('enquete_id',$poll->id)->where('ordem',$index)->first() : null;
                    abort_unless($option,422,'Opção inválida.');
                    DB::table('votos_enquetes')->updateOrInsert(['enquete_id'=>$poll->id,'user_id'=>$uid],['opcao_id'=>$option->id]);
                } else {
                    $table=['like'=>'curtidas','save'=>'publicacoes_salvas','repost'=>'republicacoes','mute'=>'publicacoes_ocultadas'][$action];
                    $result['active']=$this->toggle($table,['user_id'=>$uid,'post_id'=>$id]);
                    if ($action==='like' && $result['active']) $this->notice($post->user_id,$uid,'curtida',$id);
                }
                return $result;
            }
            $target=(int)$request->validate(['target'=>'required|integer|min:1|exists:usuarios,id'])['target'];
            abort_if($target===$uid,422,'Perfil inválido.');
            if ($action!=='block') abort_unless($this->visible($uid,$target),403,'Este perfil está bloqueado.');
            if ($action==='block') {
                $result['active']=$this->toggle('bloqueios',['bloqueador_id'=>$uid,'bloqueado_id'=>$target]);
                if ($result['active']) {
                    DB::table('seguidores')->where(fn($q)=>$q->where('seguidor_id',$uid)->where('seguido_id',$target))
                        ->orWhere(fn($q)=>$q->where('seguidor_id',$target)->where('seguido_id',$uid))->delete();
                    DB::table('assinaturas_publicacoes')->where(fn($q)=>$q->where('user_id',$uid)->where('autor_id',$target))
                        ->orWhere(fn($q)=>$q->where('user_id',$target)->where('autor_id',$uid))->delete();
                }
            } elseif ($action==='follow' || $action==='notify') {
                abort_unless($this->publicProfile($target),403,'Perfil privado.');
                $result['active']=$action==='follow' ? $this->toggle('seguidores',['seguidor_id'=>$uid,'seguido_id'=>$target])
                    : $this->toggle('assinaturas_publicacoes',['user_id'=>$uid,'autor_id'=>$target]);
                if ($action==='follow' && $result['active']) $this->notice($target,$uid,'seguidor');
            } elseif ($action==='report') {
                $reason=$request->validate(['reason'=>'required|string|max:2000'])['reason'];
                DB::table('denuncias_perfis')->insert(['denunciante_id'=>$uid,'denunciado_id'=>$target,'motivo'=>$reason]);
                return ['message'=>'Denúncia registrada.'];
            } elseif ($action==='message' || $action==='clear-chat') {
                $key=['usuario_1_id'=>min($uid,$target),'usuario_2_id'=>max($uid,$target)];
                $conversation=DB::table('conversas')->where($key)->first();
                if ($action==='clear-chat') {
                    if ($conversation) foreach (DB::table('mensagens')->where('conversa_id',$conversation->id)->pluck('id') as $mid)
                        DB::table('mensagens_ocultadas')->insertOrIgnore(['user_id'=>$uid,'mensagem_id'=>$mid]);
                    return $result;
                }
                $body=trim($request->validate(['body'=>'required|string|max:4000'])['body']); abort_if($body==='',422);
                DB::table('conversas')->insertOrIgnore($key+['created_at'=>now(),'updated_at'=>now()]);
                $cid=DB::table('conversas')->where($key)->value('id');
                $mid=DB::table('mensagens')->insertGetId(['conversa_id'=>$cid,'remetente_id'=>$uid,'conteudo'=>$body,'tipo'=>'texto','created_at'=>now(),'updated_at'=>now()]);
                DB::table('conversas')->where('id',$cid)->update(['updated_at'=>now()]);
                $this->notice($target,$uid,'mensagem',null,$mid);
            }
            return $result;
        }, 3);
    }
}
