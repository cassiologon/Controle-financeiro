<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\FinancialInsight;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Services\FinancialInsightsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class InsightController extends Controller
{
    public function __construct(
        private FinancialInsightsService $insightsService
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $userId = $request->user()->id;
        $startDate = $validated['start_date'];
        $endDate = $validated['end_date'];

        $stored = FinancialInsight::where('user_id', $userId)
            ->where('start_date', $startDate)
            ->where('end_date', $endDate)
            ->first();

        if (!$stored) {
            return response()->json(['insights' => null]);
        }

        $context = $this->buildContext($userId, $startDate, $endDate);
        $dataHash = $this->buildDataHash($context);

        return response()->json($this->formatStored($stored, $dataHash !== $stored->data_hash));
    }

    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'force' => 'sometimes|boolean',
        ]);

        $userId = $request->user()->id;
        $startDate = $validated['start_date'];
        $endDate = $validated['end_date'];
        $force = (bool) ($validated['force'] ?? false);

        $context = $this->buildContext($userId, $startDate, $endDate);
        $dataHash = $this->buildDataHash($context);

        $stored = FinancialInsight::where('user_id', $userId)
            ->where('start_date', $startDate)
            ->where('end_date', $endDate)
            ->first();

        if (!$force && $stored && $stored->data_hash === $dataHash) {
            info('Insights financeiros retornados do banco', [
                'user_id' => $userId,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

            return response()->json(array_merge(
                $this->formatStored($stored, false),
                ['from_cache' => true]
            ));
        }

        try {
            $result = $this->insightsService->generate($context);

            FinancialInsight::updateOrCreate(
                [
                    'user_id' => $userId,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
                [
                    'data_hash' => $dataHash,
                    'summary' => $result['summary'],
                    'total_potential_savings' => $result['total_potential_savings'],
                    'insights' => $result['insights'],
                    'generated_at' => now(),
                ]
            );

            info('Insights financeiros salvos no banco', [
                'user_id' => $userId,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

            return response()->json(array_merge($result, [
                'cached' => false,
                'is_stale' => false,
                'from_cache' => false,
            ]));
        } catch (RuntimeException $e) {
            info('Falha ao gerar insights financeiros', [
                'user_id' => $userId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 503);
        }
    }

    private function formatStored(FinancialInsight $stored, bool $isStale): array
    {
        return [
            'summary' => $stored->summary,
            'total_potential_savings' => (float) $stored->total_potential_savings,
            'insights' => $stored->insights,
            'generated_at' => $stored->generated_at->toIso8601String(),
            'cached' => true,
            'is_stale' => $isStale,
        ];
    }

    private function buildDataHash(array $context): string
    {
        return hash('sha256', json_encode($context));
    }

    private function buildContext(int $userId, string $startDate, string $endDate): array
    {
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

        $recurringIncome = (float) $recurringBills->where('type', 'income')->sum('amount');
        $recurringExpense = (float) $recurringBills->where('type', 'expense')->sum('amount');

        $isBankAdjustment = fn ($transaction) => $transaction->type === 'income' && !empty($transaction->bank_name);
        $effectiveType = fn ($transaction) => $isBankAdjustment($transaction) ? 'expense' : $transaction->type;
        $effectiveAmount = fn ($transaction) => $isBankAdjustment($transaction)
            ? -((float) $transaction->amount)
            : (float) $transaction->amount;

        $totalIncome = (float) $transactions
            ->filter(fn ($transaction) => $transaction->type === 'income' && empty($transaction->bank_name))
            ->sum(fn ($transaction) => (float) $transaction->amount) + $recurringIncome;

        $totalExpense = (float) $transactions
            ->filter(fn ($transaction) => $effectiveType($transaction) === 'expense')
            ->sum(fn ($transaction) => $effectiveAmount($transaction)) + $recurringExpense;

        $balance = $totalIncome - $totalExpense;

        $expensesByCategoryMap = [];

        foreach ($transactions as $transaction) {
            if ($effectiveType($transaction) !== 'expense') {
                continue;
            }

            $categoryId = $transaction->category_id ?? 'null';
            if (!isset($expensesByCategoryMap[$categoryId])) {
                $expensesByCategoryMap[$categoryId] = [
                    'category_name' => $transaction->category?->name ?? 'Sem categoria',
                    'total' => 0.0,
                    'recurring_total' => 0.0,
                ];
            }

            $expensesByCategoryMap[$categoryId]['total'] += $effectiveAmount($transaction);
        }

        foreach ($recurringBills->where('type', 'expense') as $bill) {
            $categoryId = $bill->category_id ?? 'null';
            if (!isset($expensesByCategoryMap[$categoryId])) {
                $expensesByCategoryMap[$categoryId] = [
                    'category_name' => $bill->category?->name ?? 'Sem categoria',
                    'total' => 0.0,
                    'recurring_total' => 0.0,
                ];
            }

            $expensesByCategoryMap[$categoryId]['total'] += (float) $bill->amount;
            $expensesByCategoryMap[$categoryId]['recurring_total'] += (float) $bill->amount;
        }

        $expensesByCategory = collect($expensesByCategoryMap)
            ->map(function ($item) use ($totalExpense) {
                $item['total'] = round((float) $item['total'], 2);
                $item['recurring_total'] = round((float) $item['recurring_total'], 2);
                $item['percentage'] = $totalExpense > 0
                    ? round(($item['total'] / $totalExpense) * 100, 1)
                    : 0;

                return $item;
            })
            ->sortByDesc('total')
            ->values()
            ->all();

        $dailyExpenses = $transactions
            ->filter(fn ($transaction) => $effectiveType($transaction) === 'expense')
            ->filter(fn ($transaction) => !$transaction->is_installment)
            ->groupBy(function ($transaction) {
                $date = $transaction->date;
                return $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date;
            })
            ->map(fn ($dayTransactions) => (float) $dayTransactions->sum(fn ($transaction) => $effectiveAmount($transaction)));

        $periodDays = max(1, Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1);
        $totalDailyExpense = (float) $dailyExpenses->sum();
        $averageDailyExpense = $totalDailyExpense / $periodDays;

        $maxDayTotal = 0.0;
        $maxDayDate = null;
        foreach ($dailyExpenses as $date => $total) {
            if ($total > $maxDayTotal) {
                $maxDayTotal = $total;
                $maxDayDate = $date;
            }
        }

        $recurringExpenses = $recurringBills
            ->where('type', 'expense')
            ->map(fn ($bill) => [
                'description' => $bill->description,
                'category_name' => $bill->category?->name ?? 'Sem categoria',
                'amount' => (float) $bill->amount,
            ])
            ->values()
            ->all();

        $topExpenses = $transactions
            ->filter(fn ($transaction) => $effectiveType($transaction) === 'expense')
            ->sortByDesc(fn ($transaction) => $effectiveAmount($transaction))
            ->take(10)
            ->map(function ($transaction) use ($effectiveAmount) {
                $date = $transaction->date;
                return [
                    'description' => $transaction->description,
                    'category_name' => $transaction->category?->name ?? 'Sem categoria',
                    'date' => $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date,
                    'amount' => round($effectiveAmount($transaction), 2),
                ];
            })
            ->values()
            ->all();

        $budgets = Budget::where('user_id', $userId)
            ->where('month', now()->month)
            ->where('year', now()->year)
            ->with('category:id,name,icon,color')
            ->get()
            ->map(function ($budget) use ($transactions, $effectiveType, $effectiveAmount) {
                $spent = (float) $transactions
                    ->filter(fn ($transaction) => $transaction->category_id === $budget->category_id)
                    ->filter(fn ($transaction) => $effectiveType($transaction) === 'expense')
                    ->sum(fn ($transaction) => $effectiveAmount($transaction));

                $budgetAmount = (float) $budget->amount;

                return [
                    'category_name' => $budget->category?->name ?? 'Sem categoria',
                    'budget_amount' => $budgetAmount,
                    'spent_amount' => $spent,
                    'percentage' => $budgetAmount > 0 ? round(($spent / $budgetAmount) * 100, 1) : 0,
                ];
            })
            ->values()
            ->all();

        $categoryBreakdowns = $this->buildCategoryBreakdowns(
            $transactions,
            $recurringBills,
            $effectiveType,
            $effectiveAmount
        );

        $periodComparison = $this->buildPeriodComparison(
            $userId,
            $startDate,
            $endDate,
            $periodDays,
            $effectiveType,
            $effectiveAmount
        );

        $subscriptionsDetail = $this->buildSubscriptionsDetail(
            $transactions,
            $recurringBills,
            $effectiveType,
            $effectiveAmount
        );

        return [
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days' => $periodDays,
            ],
            'summary' => [
                'total_income' => round($totalIncome, 2),
                'total_expense' => round($totalExpense, 2),
                'balance' => round($balance, 2),
                'average_daily_expense' => round($averageDailyExpense, 2),
            ],
            'expenses_by_category' => $expensesByCategory,
            'category_breakdowns' => $categoryBreakdowns,
            'period_comparison' => $periodComparison,
            'subscriptions_detail' => $subscriptionsDetail,
            'recurring_expenses' => $recurringExpenses,
            'daily_stats' => [
                'days_with_expenses' => $dailyExpenses->count(),
                'max_day_total' => round($maxDayTotal, 2),
                'max_day_date' => $maxDayDate,
            ],
            'budgets' => $budgets,
            'top_expenses' => $topExpenses,
        ];
    }

    private function buildCategoryBreakdowns(
        $transactions,
        $recurringBills,
        callable $effectiveType,
        callable $effectiveAmount,
        int $limit = 6
    ): array {
        $categoryTotals = [];
        $categoryItems = [];

        foreach ($transactions as $transaction) {
            if ($effectiveType($transaction) !== 'expense') {
                continue;
            }

            $categoryName = $transaction->category?->name ?? 'Sem categoria';
            $description = $this->normalizeDescription($transaction->description);
            $amount = $effectiveAmount($transaction);

            $categoryTotals[$categoryName] = ($categoryTotals[$categoryName] ?? 0) + $amount;

            if (!isset($categoryItems[$categoryName][$description])) {
                $categoryItems[$categoryName][$description] = [
                    'description' => $description,
                    'amount' => 0.0,
                    'transaction_count' => 0,
                    'is_recurring' => false,
                ];
            }

            $categoryItems[$categoryName][$description]['amount'] += $amount;
            $categoryItems[$categoryName][$description]['transaction_count']++;
        }

        foreach ($recurringBills->where('type', 'expense') as $bill) {
            $categoryName = $bill->category?->name ?? 'Sem categoria';
            $description = $this->normalizeDescription($bill->description);
            $amount = (float) $bill->amount;

            $categoryTotals[$categoryName] = ($categoryTotals[$categoryName] ?? 0) + $amount;

            if (!isset($categoryItems[$categoryName][$description])) {
                $categoryItems[$categoryName][$description] = [
                    'description' => $description,
                    'amount' => 0.0,
                    'transaction_count' => 0,
                    'is_recurring' => true,
                ];
            }

            $categoryItems[$categoryName][$description]['amount'] += $amount;
            $categoryItems[$categoryName][$description]['is_recurring'] = true;
        }

        arsort($categoryTotals);
        $topCategories = array_slice(array_keys($categoryTotals), 0, $limit);

        $breakdowns = [];
        foreach ($topCategories as $categoryName) {
            $total = round((float) $categoryTotals[$categoryName], 2);
            $items = collect($categoryItems[$categoryName] ?? [])
                ->map(function ($item) use ($total) {
                    $item['amount'] = round((float) $item['amount'], 2);
                    $item['percentage_of_category'] = $total > 0
                        ? round(($item['amount'] / $total) * 100, 1)
                        : 0;

                    return $item;
                })
                ->sortByDesc('amount')
                ->values()
                ->take(8)
                ->all();

            $breakdowns[] = [
                'category_name' => $categoryName,
                'total' => $total,
                'items' => $items,
            ];
        }

        return $breakdowns;
    }

    private function buildPeriodComparison(
        int $userId,
        string $startDate,
        string $endDate,
        int $periodDays,
        callable $effectiveType,
        callable $effectiveAmount
    ): array {
        $prevEnd = Carbon::parse($startDate)->subDay();
        $prevStart = $prevEnd->copy()->subDays($periodDays - 1);

        $currentTransactions = Transaction::where('user_id', $userId)
            ->whereBetween('date', [$startDate, $endDate])
            ->with('category:id,name')
            ->get();

        $previousTransactions = Transaction::where('user_id', $userId)
            ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])
            ->with('category:id,name')
            ->get();

        $sumExpenses = function ($collection) use ($effectiveType, $effectiveAmount) {
            return (float) $collection
                ->filter(fn ($t) => $effectiveType($t) === 'expense')
                ->sum(fn ($t) => $effectiveAmount($t));
        };

        $currentTotal = $sumExpenses($currentTransactions);
        $previousTotal = $sumExpenses($previousTransactions);

        $groupByCategory = function ($collection) use ($effectiveType, $effectiveAmount) {
            $map = [];
            foreach ($collection as $transaction) {
                if ($effectiveType($transaction) !== 'expense') {
                    continue;
                }
                $name = $transaction->category?->name ?? 'Sem categoria';
                $map[$name] = ($map[$name] ?? 0) + $effectiveAmount($transaction);
            }

            return $map;
        };

        $groupByCategoryAndDescription = function ($collection) use ($effectiveType, $effectiveAmount) {
            $map = [];
            foreach ($collection as $transaction) {
                if ($effectiveType($transaction) !== 'expense') {
                    continue;
                }
                $categoryName = $transaction->category?->name ?? 'Sem categoria';
                $description = $this->normalizeDescription($transaction->description);
                $key = $categoryName . '::' . $description;
                $map[$key] = [
                    'category_name' => $categoryName,
                    'description' => $description,
                    'amount' => ($map[$key]['amount'] ?? 0) + $effectiveAmount($transaction),
                ];
            }

            return $map;
        };

        $currentByCategory = $groupByCategory($currentTransactions);
        $previousByCategory = $groupByCategory($previousTransactions);

        $categoryChanges = [];
        $allCategories = array_unique(array_merge(array_keys($currentByCategory), array_keys($previousByCategory)));

        foreach ($allCategories as $categoryName) {
            $current = round((float) ($currentByCategory[$categoryName] ?? 0), 2);
            $previous = round((float) ($previousByCategory[$categoryName] ?? 0), 2);

            if ($current <= 0 && $previous <= 0) {
                continue;
            }

            $changePercent = $previous > 0
                ? round((($current - $previous) / $previous) * 100, 1)
                : ($current > 0 ? 100.0 : 0.0);

            $categoryChanges[] = [
                'category_name' => $categoryName,
                'current' => $current,
                'previous' => $previous,
                'change_percent' => $changePercent,
            ];
        }

        usort($categoryChanges, fn ($a, $b) => abs($b['change_percent']) <=> abs($a['change_percent']));

        $currentItems = $groupByCategoryAndDescription($currentTransactions);
        $previousItems = $groupByCategoryAndDescription($previousTransactions);

        $notableItems = [];
        foreach ($currentItems as $key => $currentItem) {
            $current = round((float) $currentItem['amount'], 2);
            $previous = round((float) ($previousItems[$key]['amount'] ?? 0), 2);

            if ($current < 50) {
                continue;
            }

            $changePercent = $previous > 0
                ? round((($current - $previous) / $previous) * 100, 1)
                : ($current > 0 ? 100.0 : 0.0);

            if (abs($changePercent) >= 15 || ($current >= 100 && $previous === 0.0)) {
                $notableItems[] = [
                    'category_name' => $currentItem['category_name'],
                    'description' => $currentItem['description'],
                    'current' => $current,
                    'previous' => $previous,
                    'change_percent' => $changePercent,
                ];
            }
        }

        usort($notableItems, fn ($a, $b) => abs($b['change_percent']) <=> abs($a['change_percent']));

        return [
            'previous_period' => [
                'start_date' => $prevStart->toDateString(),
                'end_date' => $prevEnd->toDateString(),
            ],
            'total_expense' => [
                'current' => round($currentTotal, 2),
                'previous' => round($previousTotal, 2),
                'change_percent' => $previousTotal > 0
                    ? round((($currentTotal - $previousTotal) / $previousTotal) * 100, 1)
                    : ($currentTotal > 0 ? 100.0 : 0.0),
            ],
            'categories' => array_slice($categoryChanges, 0, 8),
            'notable_items' => array_slice($notableItems, 0, 10),
        ];
    }

    private function buildSubscriptionsDetail(
        $transactions,
        $recurringBills,
        callable $effectiveType,
        callable $effectiveAmount
    ): array {
        $subscriptionCategoryPattern = '/assin|stream|servi[cç]o|digital|netflix|spotify/i';
        $items = [];

        foreach ($recurringBills->where('type', 'expense') as $bill) {
            $description = $this->normalizeDescription($bill->description);
            if (!isset($items[$description])) {
                $items[$description] = [
                    'description' => $description,
                    'amount' => 0.0,
                    'is_recurring' => true,
                    'category_name' => $bill->category?->name ?? 'Sem categoria',
                ];
            }
            $items[$description]['amount'] += (float) $bill->amount;
        }

        foreach ($transactions as $transaction) {
            if ($effectiveType($transaction) !== 'expense') {
                continue;
            }

            $categoryName = $transaction->category?->name ?? '';
            $description = $this->normalizeDescription($transaction->description);
            $isSubscriptionCategory = preg_match($subscriptionCategoryPattern, $categoryName) === 1;
            $isSubscriptionDescription = preg_match($subscriptionCategoryPattern, $description) === 1;

            if (!$isSubscriptionCategory && !$isSubscriptionDescription) {
                continue;
            }

            if (!isset($items[$description])) {
                $items[$description] = [
                    'description' => $description,
                    'amount' => 0.0,
                    'is_recurring' => false,
                    'category_name' => $categoryName ?: 'Sem categoria',
                ];
            }

            $items[$description]['amount'] += $effectiveAmount($transaction);
        }

        $sortedItems = collect($items)
            ->map(function ($item) {
                $item['amount'] = round((float) $item['amount'], 2);

                return $item;
            })
            ->sortByDesc('amount')
            ->values()
            ->all();

        $total = round(array_sum(array_column($sortedItems, 'amount')), 2);

        foreach ($sortedItems as &$item) {
            $item['percentage_of_subscriptions'] = $total > 0
                ? round(($item['amount'] / $total) * 100, 1)
                : 0;
        }
        unset($item);

        return [
            'total' => $total,
            'items' => $sortedItems,
        ];
    }

    private function normalizeDescription(?string $description): string
    {
        $normalized = trim((string) $description);

        if ($normalized === '') {
            return 'Sem descrição';
        }

        return $normalized;
    }
}
