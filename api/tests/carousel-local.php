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
    $suffix=bin2hex(random_bytes(6));
    [$code,$auth]=callApi('POST','auth/register',['name'=>'QA Carrossel','username'=>'carousel_'.$suffix,'email'=>$suffix.'@example.test','password'=>'Teste!123456','password_confirmation'=>'Teste!123456']);
    check($code===201,'Cadastro de teste'); $token=$auth['token'];
    $image='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aRZkAAAAASUVORK5CYII=';
    foreach ([1,3,6] as $count) {
        [$code,$post]=callApi('POST','intellecta/action',['action'=>'post','body'=>'Galeria de teste','media_type'=>'imagem','medias'=>array_fill(0,$count,$image)],$token);
        check($code===200,'Publicação com '.$count.' imagens');
        check(DB::table('midias_publicacoes')->where('post_id',$post['id'])->count()===$count,'Todas as fotos persistidas');
        [$code,$state]=callApi('GET','intellecta/state',[],$token);
        check($code===200,'Carregar feed');
        $entry=collect($state['feed'])->firstWhere('id',(string)$post['id']);
        check(count($entry['mediaList'])===$count,'Todas as fotos retornadas após recarregar');
        check($state['feed'][0]['id']===(string)$post['id'],'Posts recentes primeiro');
    }
    [$code]=callApi('POST','intellecta/action',['action'=>'post','medias'=>array_fill(0,7,$image)],$token);
    check($code===422,'Recusa mais de seis fotos');
    [$code]=callApi('POST','intellecta/action',['action'=>'post','medias'=>[$image,'invalid']],$token);
    check($code===422,'Recusa imagem inválida');
    [$code]=callApi('POST','intellecta/action',['action'=>'post','medias'=>[$image,$image],'media_type'=>'video'],$token);
    check($code===422,'Recusa carrossel de vídeos');
    [$code]=callApi('POST','intellecta/action',['action'=>'post','media'=>$image,'media_type'=>'imagem'],$token);
    check($code===200,'Mantém upload individual anterior');
    echo "PASS: $checks verificações de carrossel, persistência, limites e ordem do feed; dados revertidos.\n";
} finally { DB::rollBack(); }
