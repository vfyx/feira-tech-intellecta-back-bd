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
        [$s,$data]=callApi('POST','auth/register',['name'=>'Teste direct '.$letter,'username'=>'qa_'.$suffix.$letter,'email'=>'qa_'.$suffix.$letter.'@example.test','password'=>'Teste!123456','password_confirmation'=>'Teste!123456']);
        check($s===201,'Cadastro isolado'); $tokens[$letter]=$data['token']; $users[$letter]=(int)$data['user']['id'];
    }
    $action=function($name,$data,$token) { [$s,$result]=callApi('POST','intellecta/action',['action'=>$name]+$data,$token);check($s===200,'Ação '.$name.' HTTP '.$s);return $result; };
    $state=function($token) { [$s,$result]=callApi('GET','intellecta/state',[],$token);check($s===200,'Estado');return $result; };
    $action('message',['target'=>$users['b'],'body'=>'Histórico preservado'],$tokens['a']);
    $before=DB::table('mensagens')->count();
    $action('close-direct',['target'=>$users['b']],$tokens['a']);
    $a=$state($tokens['a']);check(in_array((string)$users['b'],$a['account']['closedDirect'],true),'Fechada para remetente');
    $b=$state($tokens['b']);check(!in_array((string)$users['a'],$b['account']['closedDirect'],true),'Outro usuário não afetado');
    check(DB::table('mensagens')->count()===$before,'Histórico intacto');
    $action('open-direct',['target'=>$users['b']],$tokens['a']);
    check(!in_array((string)$users['b'],$state($tokens['a'])['account']['closedDirect'],true),'Reabertura manual');
    $action('close-direct',['target'=>$users['b']],$tokens['a']);
    $action('message',['target'=>$users['a'],'body'=>'Uma nova mensagem'],$tokens['b']);
    check(!in_array((string)$users['b'],$state($tokens['a'])['account']['closedDirect'],true),'Reabertura por mensagem recebida');
    $action('close-direct',['target'=>$users['b']],$tokens['a']);
    check(in_array((string)$users['b'],$state($tokens['a'])['account']['closedDirect'],true),'Pode fechar novamente');
    echo "PASS: $checks verificações; fechamento persistente, privacidade, histórico preservado e reaberturas. Dados revertidos.\n";
} finally { DB::rollBack(); }
