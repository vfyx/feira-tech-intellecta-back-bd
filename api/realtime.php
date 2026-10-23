<?php
// Servidor SSE local não bloqueante: várias abas/clientes sem ocupar o servidor HTTP da API.
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$server=@stream_socket_server('tcp://127.0.0.1:8001',$errno,$error);
if (!$server) { fwrite(STDERR,"Tempo real: porta 8001 indisponível.\n"); exit(1); }
stream_set_blocking($server,false);
$clients=[]; $lastTick=0;
while (true) {
    $read=[$server]; foreach($clients as $c) $read[]=$c['socket'];
    $write=[]; foreach($clients as $c) if($c['out']!=='') $write[]=$c['socket'];
    $except=null; @stream_select($read,$write,$except,0,200000);
    foreach($read as $socket) {
        if ($socket===$server) {
            if ($new=@stream_socket_accept($server,0)) {
                stream_set_blocking($new,false);
                $clients[(int)$new]=['socket'=>$new,'in'=>'','out'=>'','auth'=>null,'version'=>null,'started'=>time()];
            }
            continue;
        }
        $id=(int)$socket; $data=@fread($socket,16384);
        if ($data===false || feof($socket)) { fclose($socket);unset($clients[$id]);continue; }
        if ($clients[$id]['auth']) continue;
        $clients[$id]['in'].=$data;
        if(strlen($clients[$id]['in'])>16384) {fclose($socket);unset($clients[$id]);continue;}
        if(!str_contains($clients[$id]['in'],"\r\n\r\n")) continue;
        $request=$clients[$id]['in']; $valid=false;
        try {
            preg_match('#^GET (/events\?[^ ]+) HTTP/1\.[01]#',$request,$match);
            parse_str(parse_url($match[1]??'',PHP_URL_QUERY)??'',$query);
            [$payload,$sig]=array_pad(explode('.',(string)($query['ticket']??''),2),2,'');
            $auth=json_decode(base64_decode($payload,true)?:'',true);
            $valid=$sig!=='' && hash_equals(hash_hmac('sha256',$payload,config('app.key')),$sig)
                && is_array($auth) && ($auth['exp']??0)>time() && ($auth['uid']??0)>0;
            preg_match('/\r\nOrigin: ([^\r\n]+)/i',$request,$originMatch);
            $origin=$originMatch[1]??'';
            $valid=$valid && (bool)preg_match('#^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?$#',$origin);
            if($valid) $valid=DB::table('personal_access_tokens')->where('id',$auth['token'])->where('tokenable_id',$auth['uid'])
                ->where(fn($q)=>$q->whereNull('expires_at')->orWhere('expires_at','>',now()))->exists();
        } catch(Throwable $e) { $valid=false; }
        if(!$valid) { @fwrite($socket,"HTTP/1.1 403 Forbidden\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");fclose($socket);unset($clients[$id]);continue; }
        $clients[$id]['auth']=$auth; $clients[$id]['in']='';
        $clients[$id]['out']="HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nCache-Control: no-cache\r\nAccess-Control-Allow-Origin: $origin\r\nConnection: keep-alive\r\nX-Accel-Buffering: no\r\n\r\nretry: 3000\n\n";
    }
    foreach($write as $socket) {
        $id=(int)$socket; if(!isset($clients[$id]))continue;
        $n=@fwrite($socket,$clients[$id]['out']);
        if($n===false){fclose($socket);unset($clients[$id]);continue;}
        $clients[$id]['out']=substr($clients[$id]['out'],$n);
    }
    if(time()===$lastTick)continue;
    $lastTick=time();$versions=[];
    foreach($clients as $id=>&$c) {
        $auth=$c['auth'];
        if((!$auth && time()-$c['started']>10) || ($auth && $auth['exp']<=time()) || strlen($c['out'])>65536){fclose($c['socket']);unset($clients[$id]);continue;}
        if(!$auth)continue;
        try {
            if(!DB::table('personal_access_tokens')->where('id',$auth['token'])->where('tokenable_id',$auth['uid'])->exists()){fclose($c['socket']);unset($clients[$id]);continue;}
            $uid=$auth['uid'];
            if(!isset($versions[$uid])) {
                $messages=DB::table('mensagens as m')->join('conversas as c','c.id','=','m.conversa_id')
                    ->where(fn($q)=>$q->where('c.usuario_1_id',$uid)->orWhere('c.usuario_2_id',$uid))
                    ->selectRaw('count(*) as total, max(m.id) as last, count(m.lida_em) as reads')->first();
                $notices=DB::table('notificacoes')->where('user_id',$uid)->selectRaw('count(*) as total, max(id) as last, count(lida_em) as reads')->first();
                $versions[$uid]=hash('sha256',json_encode([$messages,$notices]));
            }
            if($c['version']!==$versions[$uid]){
                $c['out'].="event: update\ndata: {}\n\n"; $c['version']=$versions[$uid];
            } elseif(time()%15===0) $c['out'] .= ": heartbeat\n\n";
        } catch(Throwable $e) { $c['out'].="event: unavailable\ndata: {}\n\n"; }
    }
    unset($c);
}
