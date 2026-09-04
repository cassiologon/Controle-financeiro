<?php

namespace Tests\Unit;

use App\Ai\Agents\TransactionCategorizer;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\CategoryMatcherService;
use App\Services\TransactionCategorizerService;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class TransactionCategorizerServiceTest extends TestCase
{
    private ?string $capturedPrompt = null;

    public function test_parcelas_da_mesma_compra_viram_uma_unica_linha_no_prompt(): void
    {
        $categories = collect([$this->category(7, 'Casa')]);

        $transactions = collect([
            $this->transaction(1, 'Merkel Eletrica - Parcela 12/12'),
            $this->transaction(2, 'Merkel Eletrica - Parcela 11/12'),
            $this->transaction(3, 'Merkel Eletrica - Parcela 10/12'),
            $this->transaction(4, 'PAG*Ifood'),
        ]);

        $service = $this->service($this->agentReturning([
            ['transaction_id' => 1, 'category_id' => 7, 'new_category_name' => '', 'new_category_icon' => '', 'confidence' => 0.9, 'keywords' => ['merkel'], 'reason' => 'Loja de material elétrico.'],
            ['transaction_id' => 4, 'category_id' => 7, 'new_category_name' => '', 'new_category_icon' => '', 'confidence' => 0.8, 'keywords' => ['ifood'], 'reason' => 'Delivery de comida.'],
        ]));

        $suggestions = $service->suggest($transactions, $categories);

        $listedTransactions = $this->transactionsSection();

        $this->assertSame(2, substr_count($listedTransactions, '- id '));
        $this->assertStringContainsString('- id 1 |', $listedTransactions);
        $this->assertStringContainsString('- id 4 |', $listedTransactions);
        $this->assertStringNotContainsString('- id 2 |', $listedTransactions);
        $this->assertStringNotContainsString('- id 3 |', $listedTransactions);
        $this->assertStringContainsString('Devolva exatamente 2 sugestões', $this->capturedPrompt);

        $this->assertCount(4, $suggestions);

        $byTransaction = collect($suggestions)->keyBy('transaction_id');

        foreach ([1, 2, 3] as $transactionId) {
            $this->assertSame(7, $byTransaction[$transactionId]['category_id']);
            $this->assertSame(0.9, $byTransaction[$transactionId]['confidence']);
            $this->assertSame(['merkel'], $byTransaction[$transactionId]['keywords']);
            $this->assertSame('ai', $byTransaction[$transactionId]['source']);
        }
    }

    public function test_lote_da_ia_conta_grupos_e_nao_transacoes(): void
    {
        $categories = collect([$this->category(7, 'Casa')]);

        $transactions = collect([
            $this->transaction(1, 'Merkel Eletrica - Parcela 12/12'),
            $this->transaction(2, 'Merkel Eletrica - Parcela 11/12'),
            $this->transaction(3, 'PAG*Ifood'),
        ]);

        $service = $this->service($this->agentReturning([
            ['transaction_id' => 1, 'category_id' => 7, 'new_category_name' => '', 'new_category_icon' => '', 'confidence' => 0.9, 'keywords' => ['merkel'], 'reason' => 'Material elétrico.'],
        ]));

        $suggestions = $service->suggest($transactions, $categories, aiGroupLimit: 1);

        $this->assertSame(1, substr_count($this->transactionsSection(), '- id '));
        $this->assertCount(2, $suggestions);
        $this->assertSame([1, 2], collect($suggestions)->pluck('transaction_id')->all());
    }

    public function test_transacoes_com_keyword_conhecida_nao_chegam_na_ia(): void
    {
        $categories = collect([$this->category(7, 'Casa', ['merkel'])]);

        $transactions = collect([
            $this->transaction(1, 'Merkel Eletrica - Parcela 12/12'),
            $this->transaction(2, 'Merkel Eletrica - Parcela 11/12'),
        ]);

        $agent = $this->createMock(TransactionCategorizer::class);
        $agent->expects($this->never())->method('prompt');

        $suggestions = $this->service($agent)->suggest($transactions, $categories);

        $this->assertCount(2, $suggestions);
        $this->assertSame('keywords', $suggestions[0]['source']);
    }

    public function test_descricoes_sem_texto_apos_a_normalizacao_nao_sao_agrupadas(): void
    {
        $service = $this->service($this->createMock(TransactionCategorizer::class));

        $this->assertSame('merkel eletrica', $service->normalizeDescription('Merkel Eletrica - Parcela 12/12'));
        $this->assertSame('mercpago', $service->normalizeDescription('MERCPAGO 12/24'));
        $this->assertSame('uber trip sao paulo', $service->normalizeDescription('UBER *TRIP SAO PAULO'));
        $this->assertSame('drogasil', $service->normalizeDescription('DROGASIL 1234'));
        $this->assertSame('', $service->normalizeDescription('12/12'));
    }

    /**
     * Só a seção de transações do prompt — a lista de categorias usa o mesmo
     * formato de linha.
     */
    private function transactionsSection(): string
    {
        return substr(
            $this->capturedPrompt,
            (int) strpos($this->capturedPrompt, '## Transações a classificar')
        );
    }

    public function test_categoria_proposta_pela_ia_vem_como_categoria_nova(): void
    {
        $categories = collect([$this->category(7, 'Casa')]);
        $transactions = collect([$this->transaction(1, 'DROGASIL 1234')]);

        $service = $this->service($this->agentReturning([
            [
                'transaction_id' => 1,
                'category_id' => 0,
                'new_category_name' => 'farmácia',
                'new_category_icon' => '💊',
                'confidence' => 0.92,
                'keywords' => ['drogasil'],
                'reason' => 'Rede de farmácias.',
            ],
        ]));

        $suggestion = $service->suggest($transactions, $categories)[0];

        $this->assertNull($suggestion['category_id']);
        $this->assertSame('Farmácia', $suggestion['new_category']['name']);
        $this->assertSame('💊', $suggestion['new_category']['icon']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $suggestion['new_category']['color']);
        $this->assertSame(0.92, $suggestion['confidence']);
        $this->assertSame(['drogasil'], $suggestion['keywords']);
    }

    public function test_categoria_nova_equivalente_a_uma_existente_reaproveita_a_existente(): void
    {
        $categories = collect([$this->category(7, 'Farmacia')]);
        $transactions = collect([$this->transaction(1, 'DROGASIL 1234')]);

        $service = $this->service($this->agentReturning([
            [
                'transaction_id' => 1,
                'category_id' => 0,
                'new_category_name' => 'Farmácia',
                'new_category_icon' => '💊',
                'confidence' => 0.9,
                'keywords' => ['drogasil'],
                'reason' => 'Rede de farmácias.',
            ],
        ]));

        $suggestion = $service->suggest($transactions, $categories)[0];

        $this->assertSame(7, $suggestion['category_id']);
        $this->assertNull($suggestion['new_category']);
    }

    public function test_categoria_nova_sem_nome_utilizavel_nao_vira_sugestao(): void
    {
        $categories = collect([$this->category(7, 'Casa')]);
        $transactions = collect([$this->transaction(1, 'PAGTO 4477')]);

        $service = $this->service($this->agentReturning([
            [
                'transaction_id' => 1,
                'category_id' => 0,
                'new_category_name' => '',
                'new_category_icon' => '',
                'confidence' => 0.8,
                'keywords' => ['pagto'],
                'reason' => 'Descrição ilegível.',
            ],
        ]));

        $suggestion = $service->suggest($transactions, $categories)[0];

        $this->assertNull($suggestion['category_id']);
        $this->assertNull($suggestion['new_category']);
        $this->assertSame(0.0, $suggestion['confidence']);
        $this->assertSame([], $suggestion['keywords']);
    }

    private function service(TransactionCategorizer $agent): TransactionCategorizerService
    {
        return new TransactionCategorizerService($agent, new CategoryMatcherService);
    }

    /**
     * @param  array<int, array<string, mixed>>  $suggestions
     */
    private function agentReturning(array $suggestions): TransactionCategorizer&MockObject
    {
        $agent = $this->createMock(TransactionCategorizer::class);

        $agent->method('prompt')->willReturnCallback(function (string $prompt) use ($suggestions) {
            $this->capturedPrompt = $prompt;

            return new StructuredAgentResponse(
                'fake-invocation',
                ['suggestions' => $suggestions],
                '',
                new Usage,
                new Meta,
            );
        });

        return $agent;
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function category(int $id, string $name, array $keywords = []): Category
    {
        $category = new Category(['name' => $name, 'type' => 'expense', 'keywords' => $keywords]);
        $category->id = $id;

        return $category;
    }

    private function transaction(int $id, string $description): Transaction
    {
        $transaction = new Transaction([
            'description' => $description,
            'amount' => -266.66,
            'date' => '2026-05-03',
            'type' => 'expense',
            'status' => 'pending',
        ]);
        $transaction->id = $id;

        return $transaction;
    }
}
