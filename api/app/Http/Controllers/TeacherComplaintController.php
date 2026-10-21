<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TeacherComplaintController extends Controller
{
    private function entry(object $row): array
    {
        return [
            'id'=>(string)$row->id,
            'protocol'=>'REC-'.str_pad((string)$row->id, 6, '0', STR_PAD_LEFT),
            'studentId'=>(string)$row->aluno_id, 'teacherId'=>(string)$row->professor_id,
            'name'=>$row->nome, 'teacher'=>$row->professor_nome,
            'rm'=>$row->rm, 'course'=>$row->curso, 'class'=>$row->turma,
            'subject'=>$row->assunto, 'description'=>$row->descricao, 'request'=>$row->solicitacao,
            'reply'=>$row->resposta, 'created'=>$row->created_at, 'replied'=>$row->respondida_em,
        ];
    }

    public function index(Request $request): array
    {
        $uid=(int)$request->user()->id;
        $teacher=$request->user()->role==='professor';
        $rows=DB::table('reclamacoes_professores as r')->join('usuarios as u','u.id','=','r.professor_id')
            ->where(function ($q) use ($uid, $teacher) {
                $q->where('r.aluno_id',$uid);
                if ($teacher) $q->orWhere('r.professor_id',$uid);
            })->orderByDesc('r.id')->get(['r.*','u.name as professor_nome']);
        return [
            'teachers'=>DB::table('usuarios')->where('role','professor')->where('id','!=',$uid)
                ->orderBy('name')->get(['id','name'])->map(fn($p)=>['id'=>(string)$p->id,'name'=>$p->name])->all(),
            'entries'=>$rows->map(fn($row)=>$this->entry($row))->all(),
        ];
    }

    public function store(Request $request): array
    {
        abort_unless($request->user()->role==='aluno',403,'Somente alunos podem enviar reclamações por este formulário.');
        foreach (['rm','course','class','subject','description','request'] as $field) {
            if (is_string($request->input($field))) $request->merge([$field=>trim($request->input($field))]);
        }
        $data=$request->validate([
            'request_key'=>'required|uuid',
            'teacher'=>['required','integer',Rule::exists('usuarios','id')->where('role','professor')],
            'rm'=>'nullable|string|max:30','course'=>'required|string|max:120','class'=>'required|string|max:60',
            'subject'=>'required|string|max:150','description'=>'required|string|min:10|max:5000',
            'request'=>'nullable|string|max:2000',
        ]);
        $uid=(int)$request->user()->id;
        return DB::transaction(function () use ($uid,$data,$request) {
            DB::table('usuarios')->where('id',$uid)->lockForUpdate()->first();
            $existing=DB::table('reclamacoes_professores')->where('request_key',$data['request_key'])->first();
            if ($existing) {
                abort_unless((int)$existing->aluno_id===$uid,409,'Identificador de envio indisponível. Reabra o formulário.');
                $id=$existing->id;
            } else {
                $id=DB::table('reclamacoes_professores')->insertGetId([
                    'request_key'=>$data['request_key'],'aluno_id'=>$uid,'professor_id'=>$data['teacher'],
                    'nome'=>$request->user()->name,'rm'=>$data['rm']??null,'curso'=>$data['course'],'turma'=>$data['class'],
                    'assunto'=>$data['subject'],'descricao'=>$data['description'],'solicitacao'=>$data['request']??null,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            return ['message'=>'Reclamação enviada ao professor.','protocol'=>'REC-'.str_pad((string)$id,6,'0',STR_PAD_LEFT)];
        });
    }

    public function reply(Request $request, int $id): array
    {
        abort_unless($request->user()->role==='professor',403);
        $row=DB::table('reclamacoes_professores')->where('id',$id)->where('professor_id',$request->user()->id)->first();
        abort_unless($row,404,'Reclamação não encontrada.');
        if (is_string($request->input('reply'))) $request->merge(['reply'=>trim($request->input('reply'))]);
        $data=$request->validate(['reply'=>'required|string|min:3|max:3000']);
        DB::table('reclamacoes_professores')->where('id',$id)->where('professor_id',$request->user()->id)
            ->update(['resposta'=>$data['reply'],'respondida_em'=>now(),'updated_at'=>now()]);
        return ['message'=>'Resposta enviada ao aluno.'];
    }
}
