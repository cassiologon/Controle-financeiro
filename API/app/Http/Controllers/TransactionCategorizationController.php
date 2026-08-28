<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Transaction;
use App\Services\CategoryMatcherService;
use App\Services\KeywordExtractorService;
use App\Services\TransactionCategorizerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TransactionCategorizationController extends Controller
{
    public function __construct(
        private TransactionCategorizerService $categorizerService,
        private KeywordExtractorService $keywordExtractor,
        private CategoryMatcherService $matcher
    ) {}

    /**
     * Gera sugestões de categoria para transações pendentes ou sem categoria.
     */
    public function suggest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scope' => 'sometimes|in:pending,uncategorized,all',
            'transaction_ids' => 'sometimes|array',
            'transaction_ids.*' => 'integer',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        $userId = $request->user()->id;
        $scope = $validated['scope'] ?? 'pending';
        $limit = (int) ($validated['limit'] ?? config('ai.categorization.batch_size'));

        // O lote da IA conta grupos distintos, não linhas: buscamos um universo
        // maior para que parcelas e repetições do mesmo estabelecimento sejam
        // agrupadas e resolvidas de uma vez.
        $fetchLimit = max($limit, (int) config('ai.categorization.fetch_limit'));

        $categories = Category::where('user_id', $userId)
            ->where('type', 'expense')
            ->orderBy('name')
            ->get();

        if ($categories->isEmpty()) {
            return response()->json([
                'message' => 'Crie ao menos uma categoria de despesa antes de usar a sugestão por IA.',
            ], 422);
        }

        $query = Transaction::where('user_id', $userId)
            ->where('type', 'expense');

        if (! empty($validated['transaction_ids'])) {
            $query->whereIn('id', $validated['transaction_ids']);
        } else {
            match ($scope) {
                'pending' => $query->where('status', 'pending'),
                'uncategorized' => $query->whereNull('category_id'),
                'all' => $query->where(fn ($q) => $q->where('status', 'pending')->orWhereNull('category_id')),
            };
        }

        $transactions = $query
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit($fetchLimit)
            ->get();

        if ($transactions->isEmpty()) {
            return response()->json([
                'suggestions' => [],
                'analyzed' => 0,
                'min_confidence' => (float) config('ai.categorization.min_confidence'),
                'message' => 'Nenhuma transação aguardando categorização.',
            ]);
        }

        try {
            $suggestions = $this->categorizerService->suggest($transactions, $categories, $limit);
        } catch (RuntimeException $e) {
            Log::info('Falha ao sugerir categorias', [
                'user_id' => $userId,
                'scope' => $scope,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 503);
        }

        $categoriesById = $categories->keyBy('id');

        $payload = collect($suggestions)
            ->map(function (array $suggestion) use ($categoriesById) {
                $category = $suggestion['category_id']
                    ? $categoriesById->get($suggestion['category_id'])
                    : null;

                $suggestion['category'] = $category ? [
                    'id' => $category->id,
                    'name' => $category->name,
                    'icon' => $category->icon,
                    'color' => $category->color,
                ] : null;

                return $suggestion;
            })
            ->sortByDesc('confidence')
            ->values()
            ->all();

        return response()->json([
            'suggestions' => $payload,
            'analyzed' => count($payload),
            'min_confidence' => (float) config('ai.categorization.min_confidence'),
        ]);
    }

    /**
     * Aplica as sugestões confirmadas pelo usuário, criando as categorias que
     * a IA propôs e que ainda não existem.
     */
    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'suggestions' => 'required|array|min:1',
            'suggestions.*.transaction_id' => 'required|integer',
            'suggestions.*.category_id' => 'nullable|integer',
            'suggestions.*.new_category' => 'nullable|array',
            'suggestions.*.new_category.name' => 'required_with:suggestions.*.new_category|string|max:60',
            'suggestions.*.new_category.icon' => 'nullable|string|max:16',
            'suggestions.*.new_category.color' => 'nullable|string|max:7',
            'suggestions.*.keywords' => 'sometimes|array',
            'suggestions.*.keywords.*' => 'string|max:100',
        ]);

        $userId = $request->user()->id;

        $transactions = Transaction::where('user_id', $userId)
            ->whereIn('id', collect($validated['suggestions'])->pluck('transaction_id')->unique())
            ->get()
            ->keyBy('id');

        $categories = Category::where('user_id', $userId)
            ->where('type', 'expense')
            ->get()
            ->keyBy('id');

        $appliedIds = [];
        $skipped = [];
        $createdCategories = [];

        foreach ($validated['suggestions'] as $suggestion) {
            $transaction = $transactions->get($suggestion['transaction_id']);

            $category = ! empty($suggestion['category_id'])
                ? $categories->get($suggestion['category_id'])
                : $this->resolveNewCategory($userId, $suggestion['new_category'] ?? null, $categories, $createdCategories);

            if (! $transaction || ! $category) {
                $skipped[] = $suggestion['transaction_id'];

                continue;
            }

            $transaction->update([
                'category_id' => $category->id,
                'status' => 'categorized',
            ]);

            $appliedIds[] = $transaction->id;

            $this->learnKeywords($transaction, $category, $suggestion['keywords'] ?? []);
        }

        Log::info('Sugestões de categoria aplicadas', [
            'user_id' => $userId,
            'applied' => count($appliedIds),
            'skipped' => count($skipped),
            'created_categories' => count($createdCategories),
        ]);

        return response()->json([
            'applied_ids' => $appliedIds,
            'applied' => count($appliedIds),
            'skipped' => $skipped,
            'created_categories' => array_values($createdCategories),
        ]);
    }

    /**
     * Devolve a categoria da sugestão de categoria nova: reaproveita uma
     * equivalente do usuário quando existe e cria só quando é realmente nova.
     *
     * @param  array<string, mixed>|null  $newCategory
     * @param  \Illuminate\Support\Collection<int, Category>  $categories
     * @param  array<string, array<string, mixed>>  $createdCategories
     */
    private function resolveNewCategory(int $userId, ?array $newCategory, Collection $categories, array &$createdCategories): ?Category
    {
        $name = trim((string) ($newCategory['name'] ?? ''));

        if ($name === '') {
            return null;
        }

        $slug = $this->matcher->normalizeName($name);

        $existing = $categories->first(
            fn (Category $category) => $this->matcher->normalizeName((string) $category->name) === $slug
        );

        if ($existing) {
            return $existing;
        }

        $category = Category::create([
            'user_id' => $userId,
            'name' => $name,
            'type' => 'expense',
            'icon' => $newCategory['icon'] ?? '📁',
            'color' => $newCategory['color'] ?? '#6366f1',
            'keywords' => [],
        ]);

        // Mantém a categoria recém-criada visível para as próximas sugestões
        // do mesmo lote, que costumam propor o mesmo nome.
        $categories->put($category->id, $category);
        $createdCategories[$slug] = [
            'id' => $category->id,
            'name' => $category->name,
            'icon' => $category->icon,
            'color' => $category->color,
        ];

        return $category;
    }

    /**
     * Guarda as palavras-chave da sugestão na categoria, para que a próxima
     * transação parecida seja resolvida sem chamar a IA.
     *
     * @param  array<int, string>  $keywords
     */
    private function learnKeywords(Transaction $transaction, Category $category, array $keywords): void
    {
        if ($category->type !== 'expense' || blank($transaction->description)) {
            return;
        }

        try {
            $words = array_values(array_filter(array_map('trim', $keywords)));

            if (empty($words)) {
                $words = $this->keywordExtractor->extractSignificantWords($transaction->description);
            }

            if (! empty($words)) {
                $category->addKeywords($words);
            }
        } catch (\Exception $e) {
            // Aprender keywords é secundário: não deve derrubar a categorização.
            Log::error('Erro ao aprender keywords da sugestão de IA: '.$e->getMessage(), [
                'category_id' => $category->id,
                'transaction_id' => $transaction->id,
            ]);
        }
    }
}
