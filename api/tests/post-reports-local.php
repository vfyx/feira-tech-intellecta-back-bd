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
        check($s===$expected,'Resposta esperada '.$expected.', recebida '.$s); return $result;
    };
    $post=$call(['action'=>'post','body'=>'Publicação de teste isolado'],$tokens['b']);
    $input=['action'=>'report-post','id'=>$post['id'],'reason'=>'Conteúdo denunciado no teste'];
    $call($input,null,401);
    $call($input,$tokens['b'],403);
    $call(array_replace($input,['reason'=>'   ']),$tokens['a'],422);
    $call(array_replace($input,['reason'=>str_repeat('a',2001)]),$tokens['a'],422);
    $call(array_replace($input,['id'=>0]),$tokens['a'],422);
    $call($input,$tokens['a']);
    check(DB::table('denuncias_publicacoes')->where('post_id',$post['id'])->where('denunciante_id',$users['a'])->where('autor_id',$users['b'])->where('motivo',$input['reason'])->exists(),'Denúncia vinculada ao post e autor');
    $call(['action'=>'block','target'=>$users['b']],$tokens['a']);
    $call($input,$tokens['a'],404);
    echo "PASS: autenticação, autoria, motivo, persistência e bloqueio ($checks verificações); transação revertida.\n";
} finally { DB::rollBack(); }
