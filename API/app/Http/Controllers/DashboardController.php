<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Budget;
use App\Models\RecurringTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', now()->endOfMonth()->toDateString());

        $transactions = Transaction::where('user_id', $userId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        $recurringBills = RecurringTransaction::where('user_id', $userId)
            ->where('is_active', true)
            ->where('start_date', '<=', $endDate)
            ->where(function ($q) use ($startDate) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $startDate);
            })
            ->get();

        $recurringIncome = $recurringBills->where('type', 'income')->sum('amount');
        $recurringExpense = $recurringBills->where('type', 'expense')->sum('amount');

        $totalIncome = $transactions->where('type', 'income')->sum('amount') + $recurringIncome;
        $totalExpense = $transactions->where('type', 'expense')->sum('amount') + $recurringExpense;
        $balance = $totalIncome - $totalExpense;

        $transactionsByCategory = Transaction::where('user_id', $userId)
            ->whereBetween('date', [$startDate, $endDate])
            ->select('category_id', 'type', DB::raw('SUM(amount) as total'))
            ->groupBy('category_id', 'type')
            ->get()
            ->map(function ($item) {
                $category = \App\Models\Category::find($item->category_id);
                return [
                    'category_id' => $item->category_id,
                    'type' => $item->type,
                    'total' => (float) $item->total,
                    'recurring_total' => 0.0,
                    'category' => $category ? [
                        'id' => $category->id,
                        'name' => $category->name,
                        'icon' => $category->icon,
                        'color' => $category->color,
                    ] : null,
                ];
            })
            ->keyBy(fn ($item) => $item['category_id'] . '-' . $item['type'])
            ->toArray();

        foreach ($recurringBills as $bill) {
            $key = $bill->category_id . '-' . $bill->type;
            if (isset($transactionsByCategory[$key])) {
                $transactionsByCategory[$key]['total'] = (float) $transactionsByCategory[$key]['total'] + (float) $bill->amount;
                $transactionsByCategory[$key]['recurring_total'] = (float) ($transactionsByCategory[$key]['recurring_total'] ?? 0) + (float) $bill->amount;
            } else {
                $category = \App\Models\Category::find($bill->category_id);
                $transactionsByCategory[$key] = [
                    'category_id' => $bill->category_id,
                    'type' => $bill->type,
                    'total' => (float) $bill->amount,
                    'recurring_total' => (float) $bill->amount,
                    'category' => $category ? [
                        'id' => $category->id,
                        'name' => $category->name,
                        'icon' => $category->icon,
                        'color' => $category->color,
                    ] : null,
                ];
            }
        }

        $transactionsByCategory = array_values($transactionsByCategory);

        $dailyExpenses = Transaction::where('user_id', $userId)
            ->whereBetween('date', [$startDate, $endDate])
            ->where('type', 'expense')
            ->select('date', DB::raw('SUM(amount) as total'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($row) {
                $date = $row->date;
                $dateStr = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date;
                return ['date' => $dateStr, 'total' => (float) $row->total];
            })
            ->values()
            ->toArray();

        $monthlyData = Transaction::where('user_id', $userId)
            ->whereBetween('date', [now()->subMonths(11)->startOfMonth()->toDateString(), $endDate])
            ->select(
                DB::raw("TO_CHAR(date, 'YYYY-MM') as month"),
                'type',
                DB::raw('SUM(amount) as total')
            )
            ->groupBy(DB::raw("TO_CHAR(date, 'YYYY-MM')"), 'type')
            ->orderBy(DB::raw("TO_CHAR(date, 'YYYY-MM')"))
            ->get();

        $budgets = Budget::where('user_id', $userId)
            ->where('month', now()->month)
            ->where('year', now()->year)
            ->with('category:id,name,icon,color')
            ->get()
            ->map(function ($budget) use ($userId, $startDate, $endDate) {
                $spent = Transaction::where('user_id', $userId)
                    ->where('category_id', $budget->category_id)
                    ->where('type', 'expense')
                    ->whereBetween('date', [$startDate, $endDate])
                    ->sum('amount');
                
                return [
                    'id' => $budget->id,
                    'category' => $budget->category,
                    'budget_amount' => $budget->amount,
                    'spent_amount' => $spent,
                    'remaining' => $budget->amount - $spent,
                    'percentage' => $budget->amount > 0 ? ($spent / $budget->amount) * 100 : 0,
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

