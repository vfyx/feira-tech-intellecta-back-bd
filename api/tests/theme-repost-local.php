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
    $suffix=bin2hex(random_bytes(5)); $tokens=[]; $users=[];
    foreach (['a','b'] as $letter) {
        [$s,$data]=callApi('POST','auth/register',['name'=>'Teste denúncia','username'=>'qa_'.$suffix.$letter,'email'=>'qa_'.$suffix.$letter.'@example.test','password'=>'Teste!123456','password_confirmation'=>'Teste!123456']);
        check($s===201,'Cadastro isolado'); $tokens[$letter]=$data['token']; $users[$letter]=(int)$data['user']['id'];
    }
    $call=function($data,$token,$expected=200) {
        [$s,$result]=callApi('POST','intellecta/action',$data,$token);
        if ($s===500) echo ($result['message'] ?? 'sem detalhe').PHP_EOL; check($s===$expected,'Resposta esperada '.$expected.', recebida '.$s); return $result;
    };
    $post=$call(['action'=>'post','body'=>'Publicação de teste isolado'],$tokens['b']);
    $call(['action'=>'setting','key'=>'light-theme','value'=>true],$tokens['a']);
    [$s,$state]=callApi('GET','intellecta/state',[],$tokens['a']);
    check($state['account']['settings']['light-theme']===true,'Tema claro persistido');
    [$s,$other]=callApi('GET','intellecta/state',[],$tokens['b']);
    check($other['account']['settings']['light-theme']===false,'Tema isolado por conta');
    $call(['action'=>'setting','key'=>'light-theme','value'=>false],$tokens['a']);
    [$s,$state]=callApi('GET','intellecta/state',[],$tokens['a']);
    check($state['account']['settings']['light-theme']===false,'Retorno ao tema escuro');
    $input=['action'=>'repost','id'=>$post['id']];
    $result=$call($input,$tokens['a']);
    check($result['active']===true,'Republicação ativada'); check(DB::table('notificacoes')->where('tipo','republicacao')->where('post_id',$post['id'])->where('user_id',$users['b'])->exists(),'Notificação entregue ao autor');
    [$s,$state]=callApi('GET','intellecta/state',[],$tokens['a']);
    check(in_array((string)$post['id'],$state['account']['reposts'],true),'Estado contém republicação');
    $result=$call($input,$tokens['a']); check($result['active']===false,'Republicação desfeita');
    echo "PASS: republicar e desfazer na API atual.\n";} finally { DB::rollBack(); }
