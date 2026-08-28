<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Transaction;
use App\Services\KeywordExtractorService;
use App\Services\TransactionCategorizerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TransactionCategorizationController extends Controller
{
    public function __construct(
        private TransactionCategorizerService $categorizerService,
        private KeywordExtractorService $keywordExtractor
    ) {
    }

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
            ->limit($limit)
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
            $suggestions = $this->categorizerService->suggest($transactions, $categories);
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
            'analyzed' => $transactions->count(),
            'min_confidence' => (float) config('ai.categorization.min_confidence'),
        ]);
    }

    /**
     * Aplica as sugestões confirmadas pelo usuário.
     */
    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'suggestions' => 'required|array|min:1',
            'suggestions.*.transaction_id' => 'required|integer',
            'suggestions.*.category_id' => 'required|integer',
            'suggestions.*.keywords' => 'sometimes|array',
            'suggestions.*.keywords.*' => 'string|max:100',
        ]);

        $userId = $request->user()->id;

        $transactions = Transaction::where('user_id', $userId)
            ->whereIn('id', collect($validated['suggestions'])->pluck('transaction_id')->unique())
            ->get()
            ->keyBy('id');

        $categories = Category::where('user_id', $userId)
            ->whereIn('id', collect($validated['suggestions'])->pluck('category_id')->unique())
            ->get()
            ->keyBy('id');

        $appliedIds = [];
        $skipped = [];

        foreach ($validated['suggestions'] as $suggestion) {
            $transaction = $transactions->get($suggestion['transaction_id']);
            $category = $categories->get($suggestion['category_id']);

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
        ]);

        return response()->json([
            'applied_ids' => $appliedIds,
            'applied' => count($appliedIds),
            'skipped' => $skipped,
        ]);
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
            Log::error('Erro ao aprender keywords da sugestão de IA: ' . $e->getMessage(), [
                'category_id' => $category->id,
                'transaction_id' => $transaction->id,
            ]);
        }
    }
}
