<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\InvoiceParserService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TransactionController extends Controller
{
    private function getFilterValue(Request $request, string $key): mixed
    {
        $queryValue = $request->query($key);
        if ($queryValue !== null && $queryValue !== '') {
            return $queryValue;
        }

        $inputValue = $request->input($key);
        if ($inputValue !== null && $inputValue !== '') {
            return $inputValue;
        }

        return null;
    }

    private function applyTransactionFilters($query, Request $request, bool $excludeType = false)
    {
        $type = $this->getFilterValue($request, 'type');
        $categoryId = $this->getFilterValue($request, 'category_id');
        $startDate = $this->getFilterValue($request, 'start_date');
        $endDate = $this->getFilterValue($request, 'end_date');
        $search = $this->getFilterValue($request, 'search');
        $bankName = $this->getFilterValue($request, 'bank_name');
        $isInstallment = $this->getFilterValue($request, 'is_installment');

        if (!$excludeType && $type !== null) {
            $query->where('type', $type);
        }

        if ($categoryId !== null) {
            $query->where('category_id', $categoryId);
        }

        if ($startDate !== null && $endDate !== null) {
            $query->whereBetween('date', [$startDate, $endDate]);
        } elseif ($startDate !== null) {
            $query->where('date', '>=', $startDate);
        } elseif ($endDate !== null) {
            $query->where('date', '<=', $endDate);
        }

        if ($search !== null) {
            $query->whereRaw('LOWER(description) LIKE ?', ['%' . strtolower($search) . '%']);
        }

        if ($bankName !== null) {
            $query->where('bank_name', $bankName);
        }

        if ($isInstallment !== null) {
            $isInstallmentBool = filter_var($isInstallment, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isInstallmentBool !== null) {
                $query->where('is_installment', $isInstallmentBool);
            }
        }

        return $query;
    }

    public function index(Request $request): JsonResponse
    {
        // Query para paginação
        $query = Transaction::where('user_id', $request->user()->id)
            ->with('category')
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        $this->applyTransactionFilters($query, $request);

        // Calcula totais de todas as transações que correspondem aos filtros
        // Receitas vinculadas a banco representam estornos/ajustes de fatura,
        // então não entram como receita e passam a abater o total de despesas.
        $totalIncome = 0;
        $totalExpense = 0;
        $totalIncomeCount = 0;
        $totalExpenseCount = 0;
        
        // Se há filtro de tipo, calcula apenas o tipo filtrado
        $type = $this->getFilterValue($request, 'type');

        if ($type !== null) {
            if ($type === 'expense') {
                $expenseQuery = Transaction::where('user_id', $request->user()->id)
                    ->where('type', 'expense')
                    ->whereNotNull('amount');
                $this->applyTransactionFilters($expenseQuery, $request, false); // Aplica todos os filtros incluindo tipo
                $totalExpense = (float) ($expenseQuery->sum('amount') ?? 0);
                $totalExpenseCount = $expenseQuery->count();
            } elseif ($type === 'income') {
                $incomeQuery = Transaction::where('user_id', $request->user()->id)
                    ->where('type', 'income')
                    ->whereNull('bank_name')
                    ->whereNotNull('amount');
                $this->applyTransactionFilters($incomeQuery, $request, false); // Aplica todos os filtros incluindo tipo
                $totalIncome = (float) ($incomeQuery->sum('amount') ?? 0);
                $totalIncomeCount = $incomeQuery->count();
            }
        } else {
            // Sem filtro de tipo, calcula ambos separadamente
            $incomeQuery = Transaction::where('user_id', $request->user()->id)
                ->where('type', 'income')
                ->whereNull('bank_name')
                ->whereNotNull('amount');
            $this->applyTransactionFilters($incomeQuery, $request, true); // true = exclui filtro de tipo
            $totalIncome = (float) ($incomeQuery->sum('amount') ?? 0);
            $totalIncomeCount = $incomeQuery->count();
            
            $expenseQuery = Transaction::where('user_id', $request->user()->id)
                ->where('type', 'expense')
                ->whereNotNull('amount');
            $this->applyTransactionFilters($expenseQuery, $request, true); // true = exclui filtro de tipo
            $bankAdjustmentQuery = Transaction::where('user_id', $request->user()->id)
                ->where('type', 'income')
                ->whereNotNull('bank_name')
                ->whereNotNull('amount');
            $this->applyTransactionFilters($bankAdjustmentQuery, $request, true); // Aplica os mesmos filtros sem tratar como receita

            $totalExpense = (float) ($expenseQuery->sum('amount') ?? 0)
                - (float) ($bankAdjustmentQuery->sum('amount') ?? 0);
            $totalExpenseCount = $expenseQuery->count();
        }

        $perPage = $request->get('per_page', 15);
        $transactions = $query->paginate($perPage);

        // Adiciona os totais à resposta
        $response = $transactions->toArray();
        $response['totals'] = [
            'total_income' => (float) $totalIncome,
            'total_expense' => (float) $totalExpense,
            'income_count' => $totalIncomeCount,
            'expense_count' => $totalExpenseCount,
        ];

        return response()->json($response);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'type' => 'required|in:income,expense',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:255',
            'date' => 'required|date',
            'bank_name' => 'nullable|string|max:255',
            'is_installment' => 'sometimes|boolean',
            'current_installment' => 'nullable|integer|min:1',
            'total_installments' => 'nullable|integer|min:1',
        ]);

        $category = \App\Models\Category::findOrFail($validated['category_id']);
        
        if ($category->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $isInstallment = (bool) ($validated['is_installment'] ?? false);
        $currentInstallment = $validated['current_installment'] ?? null;
        $totalInstallments = $validated['total_installments'] ?? null;

        if ($isInstallment && ($currentInstallment === null || $totalInstallments === null)) {
            return response()->json([
                'message' => 'Campos de parcelamento inválidos.',
                'errors' => [
                    'current_installment' => ['Informe a parcela atual.'],
                    'total_installments' => ['Informe o total de parcelas.'],
                ],
            ], 422);
        }

        if ($isInstallment && $currentInstallment > $totalInstallments) {
            return response()->json([
                'message' => 'Campos de parcelamento inválidos.',
                'errors' => [
                    'current_installment' => ['A parcela atual deve ser menor ou igual ao total de parcelas.'],
                ],
            ], 422);
        }

        if (!$isInstallment) {
            $transaction = Transaction::create([
                ...$validated,
                'is_installment' => false,
                'user_id' => $request->user()->id,
            ]);

            $transaction->load('category');

            return response()->json($transaction, 201);
        }

        $parserService = app(InvoiceParserService::class);
        $normalizedDescription = $parserService->buildInstallmentDescription(
            $validated['description'],
            (int) $currentInstallment,
            (int) $totalInstallments
        );
        $baseDate = Carbon::parse($validated['date']);

        $firstInstallment = Transaction::create([
            'user_id' => $request->user()->id,
            'category_id' => $validated['category_id'],
            'type' => $validated['type'],
            'amount' => $validated['amount'],
            'description' => $normalizedDescription,
            'date' => $validated['date'],
            'bank_name' => $validated['bank_name'] ?? null,
            'is_installment' => true,
        ]);

        for ($installmentNumber = $currentInstallment + 1; $installmentNumber <= $totalInstallments; $installmentNumber++) {
            $monthsToAdd = $installmentNumber - $currentInstallment;
            $futureDate = $baseDate->copy()->addMonthsNoOverflow($monthsToAdd)->toDateString();
            $futureDescription = $parserService->buildInstallmentDescription(
                $validated['description'],
                $installmentNumber,
                (int) $totalInstallments
            );

            Transaction::create([
                'user_id' => $request->user()->id,
                'category_id' => $validated['category_id'],
                'type' => $validated['type'],
                'amount' => $validated['amount'],
                'description' => $futureDescription,
                'date' => $futureDate,
                'bank_name' => $validated['bank_name'] ?? null,
                'is_installment' => true,
            ]);
        }

        $firstInstallment->load('category');

        return response()->json([
            ...$firstInstallment->toArray(),
            'installments_created' => ((int) $totalInstallments - (int) $currentInstallment) + 1,
        ], 201);
    }

    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        if ($transaction->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $transaction->load('category');

        return response()->json($transaction);
    }

    public function update(Request $request, Transaction $transaction): JsonResponse
    {
        if ($transaction->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'category_id' => 'sometimes|exists:categories,id',
            'type' => 'sometimes|in:income,expense',
            'amount' => 'sometimes|numeric|min:0.01',
            'description' => 'sometimes|string|max:255',
            'date' => 'sometimes|date',
            'bank_name' => 'nullable|string|max:255',
        ]);

        if (isset($validated['category_id'])) {
            $category = \App\Models\Category::findOrFail($validated['category_id']);
            if ($category->user_id !== $request->user()->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        $transaction->update($validated);
        $transaction->load('category');

        return response()->json($transaction);
    }

    public function destroy(Request $request, Transaction $transaction): JsonResponse
    {
        if ($transaction->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $transaction->delete();

        return response()->json(['message' => 'Transaction deleted successfully']);
    }

    public function getBanks(Request $request): JsonResponse
    {
        $banks = Transaction::where('user_id', $request->user()->id)
            ->whereNotNull('bank_name')
            ->distinct()
            ->pluck('bank_name')
            ->sort()
            ->values();

        return response()->json($banks);
    }

    public function deleteAll(Request $request): JsonResponse
    {
        $query = Transaction::where('user_id', $request->user()->id);
        $this->applyTransactionFilters($query, $request);
        $deletedCount = $query->delete();

        return response()->json([
            'message' => 'Todas as transações foram deletadas com sucesso',
            'deleted_count' => $deletedCount,
        ]);
    }
}

