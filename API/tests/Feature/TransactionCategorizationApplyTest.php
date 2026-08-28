<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionCategorizationApplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_a_categoria_proposta_pela_ia_uma_unica_vez_no_lote(): void
    {
        $user = User::factory()->create();

        $drogasil = $this->pendingTransaction($user, 'DROGASIL 1234');
        $drogaRaia = $this->pendingTransaction($user, 'DROGA RAIA 998');

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/transactions/ai-suggestions/apply', [
            'suggestions' => [
                [
                    'transaction_id' => $drogasil->id,
                    'category_id' => null,
                    'new_category' => ['name' => 'Farmácia', 'icon' => '💊', 'color' => '#10b981'],
                    'keywords' => ['drogasil'],
                ],
                [
                    'transaction_id' => $drogaRaia->id,
                    'category_id' => null,
                    // Mesma categoria com outra grafia: não pode virar uma segunda.
                    'new_category' => ['name' => 'farmacia', 'icon' => '💊', 'color' => '#10b981'],
                    'keywords' => ['droga raia'],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('applied', 2)
            ->assertJsonCount(1, 'created_categories');

        $categories = Category::where('user_id', $user->id)->get();

        $this->assertCount(1, $categories);
        $this->assertSame('Farmácia', $categories->first()->name);
        $this->assertSame('💊', $categories->first()->icon);
        $this->assertEqualsCanonicalizing(['drogasil', 'droga raia'], $categories->first()->keywords);

        foreach ([$drogasil, $drogaRaia] as $transaction) {
            $transaction->refresh();

            $this->assertSame($categories->first()->id, $transaction->category_id);
            $this->assertSame('categorized', $transaction->status);
        }
    }

    public function test_reaproveita_categoria_existente_com_o_mesmo_nome(): void
    {
        $user = User::factory()->create();

        $category = Category::create([
            'user_id' => $user->id,
            'name' => 'Farmacia',
            'type' => 'expense',
            'keywords' => [],
        ]);

        $transaction = $this->pendingTransaction($user, 'DROGASIL 1234');

        $this->actingAs($user, 'sanctum')->postJson('/api/transactions/ai-suggestions/apply', [
            'suggestions' => [
                [
                    'transaction_id' => $transaction->id,
                    'category_id' => null,
                    'new_category' => ['name' => 'Farmácia', 'icon' => '💊', 'color' => '#10b981'],
                    'keywords' => ['drogasil'],
                ],
            ],
        ])->assertOk()->assertJsonCount(0, 'created_categories');

        $this->assertSame(1, Category::where('user_id', $user->id)->count());
        $this->assertSame($category->id, $transaction->refresh()->category_id);
    }

    public function test_sugestao_sem_categoria_e_sem_proposta_e_ignorada(): void
    {
        $user = User::factory()->create();
        $transaction = $this->pendingTransaction($user, 'PAGTO 4477');

        $this->actingAs($user, 'sanctum')->postJson('/api/transactions/ai-suggestions/apply', [
            'suggestions' => [
                ['transaction_id' => $transaction->id, 'category_id' => null, 'new_category' => null],
            ],
        ])->assertOk()
            ->assertJsonPath('applied', 0)
            ->assertJsonPath('skipped', [$transaction->id]);

        $this->assertSame(0, Category::where('user_id', $user->id)->count());
        $this->assertSame('pending', $transaction->refresh()->status);
    }

    private function pendingTransaction(User $user, string $description): Transaction
    {
        return Transaction::create([
            'user_id' => $user->id,
            'category_id' => null,
            'type' => 'expense',
            'amount' => -120.50,
            'description' => $description,
            'date' => '2026-05-03',
            'status' => 'pending',
        ]);
    }
}
