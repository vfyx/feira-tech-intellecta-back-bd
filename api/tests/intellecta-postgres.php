<?php
// Verificação isolada em transação: nenhum registro de teste fica no banco.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Models\User;

if (DB::getDriverName()!=='pgsql') throw new RuntimeException('Este teste exige PostgreSQL.');
$checks=0;
function check($condition,string $label): void { global $checks; if (!$condition) throw new RuntimeException($label); $checks++; }
function callApi(string $method,string $url,array $data=[],?string $token=null): array {
    global $kernel,$app;
    $app['auth']->forgetGuards();
    $headers=['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json'];
    if ($token) $headers['HTTP_AUTHORIZATION']='Bearer '.$token;
    $r=Illuminate\Http\Request::create('/api/'.$url,$method,[],[],[],$headers,json_encode($data));
    $response=$kernel->handle($r);
    return [$response->getStatusCode(),json_decode($response->getContent(),true)];
}
DB::beginTransaction();
try {
    check(DB::table('pg_extension')->where('extname','vector')->exists(),'pgvector preservado');
    check(DB::table('migrations')->whereIn('migration',[
        '2026_10_14_000001_complementar_perfis_e_relacoes_sociais',
        '2026_10_14_000002_criar_interacoes_e_enquetes',
        '2026_10_14_000003_corrigir_mensagens_e_notificacoes',
        '2026_10_14_000004_criar_denuncias_perfis_e_historico_individual',
    ])->count()===4,'Quatro migrations previamente aplicadas');
    $suffix=bin2hex(random_bytes(5)); $tokens=[]; $users=[];
    foreach (['a','b','c'] as $letter) {
        [$status,$body]=callApi('POST','auth/register',['name'=>'Validação '.$letter,'username'=>'qa_'.$suffix.$letter,
            'email'=>'qa_'.$suffix.$letter.'@example.test','password'=>'Teste!123456','password_confirmation'=>'Teste!123456']);
        check($status===201,'Cadastro'); $tokens[$letter]=$body['token']; $users[$letter]=(int)$body['user']['id'];
        check(!isset($body['user']['password'],$body['user']['chave_adicional_hash']),'Hashes privados');
    }
    $a=$tokens['a']; $b=$tokens['b']; $c=$tokens['c'];
    [$s]=callApi('GET','intellecta/state'); check($s===401,'Estado exige autenticação');
    $action=function(string $name,array $data=[],?string $token=null,int $expected=200) use($a): array {
        [$s,$body]=callApi('POST','intellecta/action',['action'=>$name]+$data,$token??$a);
        check($s===$expected,$name.' HTTP esperado '.$expected.' recebido '.$s);
        return $body??[];
    };
    $state=function(?string $token=null) use($a): array {
        [$s,$body]=callApi('GET','intellecta/state',[],$token??$a); check($s===200,'Leitura do estado'); return $body;
    };
    $action('profile',['name'=>'Perfil Testado','username'=>'qa_'.$suffix.'a','bio'=>'Biografia de teste','birthdate'=>'2005-04-05','gender'=>'outro']);
    check($state()['account']['bio']==='Biografia de teste','Persistência do perfil');
    $action('profile',['name'=>'Teste','username'=>'qa_'.$suffix.'b'],null,422);
    $action('profile',['name'=>'Teste','username'=>'qa_'.$suffix.'a','birthdate'=>'2099-01-01'],null,422);
    $action('setting',['key'=>'compact-feed','value'=>true]); check($state()['account']['settings']['compact-feed']===true,'Preferências');
    $action('setting',['key'=>'role','value'=>true],null,422);
    $action('setting',['key'=>'two-factor','value'=>true],null,422);
    $poll=['question'=>'Qual opção?','options'=>['Primeira','Segunda']];
    $created=$action('post',['body'=>'Publicação de teste','topic'=>'Ciência','poll'=>$poll]); $id=$created['id'];
    check(DB::table('publicacoes')->where('id',$id)->value('status')==='aprovado','Publicação imediata');
    check($created['message']==='Publicação criada.','Confirmação sem espera por moderação');
    check(in_array($id,array_column($state()['feed'],'id'),true),'Autor vê publicação');
    check(in_array($id,array_column($state($b)['feed'],'id'),true),'Outro vê publicação imediatamente');
    [$s,$legacy]=callApi('POST','posts',['title'=>'Publicação direta','content'=>'Teste sem moderação por IA','subject_id'=>DB::table('disciplinas')->value('id')],$a);
    check($s===201 && $legacy['status']==='aprovado','Endpoint de posts também publica diretamente');
    check($action('like',['id'=>$id],$b)['active']===true,'Curtir');
    check($action('like',['id'=>$id],$b)['active']===false,'Descurtir');
    $action('like',['id'=>$id],$b); $action('save',['id'=>$id],$b); $action('repost',['id'=>$id],$b); $action('mute',['id'=>$id],$b);
    $action('comment',['id'=>$id,'body'=>'Comentário de teste'],$b);
    $action('vote',['id'=>$id,'option'=>1],$b); $action('vote',['id'=>$id,'option'=>0],$b);
    $action('vote',['id'=>$id,'option'=>5],$b,422);
    $p=collect($state($b)['feed'])->firstWhere('id',$id);
    check($p['likes']===1 && $p['comments']===1 && $p['poll']['choice']===0 && $p['poll']['counts']===[1,0],'Interações persistentes');
    $action('delete-post',['id'=>$id],$b,403);
    $action('follow',['target'=>$users['a']],$b); check(in_array((string)$users['a'],$state($b)['account']['following'],true),'Seguidor persistente');
    $action('notify',['target'=>$users['a']],$b);
    $action('message',['target'=>$users['a'],'body'=>'Mensagem de teste'],$b);
    check(count($state()['messages'])===1,'Destinatário vê mensagem');
    check(count($state($c)['messages'])===0,'Terceiro não vê mensagem');
    $action('clear-chat',['target'=>$users['a']],$b); check(count($state($b)['messages'])===0,'Ocultação individual');
    check(count($state()['messages'])===1,'Histórico do outro preservado');
    $action('report',['target'=>$users['a'],'reason'=>'Denúncia de teste'],$b);
    $action('read'); check(collect($state()['notifications'])->every(fn($n)=>$n['read']),'Leitura notificações');
    $action('setting',['key'=>'profile-public','value'=>false]);
    check(!in_array($id,array_column($state($b)['feed'],'id'),true),'Perfil privado oculta publicação');
    $action('comment',['id'=>$id,'body'=>'Teste'],$b,404);
    $action('setting',['key'=>'profile-public','value'=>true]);
    $action('block',['target'=>$users['a']],$b);
    check(!in_array($id,array_column($state($b)['feed'],'id'),true),'Bloqueio oculta publicação');
    check(count($state()['messages'])===0,'Bloqueio filtra mensagens nos dois sentidos');
    $action('message',['target'=>$users['b'],'body'=>'Teste'],null,403);
    $action('like',['id'=>$id],$b,404);
    $action('block',['target'=>$users['a']],$b);
    $action('post',['body'=>'Teste','poll'=>['question'=>'Q','options'=>['Igual','Igual']]],null,422);
    $action('post',['body'=>'Teste','media'=>'data:image/png;base64,AAAA'],null,422);
    $action('password',['current_password'=>'incorreta','new_password'=>'Nova!123456','confirm_password'=>'Nova!123456'],null,422);
    [$s,$login]=callApi('POST','auth/login',['email'=>'qa_'.$suffix.'a@example.test','password'=>'Teste!123456']);
    check($s===200,'Login'); $oldToken=$login['token'];
    check(DB::table('notificacoes')->where('user_id',$users['a'])->where('tipo','acesso')->exists(),'Alerta de acesso');
    $action('password',['current_password'=>'Teste!123456','new_password'=>'Nova!123456','confirm_password'=>'Nova!123456']);
    [$s]=callApi('GET','intellecta/state',[],$oldToken); check($s===401,'Troca de senha revoga outros acessos');
    [$s]=callApi('GET','intellecta/state',[],$a); check($s===200,'Sessão atual preservada após troca de senha');
    [$s]=callApi('POST','auth/login',['email'=>'qa_'.$suffix.'a@example.test','password'=>'Nova!123456']); check($s===200,'Nova senha funciona');
    [$s]=callApi('POST','auth/logout',[],$a); check($s===200,'Logout');
    [$s]=callApi('GET','intellecta/state',[],$a); check($s===401,'Token revogado');
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    check(true,'Restrições PostgreSQL e enquetes');
    echo "OK: {$checks} verificações no PostgreSQL; dados de teste revertidos.\n";
} catch (Throwable $e) { echo 'FALHA: '.$e->getMessage()."\n"; $failed=true; }
finally { DB::rollBack(); }
if (!empty($failed)) exit(1);
