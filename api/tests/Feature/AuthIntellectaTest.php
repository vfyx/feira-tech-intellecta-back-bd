<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthIntellectaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Este teste cria uma estrutura mínima APENAS em SQLite em memória.
        // Não executa as migrations PostgreSQL nem toca no banco do projeto.
        if (DB::getDriverName() !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Execute este teste somente com SQLite :memory:.');
        }

        Schema::create('usuarios', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique('users_email_unique');
            $table->string('username');
            $table->string('password');
            $table->string('role')->default('aluno');
            $table->string('token_lembrete')->nullable();
            $table->string('chave_recuperacao_hash')->nullable();
            $table->string('chave_adicional_hash')->nullable();
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX usuarios_username_unique_ci ON usuarios (LOWER(username))');
        Schema::create('preferencias_usuarios', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();
            $table->boolean('movimento')->default(true);
            $table->boolean('perfil_publico')->default(true);
        });
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Aluno Teste',
            'email' => 'aluno@example.test',
            'password' => 'senha_teste_123',
            'password_confirmation' => 'senha_teste_123',
        ], $overrides);
    }

    public function test_front_antigo_cadastra_sem_username(): void
    {
        $response = $this->postJson('/api/auth/register', $this->payload())
            ->assertCreated()->assertJsonStructure(['user', 'token']);

        $user = User::firstOrFail();
        $this->assertMatchesRegularExpression('/\A[a-z0-9_]{3,30}\z/', $user->username);
        $this->assertTrue(Hash::check('senha_teste_123', $user->password));
        $this->assertDatabaseHas('preferencias_usuarios', ['user_id' => $user->id]);
        $response->assertJsonMissingPath('user.password');
    }

    public function test_username_do_intellecta_e_normalizado(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['username' => ' @Enzo_Teste ']))
            ->assertCreated()->assertJsonPath('user.username', 'enzo_teste');
    }

    public function test_username_vazio_nao_e_substituido_silenciosamente(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['username' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->assertDatabaseCount('usuarios', 0);
    }

    public function test_username_invalido_e_rejeitado(): void
    {
        foreach (['ab', 'nome com espaço', str_repeat('a', 31), "en\nzo"] as $username) {
            $this->postJson('/api/auth/register', $this->payload(['username' => $username]))
                ->assertUnprocessable()->assertJsonValidationErrors('username');
        }
    }

    public function test_username_duplicado_e_rejeitado(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['username' => 'enzo_teste']))->assertCreated();
        $this->postJson('/api/auth/register', $this->payload([
            'username' => '@ENZO_TESTE', 'email' => 'outro@example.test',
        ]))->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->assertDatabaseCount('usuarios', 1);
    }

    public function test_email_duplicado_e_rejeitado(): void
    {
        $this->postJson('/api/auth/register', $this->payload())->assertCreated();
        $this->postJson('/api/auth/register', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_cadastro_nao_permite_escalar_para_admin(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['role' => 'admin']))
            ->assertCreated()->assertJsonPath('user.role', 'aluno');
    }

    public function test_confirmacao_da_senha_continua_obrigatoria(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['password_confirmation' => 'diferente']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_token_cadastro_e_hashes_ocultos(): void
    {
        $token = $this->postJson('/api/auth/register', $this->payload())->assertCreated()->json('token');
        User::firstOrFail()->forceFill([
            'chave_recuperacao_hash' => 'hash_privado_recuperacao',
            'chave_adicional_hash' => 'hash_privado_acesso',
        ])->save();
        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('email', 'aluno@example.test')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('token_lembrete')
            ->assertJsonMissingPath('chave_recuperacao_hash')
            ->assertJsonMissingPath('chave_adicional_hash');
    }

    public function test_login_existente_continua_funcionando(): void
    {
        $this->postJson('/api/auth/register', $this->payload())->assertCreated();
        $this->postJson('/api/auth/login', [
            'email' => 'aluno@example.test', 'password' => 'senha_teste_123',
        ])->assertOk()->assertJsonStructure(['user', 'token']);
        $this->postJson('/api/auth/login', [
            'email' => 'aluno@example.test', 'password' => 'errada',
        ])->assertUnprocessable();
    }

    public function test_falha_preferencias_nao_deixa_conta_parcial(): void
    {
        Schema::drop('preferencias_usuarios');
        $this->postJson('/api/auth/register', $this->payload())->assertStatus(500);
        $this->assertDatabaseCount('usuarios', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_seeder_cria_admin_com_username_e_preferencias(): void
    {
        require_once base_path('database/seeders/DatabaseSeeder.php');
        (new \Database\Seeders\DatabaseSeeder())->run();
        $admin = User::firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertMatchesRegularExpression('/\A[a-z0-9_]{3,30}\z/', $admin->username);
        $this->assertDatabaseHas('preferencias_usuarios', ['user_id' => $admin->id]);
    }

    public function test_seeder_preserva_conta_admin_existente(): void
    {
        require_once base_path('database/seeders/DatabaseSeeder.php');
        $admin = User::create([
            'name' => 'Admin existente', 'username' => 'admin_existente',
            'email' => 'admin@escola.local', 'password' => 'minha_senha_123', 'role' => 'admin',
        ]);
        (new \Database\Seeders\DatabaseSeeder())->run();
        (new \Database\Seeders\DatabaseSeeder())->run();
        $this->assertDatabaseCount('usuarios', 1);
        $this->assertDatabaseCount('preferencias_usuarios', 1);
        $admin->refresh();
        $this->assertSame('admin_existente', $admin->username);
        $this->assertTrue(Hash::check('minha_senha_123', $admin->password));
    }
}
