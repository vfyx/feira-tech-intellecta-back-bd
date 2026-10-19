<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Credencial de desenvolvimento herdada do projeto original.
        // firstOrCreate preserva a senha e o username de um admin já existente.
        $admin = User::firstOrCreate(
            ['email' => 'admin@escola.local'],
            [
                'name' => 'Administrador',
                'username' => 'admin_' . bin2hex(random_bytes(8)),
                'password' => 'password',
                'role' => User::ROLE_ADMIN,
            ]
        );

        DB::table('preferencias_usuarios')->insertOrIgnore(['user_id' => $admin->id]);
    }
}
