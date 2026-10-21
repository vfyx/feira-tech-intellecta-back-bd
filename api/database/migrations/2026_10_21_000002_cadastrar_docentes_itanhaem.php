<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        // Os 48 nomes efetivamente listados no PDF de docentes de Itanhaém.
        $names = [
            'Carla Cristina de Gouveia',
            'Carlos Henrique Marcelino Junior',
            'Cecilia Souza de Jesus',
            'Celso Aparecido da Costa',
            'Claudio Bessi',
            'Claudio Roberto de Vasconcellos Moreira',
            'Cristina Laurinda Simões',
            'Daniel Gomes',
            'Dimorie Silva de Oliveira',
            'Diogenes Leandro Leite Pereira',
            'Eduardo Francisco Pereira de Souza',
            'Eliamar Gutierres Augusto',
            'Eliete Ferreira de Souza Silva',
            'Evelise Teixeira Moraes',
            'Giovanna Lettieri',
            'Guilherme Augusto Soares',
            'Jean Simoes Peixoto',
            'Joelma Lucia Sartori Ribeiro',
            'Jose Adriano de Barros',
            'Jose Wellington da Costa Silva',
            'Julio Cesar Menezes Borges',
            'Jussimar Nascimento Leal',
            'Kariman de Souza Brandao',
            'Karina Verdum Galhardo',
            'Marcelo Gazzo',
            'Matheus Calixto de Oliveira',
            'Meire Mamede',
            'Milena Biazzus da Silva Ribeiro',
            'Moema Setubal de Souza',
            'Pamela Cristina Seppa',
            'Patricia Augusto Carlos',
            'Paulo Eduardo Silva Montier',
            'Regina Celia Luz Vieira de Moraes',
            'Renata da Ressurreição Chagas',
            'Roberta Bezerra Gardim',
            'Rodrigo Ferraz',
            'Rosane Lourdes Souza',
            'Roseli Antonieta Zula',
            'Simone Maria Veloso',
            'Tadeu Dantas de Souza',
            'Talita Rolim de Freitas Lima',
            'Thiago Augusto Leão Pires',
            'Thiago Pellegrini da Silva',
            'Vanessa Perosino Cardoso Siqueira',
            'Vinicius Moreira das Neves',
            'Viviane Barbosa Rasga Aires',
            'Willians Souza de Oliveira',
            'Wilson Correia da Costa',
        ];
        DB::transaction(function () use ($names): void {
            foreach ($names as $name) {
                // Preserva contas existentes; nunca promove alunos por coincidência de nome.
                $existing = DB::table('usuarios')->where('role', 'professor')
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists();
                if ($existing) continue;
                $key = substr(hash('sha256', $name), 0, 16);
                $email = 'docente.'.$key.'@cadastro.intellecta.invalid';
                if (DB::table('usuarios')->where('email', $email)->exists()) {
                    throw new RuntimeException('Cadastro reservado já utilizado: '.$name);
                }
                $id = DB::table('usuarios')->insertGetId([
                    'name' => $name, 'username' => 'docente_'.$key, 'email' => $email,
                    // Sem senha compartilhada ou acesso automático. Configurar o acesso
                    // institucional após confirmar a identidade e o e-mail do docente.
                    'password' => Hash::make(bin2hex(random_bytes(32))),
                    'role' => 'professor', 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('preferencias_usuarios')->insert([
                    'user_id' => $id, 'perfil_publico' => false, 'mostrar_atividade' => false,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Cadastro de dados: preserva docentes e reclamações já vinculadas no rollback.
    }
};
