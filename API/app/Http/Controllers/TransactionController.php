<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Função auxiliar para aplicar filtros (exceto tipo, que é aplicado separadamente)
        $applyFilters = function ($query, $excludeType = false) use ($request) {
            if (!$excludeType && $request->has('type')) {
                $query->where('type', $request->type);
            }

            if ($request->has('category_id')) {
                $query->where('category_id', $request->category_id);
            }

            if ($request->has('start_date') && $request->has('end_date')) {
                $query->whereBetween('date', [$request->start_date, $request->end_date]);
            } elseif ($request->has('start_date')) {
                $query->where('date', '>=', $request->start_date);
            } elseif ($request->has('end_date')) {
                $query->where('date', '<=', $request->end_date);
            }

            if ($request->has('search')) {
                $query->whereRaw('LOWER(description) LIKE ?', ['%' . strtolower($request->search) . '%']);
            }

            if ($request->has('bank_name')) {
                $query->where('bank_name', $request->bank_name);
            }

            return $query;
        };

        // Query para paginação
        $query = Transaction::where('user_id', $request->user()->id)
            ->with('category')
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        $applyFilters($query);

        // Calcula totais de todas as transações que correspondem aos filtros
        // Receitas vinculadas a banco representam estornos/ajustes de fatura,
        // então não entram como receita e passam a abater o total de despesas.
        $totalIncome = 0;
        $totalExpense = 0;
        $totalIncomeCount = 0;
        $totalExpenseCount = 0;
        
        // Se há filtro de tipo, calcula apenas o tipo filtrado
        if ($request->has('type')) {
            if ($request->type === 'expense') {
                $expenseQuery = Transaction::where('user_id', $request->user()->id)
                    ->where('type', 'expense')
                    ->whereNotNull('amount');
                $applyFilters($expenseQuery, false); // Aplica todos os filtros incluindo tipo
                $totalExpense = (float) ($expenseQuery->sum('amount') ?? 0);
                $totalExpenseCount = $expenseQuery->count();
            } elseif ($request->type === 'income') {
                $incomeQuery = Transaction::where('user_id', $request->user()->id)
                    ->where('type', 'income')
                    ->whereNull('bank_name')
                    ->whereNotNull('amount');
                $applyFilters($incomeQuery, false); // Aplica todos os filtros incluindo tipo
                $totalIncome = (float) ($incomeQuery->sum('amount') ?? 0);
                $totalIncomeCount = $incomeQuery->count();
            }
        } else {
            // Sem filtro de tipo, calcula ambos separadamente
            $incomeQuery = Transaction::where('user_id', $request->user()->id)
                ->where('type', 'income')
                ->whereNull('bank_name')
                ->whereNotNull('amount');
            $applyFilters($incomeQuery, true); // true = exclui filtro de tipo
            $totalIncome = (float) ($incomeQuery->sum('amount') ?? 0);
            $totalIncomeCount = $incomeQuery->count();
            
            $expenseQuery = Transaction::where('user_id', $request->user()->id)
                ->where('type', 'expense')
                ->whereNotNull('amount');
            $applyFilters($expenseQuery, true); // true = exclui filtro de tipo
            $bankAdjustmentQuery = Transaction::where('user_id', $request->user()->id)
                ->where('type', 'income')
                ->whereNotNull('bank_name')
                ->whereNotNull('amount');
            $applyFilters($bankAdjustmentQuery, true); // Aplica os mesmos filtros sem tratar como receita

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
        ]);

        $category = \App\Models\Category::findOrFail($validated['category_id']);
        
        if ($category->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $transaction = Transaction::create([
            ...$validated,
            'user_id' => $request->user()->id,
        ]);

        $transaction->load('category');

        return response()->json($transaction, 201);
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
        $deletedCount = Transaction::where('user_id', $request->user()->id)->delete();

        return response()->json([
            'message' => 'Todas as transações foram deletadas com sucesso',
            'deleted_count' => $deletedCount,
        ]);
    }
}

