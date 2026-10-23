<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        $data = $request->validated();
        $automatic = !array_key_exists('username', $data);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $username = $automatic
                ? 'aluno_' . bin2hex(random_bytes(8))
                : $data['username'];

            try {
                $result = DB::transaction(function () use ($data, $username): array {
                    $user = User::create([
                        'name' => $data['name'],
                        'email' => $data['email'],
                        'username' => $username,
                        'password' => $data['password'],
                        'role' => User::ROLE_STUDENT,
                    ]);

                    // Os outros campos recebem os padrões definidos na migration.
                    DB::table('preferencias_usuarios')->insert(['user_id' => $user->id]);

                    return [
                        'user' => $user,
                        'token' => $user->createToken('api')->plainTextToken,
                    ];
                });

                return response()->json($result, 201);
            } catch (QueryException $exception) {
                $state = $exception->errorInfo[0] ?? $exception->getCode();
                $duplicate = in_array((string) $state, ['23505', '23000'], true);
                $usernameConflict = $duplicate && Str::contains(
                    $exception->getMessage(),
                    ['usuarios_username_unique_ci', 'usuarios.username']
                );

                // Trata também a corrida entre validar e efetivamente inserir.
                if ($usernameConflict && $automatic && $attempt < 2) {
                    continue;
                }
                if ($usernameConflict) {
                    throw ValidationException::withMessages([
                        'username' => ['Este nome de usuário já está em uso.'],
                    ]);
                }
                if ($duplicate && Str::contains(
                    $exception->getMessage(), ['users_email_unique', 'usuarios.email']
                )) {
                    throw ValidationException::withMessages([
                        'email' => ['Este e-mail já está cadastrado.'],
                    ]);
                }

                throw $exception;
            }
        }

        throw new \RuntimeException('Não foi possível concluir o cadastro.');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas.'],
            ]);
        }

        abort_if($user->suspended_at !== null, 403, 'Esta conta está suspensa.');
        return DB::transaction(function () use ($user): array {
            $prefs = DB::table('preferencias_usuarios')->where('user_id', $user->id)->first();
            if ($prefs->alertas_acesso ?? false) {
                DB::table('notificacoes')->insert(['user_id'=>$user->id,'tipo'=>'acesso','url'=>'?page=seguranca']);
            }
            return ['user'=>$user, 'token'=>$user->createToken('api')->plainTextToken];
        });
    }

    public function institutionalLogin(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);
        $user = User::where('email', $data['email'])->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['Credenciais inválidas.']]);
        }
        abort_if($user->suspended_at !== null, 403, 'Esta conta está suspensa.');
        abort_unless(
            $user->isAdmin() || $user->role === User::ROLE_MODERATOR || $user->isVerifiedInstitutional(),
            403,
            'Esta conta não possui acesso institucional autorizado.'
        );
        return ['user' => $user, 'token' => $user->createToken('institutional')->plainTextToken];
    }

    public function me(Request $request)
    {
        return $request->user();
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Sessão encerrada.']);
    }
}
