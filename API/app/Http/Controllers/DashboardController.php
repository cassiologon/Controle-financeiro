<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Budget;
use App\Models\RecurringTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', now()->endOfMonth()->toDateString());

        $transactions = Transaction::where('user_id', $userId)
            ->whereBetween('date', [$startDate, $endDate])
            ->with('category:id,name,icon,color')
            ->get();

        $recurringBills = RecurringTransaction::where('user_id', $userId)
            ->where('is_active', true)
            ->where('start_date', '<=', $endDate)
            ->where(function ($q) use ($startDate) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $startDate);
            })
            ->with('category:id,name,icon,color')
            ->get();

        $recurringIncome = $recurringBills->where('type', 'income')->sum('amount');
        $recurringExpense = $recurringBills->where('type', 'expense')->sum('amount');

        $isBankAdjustment = fn ($transaction) => $transaction->type === 'income' && !empty($transaction->bank_name);
        $effectiveType = fn ($transaction) => $isBankAdjustment($transaction) ? 'expense' : $transaction->type;
        $effectiveAmount = fn ($transaction) => $isBankAdjustment($transaction)
            ? -((float) $transaction->amount)
            : (float) $transaction->amount;

        $totalIncome = $transactions
            ->filter(fn ($transaction) => $transaction->type === 'income' && empty($transaction->bank_name))
            ->sum(fn ($transaction) => (float) $transaction->amount) + $recurringIncome;

        $totalExpense = $transactions
            ->filter(fn ($transaction) => $effectiveType($transaction) === 'expense')
            ->sum(fn ($transaction) => $effectiveAmount($transaction)) + $recurringExpense;

        $balance = $totalIncome - $totalExpense;

        $transactionsByCategory = [];

        foreach ($transactions as $transaction) {
            $resolvedType = $effectiveType($transaction);
            $key = ($transaction->category_id ?? 'null') . '-' . $resolvedType;

            if (!isset($transactionsByCategory[$key])) {
                $transactionsByCategory[$key] = [
                    'category_id' => $transaction->category_id,
                    'type' => $resolvedType,
                    'total' => 0.0,
                    'recurring_total' => 0.0,
                    'category' => $transaction->category ? [
                        'id' => $transaction->category->id,
                        'name' => $transaction->category->name,
                        'icon' => $transaction->category->icon,
                        'color' => $transaction->category->color,
                    ] : null,
                ];
            }

            $transactionsByCategory[$key]['total'] += $effectiveAmount($transaction);
        }

        foreach ($recurringBills as $bill) {
            $key = $bill->category_id . '-' . $bill->type;
            if (isset($transactionsByCategory[$key])) {
                $transactionsByCategory[$key]['total'] = (float) $transactionsByCategory[$key]['total'] + (float) $bill->amount;
                $transactionsByCategory[$key]['recurring_total'] = (float) ($transactionsByCategory[$key]['recurring_total'] ?? 0) + (float) $bill->amount;
            } else {
                $transactionsByCategory[$key] = [
                    'category_id' => $bill->category_id,
                    'type' => $bill->type,
                    'total' => (float) $bill->amount,
                    'recurring_total' => (float) $bill->amount,
                    'category' => $bill->category ? [
                        'id' => $bill->category->id,
                        'name' => $bill->category->name,
                        'icon' => $bill->category->icon,
                        'color' => $bill->category->color,
                    ] : null,
                ];
            }
        }

        $transactionsByCategory = array_values($transactionsByCategory);

        $dailyExpenses = $transactions
            ->filter(fn ($transaction) => $effectiveType($transaction) === 'expense')
            ->groupBy(function ($transaction) {
                $date = $transaction->date;
                return $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date;
            })
            ->map(function ($dayTransactions, $date) use ($effectiveAmount) {
                return ['date' => $date, 'total' => (float) $dayTransactions->sum(fn ($transaction) => $effectiveAmount($transaction))];
            })
            ->sortBy('date')
            ->values()
            ->toArray();

        $monthlyTransactions = Transaction::where('user_id', $userId)
            ->whereBetween('date', [now()->subMonths(11)->startOfMonth()->toDateString(), $endDate])
            ->get(['date', 'type', 'amount', 'bank_name']);

        $monthlyData = $monthlyTransactions
            ->groupBy(fn ($t) => \Carbon\Carbon::parse($t->date)->format('Y-m'))
            ->flatMap(fn ($monthGroup, $month) => $monthGroup->groupBy(
                fn ($transaction) => $transaction->type === 'income' && !empty($transaction->bank_name) ? 'expense' : $transaction->type
            )->map(
                fn ($typeGroup, $type) => [
                    'month' => $month,
                    'type' => $type,
                    'total' => (float) $typeGroup->sum(
                        fn ($transaction) => $transaction->type === 'income' && !empty($transaction->bank_name)
                            ? -((float) $transaction->amount)
                            : (float) $transaction->amount
                    ),
                ]
            )->values())
            ->sortBy('month')
            ->values();

        $budgets = Budget::where('user_id', $userId)
            ->where('month', now()->month)
            ->where('year', now()->year)
            ->with('category:id,name,icon,color')
            ->get()
            ->map(function ($budget) use ($transactions, $effectiveType, $effectiveAmount) {
                $spent = $transactions
                    ->filter(fn ($transaction) => $transaction->category_id === $budget->category_id)
                    ->filter(fn ($transaction) => $effectiveType($transaction) === 'expense')
                    ->sum(fn ($transaction) => $effectiveAmount($transaction));

                return [
                    'id' => $budget->id,
                    'category' => $budget->category,
                    'budget_amount' => (float) $budget->amount,
                    'spent_amount' => (float) $spent,
                    'remaining' => (float) $budget->amount - (float) $spent,
                    'percentage' => $budget->amount > 0 ? ((float) $spent / (float) $budget->amount) * 100 : 0,
                ];
            });

        $recentTransactions = Transaction::where('user_id', $userId)
            ->with('category:id,name,icon,color')
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'summary' => [
                'total_income' => $totalIncome,
                'total_expense' => $totalExpense,
                'balance' => $balance,
            ],
            'transactions_by_category' => $transactionsByCategory,
            'daily_expenses' => $dailyExpenses,
            'monthly_data' => $monthlyData,
            'budgets' => $budgets,
            'recent_transactions' => $recentTransactions,
        ]);
    }
}

