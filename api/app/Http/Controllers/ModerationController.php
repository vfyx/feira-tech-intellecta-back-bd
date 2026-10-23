<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ModerationController extends Controller {
    private function table(string $type): string {
        abort_unless(in_array($type,['perfil','post'],true),404);
        return $type==='perfil' ? 'denuncias_perfis' : 'denuncias_publicacoes';
    }
    public function index(Request $request): array {
        abort_unless($request->user()->isModerator(),403);
        $data=$request->validate(['type'=>['required',Rule::in(['perfil','post'])], 'status'=>['nullable',Rule::in(['nova','em_analise','resolvida','arquivada'])], 'page'=>'nullable|integer|min:1']);
        $target=$data['type']==='perfil'?'denunciado_id':'autor_id';
        $query=DB::table($this->table($data['type']).' as r')->leftJoin('usuarios as u','u.id','=','r.'.$target)
            ->leftJoin('usuarios as m','m.id','=','r.moderador_id');
        if (!empty($data['status'])) $query->where('r.status',$data['status']);
        $rows=$query->orderByDesc('r.id')->paginate(20,['r.*','u.name as target_name','u.username as target_username','m.name as moderator_name']);
        return ['entries'=>$rows->items(),'more'=>$rows->hasMorePages(),'page'=>$rows->currentPage(),'total'=>$rows->total()];
    }
    public function update(Request $request, string $type, int $id): array {
        abort_unless($request->user()->isModerator(),403);
        $table=$this->table($type);
        $data=$request->validate(['status'=>['required',Rule::in(['nova','em_analise','resolvida','arquivada'])], 'resolution'=>'required|string|min:3|max:2000','hide_post'=>'sometimes|boolean']);
        $resolution=trim($data['resolution']); abort_if(mb_strlen($resolution)<3,422,'Descreva a decisão da moderação.');
        return DB::transaction(function() use($table,$id,$type,$data,$resolution,$request) {
            $row=DB::table($table)->where('id',$id)->lockForUpdate()->first(); abort_unless($row,404);
            if (!empty($data['hide_post'])) {
                abort_unless($type==='post' && $row->post_id,422,'Esta denúncia não possui publicação disponível.');
                DB::table('publicacoes')->where('id',$row->post_id)->update(['status'=>'reprovado','updated_at'=>now()]);
            }
            DB::table($table)->where('id',$id)->update(['status'=>$data['status'],'resolucao'=>$resolution,'moderador_id'=>$request->user()->id,'updated_at'=>now()]);
            return ['message'=>'Denúncia atualizada.'];
        });
    }
}
