<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class DefaultCategoriesSeeder extends Seeder
{
    /**
     * Cria categorias padrão para um usuário
     */
    public static function createDefaultCategories($userId): void
    {
        $defaultCategories = [
            // Despesas
            [
                'name' => 'Habitação',
                'type' => 'expense',
                'icon' => '🏠',
                'color' => '#3b82f6',
            ],
            [
                'name' => 'Alimentação',
                'type' => 'expense',
                'icon' => '🍔',
                'color' => '#10b981',
            ],
            [
                'name' => 'Transporte',
                'type' => 'expense',
                'icon' => '🚗',
                'color' => '#f59e0b',
            ],
            [
                'name' => 'Saúde',
                'type' => 'expense',
                'icon' => '🏥',
                'color' => '#ef4444',
            ],
            [
                'name' => 'Educação',
                'type' => 'expense',
                'icon' => '📚',
                'color' => '#8b5cf6',
            ],
            [
                'name' => 'Lazer',
                'type' => 'expense',
                'icon' => '🎬',
                'color' => '#ec4899',
            ],
            [
                'name' => 'Vestuário',
                'type' => 'expense',
                'icon' => '👕',
                'color' => '#06b6d4',
            ],
            [
                'name' => 'Financeiro',
                'type' => 'expense',
                'icon' => '💳',
                'color' => '#6366f1',
            ],
            [
                'name' => 'Outros',
                'type' => 'expense',
                'icon' => '📦',
                'color' => '#6b7280',
            ],
            // Receitas
            [
                'name' => 'Salário',
                'type' => 'income',
                'icon' => '💰',
                'color' => '#10b981',
            ],
            [
                'name' => 'Freelance',
                'type' => 'income',
                'icon' => '💼',
                'color' => '#3b82f6',
            ],
            [
                'name' => 'Investimentos',
                'type' => 'income',
                'icon' => '📈',
                'color' => '#8b5cf6',
            ],
            [
                'name' => 'Outras Receitas',
                'type' => 'income',
                'icon' => '💵',
                'color' => '#06b6d4',
            ],
        ];

        foreach ($defaultCategories as $category) {
            Category::create([
                'user_id' => $userId,
                ...$category,
            ]);
        }
    }
}

