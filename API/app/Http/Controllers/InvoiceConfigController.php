<?php

namespace App\Http\Controllers;

use App\Models\InvoiceConfig;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InvoiceConfigController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $configs = InvoiceConfig::where('user_id', $request->user()->id)
            ->orderBy('bank_name')
            ->get();

        return response()->json($configs);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bank_name' => 'required|string|max:255',
            'closing_day' => 'required|integer|min:1|max:31',
            'is_active' => 'sometimes|boolean',
        ]);

        $config = InvoiceConfig::create([
            ...$validated,
            'user_id' => $request->user()->id,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        Log::info("InvoiceConfig criado: {$config->bank_name}, dia {$config->closing_day}");

        return response()->json($config, 201);
    }

    public function update(Request $request, InvoiceConfig $invoiceConfig): JsonResponse
    {
        if ($invoiceConfig->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'bank_name' => 'sometimes|string|max:255',
            'closing_day' => 'sometimes|integer|min:1|max:31',
            'is_active' => 'sometimes|boolean',
        ]);

        $invoiceConfig->update($validated);

        return response()->json($invoiceConfig);
    }

    public function destroy(Request $request, InvoiceConfig $invoiceConfig): JsonResponse
    {
        if ($invoiceConfig->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $invoiceConfig->delete();

        return response()->json(['message' => 'Configuração de fatura excluída com sucesso']);
    }

    public function summary(Request $request): JsonResponse
    {
        $configs = InvoiceConfig::where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->orderBy('bank_name')
            ->get();

        $today = Carbon::today();
        $summaries = [];

        foreach ($configs as $config) {
            [$periodStart, $periodEnd] = $this->calculateBillingPeriod($today, $config->closing_day);

            $total = Transaction::where('user_id', $request->user()->id)
                ->where('bank_name', $config->bank_name)
                ->where('type', 'expense')
                ->whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()])
                ->sum('amount');

            $summaries[] = [
                'id' => $config->id,
                'bank_name' => $config->bank_name,
                'closing_day' => $config->closing_day,
                'is_active' => $config->is_active,
                'total' => (float) $total,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
            ];
        }

        return response()->json($summaries);
    }

    private function calculateBillingPeriod(Carbon $today, int $closingDay): array
    {
        $currentDay = $today->day;

        if ($currentDay >= $closingDay) {
            $periodStart = $today->copy()->day($closingDay);
            $periodEnd = $today->copy()->addMonth()->day($closingDay)->subDay();
        } else {
            $periodStart = $today->copy()->subMonth()->day($closingDay);
            $periodEnd = $today->copy()->day($closingDay)->subDay();
        }

        return [$periodStart, $periodEnd];
    }
}
