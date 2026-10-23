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


// O serviço SSE usa outra conexão; contas temporárias são removidas no finally.
$users=[];$sockets=[];
function connectEvents(string $ticket) {
    $socket=stream_socket_client('tcp://127.0.0.1:8001',$errno,$error,3);
    if(!$socket) throw new RuntimeException('Inicie INICIAR-TEMPO-REAL.ps1 antes deste teste.');
    stream_set_timeout($socket,4);
    fwrite($socket,"GET /events?ticket=".urlencode($ticket)." HTTP/1.1\r\nHost: 127.0.0.1:8001\r\nOrigin: http://localhost:8080\r\n\r\n");
    return $socket;
}
function eventReceived($socket): bool {
    while(($line=fgets($socket))!==false) {if(str_starts_with($line,'data:')) return true;}
    return false;
}
try {
    $tokens=[];$tickets=[];$suffix=bin2hex(random_bytes(6));
    foreach(['a','b'] as $key) {
        $u=User::create(['name'=>'QA SSE '.$key,'username'=>'sse_'.$suffix.$key,'email'=>$suffix.$key.'@example.test','password'=>bin2hex(random_bytes(32)),'role'=>'aluno']);
        $users[]=$u->id; DB::table('preferencias_usuarios')->insert(['user_id'=>$u->id]);$tokens[$key]=$u->createToken('qa-sse')->plainTextToken;
        [$code,$ticket]=callApi('POST','live/ticket',[],$tokens[$key]);check($code===200,'Ticket SSE');
        $sockets[$key]=connectEvents($ticket['ticket']);check(eventReceived($sockets[$key]),'Conexão SSE autenticada');
    }
    [$code]=callApi('POST','intellecta/action',['action'=>'message','target'=>$users[1],'body'=>'Teste temporário de entrega'],$tokens['a']);check($code===200,'Enviar mensagem');
    check(eventReceived($sockets['a']),'Evento ao remetente');check(eventReceived($sockets['b']),'Evento ao destinatário');
    [$code,$state]=callApi('GET','intellecta/state?no_feed=1',[],$tokens['b']);$message=end($state['messages']);
    callApi('POST','live/read',['target'=>$users[0],'until'=>$message['id']],$tokens['b']);
    check(eventReceived($sockets['a']),'Leitura entregue em tempo real');
    $bad=connectEvents('invalid');check(str_contains(fgets($bad),'403'),'Recusa assinatura inválida');fclose($bad);

} finally {
    foreach($sockets as $s)if(is_resource($s))fclose($s);
    DB::table('personal_access_tokens')->whereIn('tokenable_id',$users)->where('name','qa-sse')->delete();
    DB::table('conversas')->whereIn('usuario_1_id',$users)->whereIn('usuario_2_id',$users)->delete();
    DB::table('usuarios')->whereIn('id',$users)->delete();
}

echo "PASS: $checks verificações SSE com duas contas; contas temporárias removidas.\n";
