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
    $suffix=bin2hex(random_bytes(5));$users=[];$tokens=[];
    foreach(['a','b','c','mod'] as $key) {
        [$code,$auth]=callApi('POST','auth/register',['name'=>'QA '.$key,'username'=>'qa_'.$suffix.$key,'email'=>$suffix.$key.'@example.test','password'=>'Teste!123456','password_confirmation'=>'Teste!123456']);
        check($code===201,'Conta de teste');$users[$key]=$auth['user']['id'];$tokens[$key]=$auth['token'];
    }
    DB::table('usuarios')->where('id',$users['mod'])->update(['role'=>'moderador']);
    $subject=DB::table('disciplinas')->first();$ids=[];
    for($i=0;$i<26;$i++) $ids[]=DB::table('publicacoes')->insertGetId(['user_id'=>$users['b'],'subject_id'=>$subject->id,'title'=>'QA','content'=>'Pagina '.$i,'status'=>'aprovado','created_at'=>'2090-01-01 00:00:00','updated_at'=>now()]);
    [$code,$first]=callApi('GET','intellecta/state?view=home',[],$tokens['a']);check($code===200,'Feed');check(count($first['feed'])===12,'Primeira página limitada');check($first['feed'][0]['id']===(string)end($ids),'Desempate por ID');
    [$code,$second]=callApi('GET','intellecta/state?view=home&feed_only=1&cursor='.urlencode($first['feedPage']['cursor']),[],$tokens['a']);check($code===200,'Segunda página');check(count($second['feed'])===12,'Segunda página limitada');check(!array_intersect(array_column($first['feed'],'id'),array_column($second['feed'],'id')),'Sem repetição');
    [$code]=callApi('GET','intellecta/state?cursor=invalid',[],$tokens['a']);check($code===422,'Cursor inválido');
    [$code,$sent]=callApi('POST','intellecta/action',['action'=>'message','target'=>$users['b'],'body'=>'Mensagem QA'],$tokens['a']);check($code===200,'Mensagem enviada');
    [$code,$inbox]=callApi('GET','intellecta/state?no_feed=1',[],$tokens['b']);check(($inbox['unread'][$users['a']]??0)==1,'Contagem não lida');$message=end($inbox['messages']);check(!$message['read'],'Mensagem ainda não lida');
    [$code]=callApi('POST','live/read',['target'=>$users['a'],'until'=>$message['id']],$tokens['c']);check($code===404,'Terceiro não pode marcar leitura');
    [$code]=callApi('POST','live/read',['target'=>$users['a'],'until'=>$message['id']],$tokens['b']);check($code===200,'Destinatário marca leitura');
    [$code,$outbox]=callApi('GET','intellecta/state?no_feed=1',[],$tokens['a']);check(end($outbox['messages'])['read'],'Remetente vê leitura');
    [$code,$ticket]=callApi('POST','live/ticket',[],$tokens['a']);check($code===200 && str_contains($ticket['ticket'],'.'),'Ticket autenticado');
    [$code]=callApi('POST','live/ticket');check($code===401,'Ticket exige autenticação');
    [$code]=callApi('POST','intellecta/action',['action'=>'report-post','id'=>$ids[0],'reason'=>'Relato QA'],$tokens['a']);check($code===200,'Denúncia de post');
    [$code]=callApi('POST','intellecta/action',['action'=>'report','target'=>$users['b'],'reason'=>'Relato QA perfil'],$tokens['a']);check($code===200,'Denúncia de perfil');
    foreach(['post','perfil'] as $type) {
        [$code]=callApi('GET','moderation?type='.$type,[],$tokens['a']);check($code===403,'Aluno não acessa moderação');
        [$code,$list]=callApi('GET','moderation?type='.$type.'&status=nova',[],$tokens['mod']);check($code===200 && count($list['entries'])>0,'Moderador lista denúncias');$id=$list['entries'][0]['id'];
        [$code]=callApi('PATCH','moderation/'.$type.'/'.$id,['status'=>'resolvida','resolution'=>'Analisada no teste','hide_post'=>$type==='post'],$tokens['a']);check($code===403,'Aluno não altera denúncia');
        [$code]=callApi('PATCH','moderation/'.$type.'/'.$id,['status'=>'resolvida','resolution'=>'Analisada no teste','hide_post'=>$type==='post'],$tokens['mod']);check($code===200,'Moderador resolve');
    }
    check(DB::table('publicacoes')->where('id',$ids[0])->value('status')==='reprovado','Post denunciado ocultado');
    echo "PASS: $checks verificações de paginação, leitura, tickets e moderação; dados revertidos.\n";
} finally { DB::rollBack(); }
