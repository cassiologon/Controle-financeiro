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
    ) {}

    /**
     * Sugere uma categoria para cada transação recebida.
     *
     * As transações que já casam com as keywords das categorias do usuário são
     * resolvidas localmente, sem custo de IA. As demais são agrupadas por
     * descrição normalizada — parcelas da mesma compra e lançamentos repetidos
     * do mesmo estabelecimento viram uma única linha no prompt, e a sugestão do
     * representante é replicada para o grupo inteiro.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @param  Collection<int, Category>  $categories
     * @param  int|null  $aiGroupLimit  Máximo de grupos distintos enviados à IA.
     * @return array<int, array<string, mixed>>
     */
    public function suggest(Collection $transactions, Collection $categories, ?int $aiGroupLimit = null): array
    {
        if ($transactions->isEmpty() || $categories->isEmpty()) {
            return [];
        }

        $suggestions = [];
        $groups = [];

        foreach ($transactions as $transaction) {
            $matched = $this->matchByKeywords($transaction, $categories);

            if ($matched) {
                $suggestions[$transaction->id] = [
                    'transaction_id' => $transaction->id,
                    'category_id' => $matched->id,
                    'new_category' => null,
                    'confidence' => 1.0,
                    'keywords' => [],
                    'reason' => 'Descrição casa com uma palavra-chave já cadastrada na categoria.',
                    'source' => 'keywords',
                ];

                continue;
            }

            $groups[$this->groupKey($transaction)][] = $transaction;
        }

        if (! empty($groups)) {
            if ($aiGroupLimit !== null && count($groups) > $aiGroupLimit) {
                $groups = array_slice($groups, 0, $aiGroupLimit, true);
            }

            info('Transações agrupadas para categorização por IA', [
                'transactions_count' => $transactions->count(),
                'resolved_by_keywords' => count($suggestions),
                'groups_count' => count($groups),
            ]);

            $representatives = collect($groups)->map(fn (array $group) => $group[0])->values();
            $groupsByRepresentative = collect($groups)->keyBy(fn (array $group) => $group[0]->id);

            foreach ($this->suggestWithAi($representatives, $categories) as $suggestion) {
                foreach ($groupsByRepresentative->get($suggestion['transaction_id'], []) as $transaction) {
                    $suggestions[$transaction->id] = [
                        ...$suggestion,
                        'transaction_id' => $transaction->id,
                    ];
                }
            }
        }

        return array_values($suggestions);
    }

    /**
     * Chave usada para juntar transações que representam o mesmo gasto.
     *
     * Descrições que ficam vazias depois da normalização (só números, por
     * exemplo) recebem uma chave própria para não serem agrupadas entre si.
     */
    private function groupKey(Transaction $transaction): string
    {
        $normalized = $this->normalizeDescription($transaction->description);

        return $normalized !== '' ? $normalized : '#'.$transaction->id;
    }

    /**
     * Reduz a descrição ao nome do estabelecimento, descartando o que varia
     * entre lançamentos do mesmo gasto: número de parcela, código de loja,
     * CNPJ, data e pontuação.
     */
    public function normalizeDescription(?string $description): string
    {
        $normalized = mb_strtolower(trim((string) $description), 'UTF-8');

        // "- Parcela 12/12", "PARC 3/10", "(03/10)" marcam a mesma compra.
        $normalized = (string) preg_replace(
            '/\b(?:parc(?:ela)?s?|parcelado)?\.?\s*\(?\d{1,2}\s*\/\s*\d{1,2}\)?/u',
            ' ',
            $normalized
        );

        $normalized = (string) preg_replace('/\d+/u', ' ', $normalized);
        $normalized = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized);

        return trim((string) preg_replace('/\s+/u', ' ', $normalized));
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
            $categories
        );
    }

    /**
     * Descarta o que o modelo devolveu fora do combinado: ids desconhecidos,
     * duplicatas e confiança fora da faixa. Quando o modelo propõe uma
     * categoria nova, ela é validada aqui e — se o usuário já tiver uma
     * equivalente — convertida na categoria existente.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, int>  $validTransactionIds
     * @param  Collection<int, Category>  $categories
     * @return array<int, array<string, mixed>>
     */
    private function normalizeResponse(array $payload, array $validTransactionIds, Collection $categories): array
    {
        $suggestions = [];
        $validCategoryIds = $categories->pluck('id')->all();

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

            $newCategory = null;

            if ($categoryId === 0) {
                $newCategory = $this->normalizeNewCategory(
                    $raw['new_category_name'] ?? '',
                    $raw['new_category_icon'] ?? ''
                );

                // Se o usuário já tem categoria equivalente, ela vale mais do
                // que criar outra com nome parecido.
                $existing = $newCategory
                    ? $this->matchCategoryByName($newCategory['name'], $categories)
                    : null;

                if ($existing) {
                    $categoryId = $existing->id;
                    $newCategory = null;
                }
            }

            $hasCategory = $categoryId !== 0 || $newCategory !== null;

            $confidence = (float) ($raw['confidence'] ?? 0);
            $confidence = max(0.0, min(1.0, $confidence));

            $suggestions[$transactionId] = [
                'transaction_id' => $transactionId,
                'category_id' => $categoryId ?: null,
                'new_category' => $newCategory,
                'confidence' => $hasCategory ? round($confidence, 2) : 0.0,
                'keywords' => $hasCategory ? $this->normalizeKeywords($raw['keywords'] ?? []) : [],
                'reason' => trim((string) ($raw['reason'] ?? '')),
                'source' => 'ai',
            ];
        }

        return array_values($suggestions);
    }

    /**
     * Valida a categoria proposta pelo modelo e escolhe uma cor para ela.
     *
     * @return array{name: string, icon: string, color: string}|null
     */
    private function normalizeNewCategory(mixed $name, mixed $icon): ?array
    {
        if (! is_string($name)) {
            return null;
        }

        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if (mb_strlen($name, 'UTF-8') < 3 || mb_strlen($name, 'UTF-8') > 60) {
            return null;
        }

        $name = mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8').mb_substr($name, 1, null, 'UTF-8');

        $icon = is_string($icon) ? trim($icon) : '';

        if ($icon === '' || mb_strlen($icon, 'UTF-8') > 4) {
            $icon = '📁';
        }

        return [
            'name' => $name,
            'icon' => $icon,
            'color' => $this->colorForNewCategory($name),
        ];
    }

    /**
     * Cor estável por nome, para que a mesma categoria proposta em lotes
     * diferentes chegue sempre com a mesma cor.
     */
    private function colorForNewCategory(string $name): string
    {
        $palette = ['#6366f1', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#0ea5e9', '#ec4899', '#84cc16'];

        return $palette[crc32($this->matcher->normalizeName($name)) % count($palette)];
    }

    /**
     * @param  Collection<int, Category>  $categories
     */
    private function matchCategoryByName(string $name, Collection $categories): ?Category
    {
        $slug = $this->matcher->normalizeName($name);

        if ($slug === '') {
            return null;
        }

        return $categories->first(
            fn (Category $category) => $this->matcher->normalizeName((string) $category->name) === $slug
        );
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
                $keywords !== '' ? ' | palavras-chave: '.$keywords : ' | sem palavras-chave ainda'
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
                filled($transaction->bank_name) ? ' | '.$transaction->bank_name : ''
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
