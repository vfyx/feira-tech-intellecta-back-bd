<?php

namespace App\Http\Controllers;

use App\Models\{User, Post, Subject};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function index(Request $request, string $kind): array
    {
        abort_unless(in_array($kind, ['users', 'posts', 'subjects'], true), 404);
        $data = $request->validate(['q'=>'nullable|string|max:120', 'page'=>'nullable|integer|min:1']);
        $q = $data['q'] ?? '';
        $query = match ($kind) {
            'users' => User::query()->select('id','name','email','username','role','verificado_em','suspended_at')->orderBy('name')->orderBy('id'),
            'posts' => Post::query()->with('user:id,name,username')->latest('created_at')->orderByDesc('id'),
            'subjects' => Subject::query()->orderBy('name')->orderBy('id'),
        };
        if ($q !== '') $query->where(function ($query) use ($q, $kind) {
            foreach (match ($kind) {'users'=>['name','username','email'], 'posts'=>['title','content'], default=>['name']} as $column) {
                $query->orWhere($column, 'ilike', '%'.$q.'%');
            }
        });
        $page = $query->paginate(20);
        return ['entries'=>$page->items(), 'total'=>$page->total(), 'more'=>$page->hasMorePages()];
    }

    public function user(Request $request, User $user): array
    {
        $data = $request->validate([
            'name'=>'required|string|max:120',
            'email'=>['required','email','max:255',Rule::unique('usuarios','email')->ignore($user->id)],
            'role'=>['required',Rule::in(['aluno','professor','moderador','admin'])],
            'verified'=>'required|boolean', 'suspended'=>'required|boolean',
            'password'=>'nullable|string|min:12|max:128',
        ]);
        abort_if($user->id === $request->user()->id && ($data['role'] !== 'admin' || $data['suspended']), 422, 'Você não pode remover seu próprio acesso administrativo.');
        DB::transaction(function () use ($data, $user) {
            $user->name=$data['name']; $user->email=$data['email']; $user->role=$data['role'];
            $user->verificado_em=$data['role']==='professor' && $data['verified'] ? ($user->verificado_em ?? now()) : null;
            $user->suspended_at=$data['suspended'] ? ($user->suspended_at ?? now()) : null;
            if (!empty($data['password'])) $user->password=$data['password'];
            $user->save();
            if ($data['suspended'] || !empty($data['password'])) $user->tokens()->delete();
        });
        return ['message'=>'Conta atualizada.'.(!empty($data['password']) ? ' As sessões foram encerradas; entre com a nova senha.' : '')];
    }

    public function post(Request $request, Post $post): array
    {
        $data=$request->validate(['title'=>'required|string|max:255','content'=>'required|string|max:20000','status'=>['required',Rule::in(['aprovado','pendente','reprovado'])]]);
        $post->update($data);
        return ['message'=>'Publicação atualizada.'];
    }

    public function subject(Request $request, ?Subject $subject = null): array
    {
        $data=$request->validate(['name'=>['required','string','max:100',Rule::unique('disciplinas','name')->ignore($subject?->id)]]);
        if ($subject?->exists) $subject->update($data); else Subject::create($data);
        return ['message'=>'Tópico salvo.'];
    }
}
