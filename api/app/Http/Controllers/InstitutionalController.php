<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InstitutionalController extends Controller
{
    public function index(): array
    {
        return ['users' => User::query()
            ->whereIn('role', [User::ROLE_MODERATOR, User::ROLE_TEACHER, User::ROLE_ADMIN])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'username', 'role', 'verificado_em'])];
    }

    public function update(Request $request, User $user): array
    {
        $data = $request->validate([
            'role' => ['required', Rule::in([
                User::ROLE_STUDENT, User::ROLE_MODERATOR, User::ROLE_TEACHER, User::ROLE_ADMIN,
            ])],
            'verified' => ['required', 'boolean'],
        ]);
        abort_if((int) $request->user()->id === (int) $user->id && $data['role'] !== User::ROLE_ADMIN, 422, 'Não remova seu próprio acesso administrativo.');
        $verified = $data['role'] === User::ROLE_TEACHER && $data['verified'];
        DB::table('usuarios')->where('id', $user->id)->update([
            'role' => $data['role'],
            'verificado_em' => $verified ? now() : null,
            'updated_at' => now(),
        ]);
        return ['message' => 'Permissões atualizadas.'];
    }
}
