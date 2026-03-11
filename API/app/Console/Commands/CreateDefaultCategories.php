<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Database\Seeders\DefaultCategoriesSeeder;

class CreateDefaultCategories extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'categories:create-default {--user-id= : ID do usuário específico}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cria categorias padrão para usuários que ainda não possuem';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $userId = $this->option('user-id');

        if ($userId) {
            $user = User::find($userId);
            if (!$user) {
                $this->error("Usuário com ID {$userId} não encontrado.");
                return 1;
            }

            $this->createCategoriesForUser($user);
            $this->info("Categorias padrão criadas para o usuário: {$user->name}");
        } else {
            $users = User::all();
            $created = 0;

            foreach ($users as $user) {
                if ($this->createCategoriesForUser($user)) {
                    $created++;
                }
            }

            $this->info("Categorias padrão criadas para {$created} usuário(s).");
        }

        return 0;
    }

    private function createCategoriesForUser(User $user): bool
    {
        // Verifica se o usuário já tem categorias
        if ($user->categories()->count() > 0) {
            return false;
        }

        DefaultCategoriesSeeder::createDefaultCategories($user->id);
        return true;
    }
}
