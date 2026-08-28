<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessInvoiceImport;
use App\Models\Transaction;
use App\Services\KeywordExtractorService;
use App\Services\CategoryMatcherService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class InvoiceImportController extends Controller
{
    /**
     * Upload de arquivo de fatura para importação
     */
    public function upload(Request $request): JsonResponse
    {
        // Aceita "files[]" (multiplos) mantendo "file" (unico) para compatibilidade.
        if ($request->hasFile('file') && !$request->hasFile('files')) {
            $request->files->set('files', [$request->file('file')]);
        }

        $validated = $request->validate([
            'files' => 'required|array|min:1|max:20',
            // OFX nao tem mime type confiavel: valida pela extensao.
            'files.*' => 'required|file|extensions:pdf,csv,ofx|max:10240', // 10MB por arquivo
            'bank_name' => 'required|string|max:255',
        ]);

        $user = $request->user();
        $files = $request->file('files');

        // Cria o diretorio manualmente em storage/app/ (nao storage/app/private)
        $userDir = "invoice-imports/{$user->id}";
        $fullDir = storage_path('app/' . $userDir);
        if (!is_dir($fullDir)) {
            mkdir($fullDir, 0755, true);
        }

        $imported = [];
        $failed = [];

        // Processa em ordem para que a reconciliacao de parcelas entre faturas
        // consecutivas encontre os lancamentos ja gravados.
        foreach ($files as $index => $file) {
            $originalName = $file->getClientOriginalName();

            try {
                $filename = time() . '-' . $index . '-' . $originalName;
                $filePath = $userDir . '/' . $filename;
                $file->move($fullDir, $filename);

                // Em ambiente local, processa imediatamente (sem precisar do queue worker)
                if (app()->environment('local')) {
                    ProcessInvoiceImport::dispatchSync($filePath, $user->id, $validated['bank_name']);
                } else {
                    ProcessInvoiceImport::dispatch($filePath, $user->id, $validated['bank_name']);
                }

                $imported[] = $originalName;

                Log::info("Arquivo enviado para processamento", [
                    'user_id' => $user->id,
                    'file_path' => $filePath,
                    'bank_name' => $validated['bank_name'],
                ]);
            } catch (\Exception $e) {
                // Um arquivo com problema nao pode abortar os demais.
                $failed[] = ['file' => $originalName, 'error' => $e->getMessage()];

                Log::error("Erro ao processar arquivo de fatura: " . $e->getMessage(), [
                    'user_id' => $user->id,
                    'file' => $originalName,
                ]);
            }
        }

        if (empty($imported)) {
            return response()->json([
                'message' => 'Nenhum arquivo pode ser processado.',
                'imported' => [],
                'failed' => $failed,
            ], 500);
        }

        $total = count($imported);
        $message = $total === 1
            ? 'Arquivo enviado para processamento'
            : "{$total} arquivos enviados para processamento";

        if (!empty($failed)) {
            $message .= '. ' . count($failed) . ' falhou(ram).';
        }

        return response()->json([
            'message' => $message,
            'status' => 'processing',
            'imported' => $imported,
            'failed' => $failed,
        ], 202);
    }

    /**
     * Preview das palavras-chave que seriam extraídas da descrição
     */
    public function previewKeywords(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'description' => 'required|string|max:500',
        ]);

        $keywordExtractor = app(KeywordExtractorService::class);
        $keywords = $keywordExtractor->extractSignificantWords($validated['description']);

        return response()->json(['keywords' => $keywords]);
    }

    /**
     * Lista transações pendentes de categorização
     */
    public function getPending(Request $request): JsonResponse
    {
        $query = Transaction::where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->with('category')
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc');

        $perPage = $request->get('per_page', 15);
        $transactions = $query->paginate($perPage);

        return response()->json($transactions);
    }

    /**
     * Categoriza uma transação pendente
     */
    public function categorize(Request $request, Transaction $transaction): JsonResponse
    {
        // Valida que a transação pertence ao usuário e está pendente
        if ($transaction->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($transaction->status !== 'pending') {
            return response()->json([
                'message' => 'Esta transação já foi categorizada',
            ], 400);
        }

        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'keywords' => 'sometimes|array',
            'keywords.*' => 'string|max:100',
        ]);

        // Valida que a categoria pertence ao usuário
        $category = \App\Models\Category::findOrFail($validated['category_id']);
        if ($category->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Atualiza transação
        $transaction->update([
            'category_id' => $validated['category_id'],
            'status' => 'categorized',
        ]);

        // Extrai ou usa palavras-chave e adiciona às keywords da categoria
        // Apenas para categorias de despesa
        if ($category->type === 'expense' && !empty($transaction->description)) {
            try {
                $significantWords = isset($validated['keywords']) && !empty($validated['keywords'])
                    ? array_values(array_filter(array_map('trim', $validated['keywords'])))
                    : app(KeywordExtractorService::class)->extractSignificantWords($transaction->description);

                if (!empty($significantWords)) {
                    $category->addKeywords($significantWords);

                    Log::info("Keywords adicionadas automaticamente à categoria", [
                        'category_id' => $category->id,
                        'category_name' => $category->name,
                        'keywords_added' => $significantWords,
                        'transaction_description' => $transaction->description,
                    ]);
                }
            } catch (\Exception $e) {
                // Log do erro mas não interrompe o fluxo de categorização
                Log::error("Erro ao adicionar keywords automaticamente: " . $e->getMessage(), [
                    'category_id' => $category->id,
                    'transaction_id' => $transaction->id,
                ]);
            }
        }

        // Categoriza automaticamente outras transações pendentes
        $autoCategorizedIds = [];

        try {
            // 1. Categoriza transações com a mesma descrição exata
            $sameDescriptionTransactions = Transaction::where('user_id', $request->user()->id)
                ->where('status', 'pending')
                ->where('description', $transaction->description)
                ->where('id', '!=', $transaction->id)
                ->get();

            if ($sameDescriptionTransactions->count() > 0) {
                $sameDescriptionTransactions->each(function ($sameTransaction) use ($category) {
                    $sameTransaction->update([
                        'category_id' => $category->id,
                        'status' => 'categorized',
                    ]);
                });

                $autoCategorizedIds = $sameDescriptionTransactions->pluck('id')->toArray();

                Log::info("Transações com mesma descrição categorizadas automaticamente", [
                    'original_transaction_id' => $transaction->id,
                    'category_id' => $category->id,
                    'description' => $transaction->description,
                    'transactions_categorized' => $autoCategorizedIds,
                    'count' => $sameDescriptionTransactions->count(),
                ]);
            }

            // 2. Categoriza transações pendentes que se encaixam nas keywords da categoria
            // Recarrega a categoria para ter as keywords atualizadas (caso tenham sido adicionadas acima)
            $category->refresh();

            // Verifica se a categoria tem keywords antes de fazer o matching
            if ($category->keywords && is_array($category->keywords) && count($category->keywords) > 0) {
                $matcherService = app(CategoryMatcherService::class);

                // Busca todas as transações pendentes do usuário (exceto a original e as já categorizadas por nome)
                $pendingTransactions = Transaction::where('user_id', $request->user()->id)
                    ->where('status', 'pending')
                    ->where('id', '!=', $transaction->id)
                    ->whereNotIn('id', $autoCategorizedIds) // Evita duplicatas
                    ->get();

                $keywordMatchedIds = [];

                foreach ($pendingTransactions as $pendingTransaction) {
                    // Verifica se a descrição da transação pendente se encaixa nas keywords da categoria específica
                    if ($matcherService->matchesCategory($pendingTransaction->description, $category)) {
                        $pendingTransaction->update([
                            'category_id' => $category->id,
                            'status' => 'categorized',
                        ]);

                        $keywordMatchedIds[] = $pendingTransaction->id;
                    }
                }

                if (count($keywordMatchedIds) > 0) {
                    // Adiciona os IDs categorizados por keywords ao array principal
                    $autoCategorizedIds = array_merge($autoCategorizedIds, $keywordMatchedIds);

                    Log::info("Transações categorizadas automaticamente por keywords", [
                        'original_transaction_id' => $transaction->id,
                        'category_id' => $category->id,
                        'category_name' => $category->name,
                        'keywords' => $category->keywords,
                        'transactions_categorized_by_keywords' => $keywordMatchedIds,
                        'count' => count($keywordMatchedIds),
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Log do erro mas não interrompe o fluxo de categorização
            Log::error("Erro ao categorizar transações automaticamente: " . $e->getMessage(), [
                'transaction_id' => $transaction->id,
                'category_id' => $category->id,
                'trace' => $e->getTraceAsString(),
            ]);
        }

        $transaction->load('category');

        return response()->json([
            'transaction' => $transaction,
            'auto_categorized_ids' => $autoCategorizedIds,
        ]);
    }
}
