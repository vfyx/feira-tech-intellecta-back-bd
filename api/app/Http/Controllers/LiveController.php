<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LiveController extends Controller {
    public function ticket(Request $request): array {
        $payload=base64_encode(json_encode(['uid'=>(int)$request->user()->id,'token'=>(int)$request->user()->currentAccessToken()->id,'exp'=>time()+300]));
        return ['ticket'=>$payload.'.'.hash_hmac('sha256',$payload,config('app.key'))];
    }
    public function read(Request $request): array {
        $data=$request->validate(['target'=>'required|integer|min:1','until'=>'required|integer|min:1']);
        $uid=(int)$request->user()->id; $target=(int)$data['target'];
        $chat=DB::table('conversas')->where('usuario_1_id',min($uid,$target))->where('usuario_2_id',max($uid,$target))->first();
        abort_unless($chat,404);
        abort_unless(DB::table('mensagens')->where('conversa_id',$chat->id)->where('id',$data['until'])->exists(),422);
        $count=DB::table('mensagens')->where('conversa_id',$chat->id)->where('remetente_id',$target)->where('id','<=',$data['until'])->whereNull('lida_em')->update(['lida_em'=>now()]);
        return ['read'=>$count];
    }
}
