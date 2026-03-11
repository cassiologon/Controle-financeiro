<?php

namespace App\Http\Controllers;

use App\Models\RecurringTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RecurringTransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = RecurringTransaction::where('user_id', $request->user()->id)
            ->with('category:id,name,icon,color')
            ->orderBy('created_at', 'desc');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $recurringTransactions = $query->get();

        return response()->json($recurringTransactions);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'type' => 'required|in:income,expense',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:255',
            'frequency' => 'required|in:daily,weekly,monthly,yearly',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after:start_date',
            'is_active' => 'sometimes|boolean',
        ]);

        $category = \App\Models\Category::findOrFail($validated['category_id']);
        
        if ($category->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $recurringTransaction = RecurringTransaction::create([
            ...$validated,
            'user_id' => $request->user()->id,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $recurringTransaction->load('category');

        return response()->json($recurringTransaction, 201);
    }

    public function show(Request $request, RecurringTransaction $recurringTransaction): JsonResponse
    {
        if ($recurringTransaction->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $recurringTransaction->load('category');

        return response()->json($recurringTransaction);
    }

    public function update(Request $request, RecurringTransaction $recurringTransaction): JsonResponse
    {
        if ($recurringTransaction->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'category_id' => 'sometimes|exists:categories,id',
            'type' => 'sometimes|in:income,expense',
            'amount' => 'sometimes|numeric|min:0.01',
            'description' => 'sometimes|string|max:255',
            'frequency' => 'sometimes|in:daily,weekly,monthly,yearly',
            'start_date' => 'sometimes|date',
            'end_date' => 'nullable|date|after:start_date',
            'is_active' => 'sometimes|boolean',
        ]);

        if (isset($validated['category_id'])) {
            $category = \App\Models\Category::findOrFail($validated['category_id']);
            if ($category->user_id !== $request->user()->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        $recurringTransaction->update($validated);
        $recurringTransaction->load('category');

        return response()->json($recurringTransaction);
    }

    public function destroy(Request $request, RecurringTransaction $recurringTransaction): JsonResponse
    {
        if ($recurringTransaction->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $recurringTransaction->delete();

        return response()->json(['message' => 'Recurring transaction deleted successfully']);
    }
}

