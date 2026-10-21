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
    foreach (['a','b','c','d'] as $letter) {
        [$s,$data]=callApi('POST','auth/register',['name'=>'Teste requerimento '.$letter,'username'=>'qa_'.$suffix.$letter,'email'=>'qa_'.$suffix.$letter.'@example.test','password'=>'Teste!123456','password_confirmation'=>'Teste!123456']);
        check($s===201,'Cadastro isolado'); $tokens[$letter]=$data['token']; $users[$letter]=(int)$data['user']['id'];
    }
    DB::table('usuarios')->whereIn('id',[$users['b'],$users['d']])->update(['role'=>'professor']);
    $call=function($method,$path,$data,$token,$expected=200) {
        [$s,$result]=callApi($method,$path,$data,$token);
        check($s===$expected,'HTTP esperado '.$expected.', recebido '.$s); return $result;
    };
    $input=['request_key'=>(string)Illuminate\Support\Str::uuid(),'teacher'=>$users['b'],'course'=>'Informática','class'=>'2º B','rm'=>'123','subject'=>'Revisão de atividade','description'=>'Preciso conversar sobre a correção da atividade.','request'=>'Gostaria de orientação.','name'=>'Nome falso'];
    $call('POST','teacher-complaints',$input,null,401);
    $call('POST','teacher-complaints',$input,$tokens['b'],403);
    $call('POST','teacher-complaints',array_replace($input,['teacher'=>$users['c']]),$tokens['a'],422);
    $call('POST','teacher-complaints',array_replace($input,['description'=>'   ']),$tokens['a'],422);
    $call('POST','teacher-complaints',array_replace($input,['description'=>str_repeat('a',5001)]),$tokens['a'],422);
    $created=$call('POST','teacher-complaints',$input,$tokens['a']);
    $again=$call('POST','teacher-complaints',$input,$tokens['a']);
    check($created['protocol']===$again['protocol'],'Reenvio idempotente');
    check(DB::table('reclamacoes_professores')->where('request_key',$input['request_key'])->count()===1,'Sem duplicata');
    $mine=$call('GET','teacher-complaints',[],$tokens['a']); $id=$mine['entries'][0]['id'];
    check($mine['entries'][0]['name']==='Teste requerimento a','Nome da conta, sem falsificação');
    $inbox=$call('GET','teacher-complaints',[],$tokens['b']); check(count($inbox['entries'])===1,'Professor destinatário recebe');
    foreach (['c','d'] as $other) {
        $private=$call('GET','teacher-complaints',[],$tokens[$other]); check(count($private['entries'])===0,'Terceiros sem acesso');
    }
    $call('PATCH','teacher-complaints/'.$id,['reply'=>'Resposta indevida'],$tokens['c'],403);
    $call('PATCH','teacher-complaints/'.$id,['reply'=>'Resposta indevida'],$tokens['d'],404);
    $call('PATCH','teacher-complaints/'.$id,['reply'=>'   '],$tokens['b'],422);
    $call('PATCH','teacher-complaints/'.$id,['reply'=>'Vamos revisar a atividade juntos.'],$tokens['b']);
    $mine=$call('GET','teacher-complaints',[],$tokens['a']); check($mine['entries'][0]['reply']==='Vamos revisar a atividade juntos.','Aluno recebe resposta');
    echo "PASS: $checks verificações de envio, validação, idempotência, privacidade e resposta; dados revertidos.\n";
} finally { DB::rollBack(); }
