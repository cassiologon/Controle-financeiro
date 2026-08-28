<?php

namespace App\Services;

use App\Ai\Agents\TransactionCategorizer;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

class TransactionCategorizerService
{
    public function __construct(
        private TransactionCategorizer $agent,
        private CategoryMatcherService $matcher
    ) {
    }

    /**
     * Sugere uma categoria para cada transação recebida.
     *
     * As transações que já casam com as keywords das categorias do usuário são
     * resolvidas localmente, sem custo de IA. As demais vão para o agente.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @param  Collection<int, Category>  $categories
     * @return array<int, array<string, mixed>>
     */
    public function suggest(Collection $transactions, Collection $categories): array
    {
        if ($transactions->isEmpty() || $categories->isEmpty()) {
            return [];
        }

        $suggestions = [];
        $needsAi = [];

        foreach ($transactions as $transaction) {
            $matched = $this->matchByKeywords($transaction, $categories);

            if ($matched) {
                $suggestions[$transaction->id] = [
                    'transaction_id' => $transaction->id,
                    'category_id' => $matched->id,
                    'confidence' => 1.0,
                    'keywords' => [],
                    'reason' => 'Descrição casa com uma palavra-chave já cadastrada na categoria.',
                    'source' => 'keywords',
                ];

                continue;
            }

            $needsAi[] = $transaction;
        }

        if (! empty($needsAi)) {
            foreach ($this->suggestWithAi(collect($needsAi), $categories) as $suggestion) {
                $suggestions[$suggestion['transaction_id']] = $suggestion;
            }
        }

        return array_values($suggestions);
    }

    /**
     * Envia o lote ao agente e devolve as sugestões já validadas.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @param  Collection<int, Category>  $categories
     * @return array<int, array<string, mixed>>
     */
    private function suggestWithAi(Collection $transactions, Collection $categories): array
    {
        $provider = config('ai.categorization.provider');
        $model = config('ai.categorization.model');
        $timeout = (int) config('ai.categorization.timeout');

        info('Sugerindo categorias com IA', [
            'provider' => $provider,
            'model' => $model,
            'transactions_count' => $transactions->count(),
            'categories_count' => $categories->count(),
        ]);

        try {
            $response = $this->agent->prompt(
                $this->buildUserPrompt($transactions, $categories),
                provider: $provider,
                model: $model,
                timeout: $timeout,
            );
        } catch (AiException $e) {
            info('Erro do provedor de IA ao sugerir categorias', [
                'provider' => $provider,
                'model' => $model,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw new RuntimeException('Não foi possível sugerir categorias no momento. Tente novamente.');
        } catch (Throwable $e) {
            info('Falha inesperada ao sugerir categorias', [
                'provider' => $provider,
                'model' => $model,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw new RuntimeException('Não foi possível sugerir categorias no momento. Tente novamente.');
        }

        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException('Resposta inválida do provedor de IA.');
        }

        info('Sugestões de categoria geradas', [
            'prompt_tokens' => $response->usage->promptTokens,
            'completion_tokens' => $response->usage->completionTokens,
            'reasoning_tokens' => $response->usage->reasoningTokens,
        ]);

        return $this->normalizeResponse(
            $response->toArray(),
            $transactions->pluck('id')->all(),
            $categories->pluck('id')->all()
        );
    }

    /**
     * Descarta o que o modelo devolveu fora do combinado: ids desconhecidos,
     * duplicatas e confiança fora da faixa.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, int>  $validTransactionIds
     * @param  array<int, int>  $validCategoryIds
     * @return array<int, array<string, mixed>>
     */
    private function normalizeResponse(array $payload, array $validTransactionIds, array $validCategoryIds): array
    {
        $suggestions = [];

        foreach ($payload['suggestions'] ?? [] as $raw) {
            $transactionId = (int) ($raw['transaction_id'] ?? 0);
            $categoryId = (int) ($raw['category_id'] ?? 0);

            if (! in_array($transactionId, $validTransactionIds, true)) {
                continue;
            }

            if (isset($suggestions[$transactionId])) {
                continue;
            }

            if ($categoryId !== 0 && ! in_array($categoryId, $validCategoryIds, true)) {
                continue;
            }

            $confidence = (float) ($raw['confidence'] ?? 0);
            $confidence = max(0.0, min(1.0, $confidence));

            $suggestions[$transactionId] = [
                'transaction_id' => $transactionId,
                'category_id' => $categoryId ?: null,
                'confidence' => $categoryId ? round($confidence, 2) : 0.0,
                'keywords' => $categoryId ? $this->normalizeKeywords($raw['keywords'] ?? []) : [],
                'reason' => trim((string) ($raw['reason'] ?? '')),
                'source' => 'ai',
            ];
        }

        return array_values($suggestions);
    }

    /**
     * @param  mixed  $keywords
     * @return array<int, string>
     */
    private function normalizeKeywords($keywords): array
    {
        if (! is_array($keywords)) {
            return [];
        }

        $normalized = [];

        foreach ($keywords as $keyword) {
            if (! is_string($keyword)) {
                continue;
            }

            $keyword = mb_strtolower(trim($keyword), 'UTF-8');

            // Keywords muito curtas geram falso positivo no matcher, que faz
            // busca por substring na descrição inteira.
            if (mb_strlen($keyword, 'UTF-8') <= 3) {
                continue;
            }

            if (! in_array($keyword, $normalized, true)) {
                $normalized[] = $keyword;
            }
        }

        return array_slice($normalized, 0, 3);
    }

    /**
     * @param  Collection<int, Category>  $categories
     */
    private function matchByKeywords(Transaction $transaction, Collection $categories): ?Category
    {
        if (blank($transaction->description)) {
            return null;
        }

        foreach ($categories as $category) {
            if ($this->matcher->matchesCategory($transaction->description, $category)) {
                return $category;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @param  Collection<int, Category>  $categories
     */
    private function buildUserPrompt(Collection $transactions, Collection $categories): string
    {
        $lines = [
            'Classifique cada transação abaixo em uma das categorias do usuário.',
            '',
            '## Categorias disponíveis',
        ];

        foreach ($categories as $category) {
            $keywords = collect($category->keywords ?? [])
                ->filter(fn ($keyword) => filled(trim((string) $keyword)))
                ->take(20)
                ->implode(', ');

            $lines[] = sprintf(
                '- id %d | %s%s',
                $category->id,
                $category->name,
                $keywords !== '' ? ' | palavras-chave: ' . $keywords : ' | sem palavras-chave ainda'
            );
        }

        $lines[] = '';
        $lines[] = '## Transações a classificar';

        foreach ($transactions as $transaction) {
            $date = $transaction->date;

            $lines[] = sprintf(
                '- id %d | "%s" | R$ %s | %s%s',
                $transaction->id,
                $transaction->description ?: 'Sem descrição',
                number_format((float) $transaction->amount, 2, ',', '.'),
                $date instanceof \DateTimeInterface ? $date->format('d/m/Y') : (string) $date,
                filled($transaction->bank_name) ? ' | ' . $transaction->bank_name : ''
            );
        }

        $lines[] = '';
        $lines[] = sprintf(
            'Devolva exatamente %d sugestões, uma para cada transação listada.',
            $transactions->count()
        );

        return implode("\n", $lines);
    }
}
