<?php

namespace App\Services;

use App\Ai\Agents\FinancialInsights;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

class FinancialInsightsService
{
    public function __construct(private FinancialInsights $agent)
    {
    }

    public function generate(array $context): array
    {
        $provider = config('ai.insights.provider');
        $model = config('ai.insights.model');
        $timeout = (int) config('ai.insights.timeout');

        info('Gerando insights financeiros com IA', [
            'provider' => $provider,
            'model' => $model,
            'period' => $context['period'] ?? null,
            'categories_count' => count($context['expenses_by_category'] ?? []),
        ]);

        try {
            $response = $this->agent->prompt(
                $this->buildUserPrompt($context),
                provider: $provider,
                model: $model,
                timeout: $timeout,
            );
        } catch (AiException $e) {
            info('Erro do provedor de IA ao gerar insights', [
                'provider' => $provider,
                'model' => $model,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw new RuntimeException('Não foi possível gerar insights no momento. Tente novamente.');
        } catch (Throwable $e) {
            info('Falha inesperada ao gerar insights', [
                'provider' => $provider,
                'model' => $model,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw new RuntimeException('Não foi possível gerar insights no momento. Tente novamente.');
        }

        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException('Resposta inválida do provedor de IA.');
        }

        info('Insights financeiros gerados', [
            'prompt_tokens' => $response->usage->promptTokens,
            'completion_tokens' => $response->usage->completionTokens,
            'reasoning_tokens' => $response->usage->reasoningTokens,
        ]);

        return $this->normalizeResponse($response->toArray());
    }

    private function buildUserPrompt(array $context): string
    {
        $period = $context['period'] ?? [];
        $summary = $context['summary'] ?? [];
        $expensesByCategory = $context['expenses_by_category'] ?? [];
        $categoryBreakdowns = $context['category_breakdowns'] ?? [];
        $periodComparison = $context['period_comparison'] ?? [];
        $subscriptionsDetail = $context['subscriptions_detail'] ?? [];
        $recurringExpenses = $context['recurring_expenses'] ?? [];
        $dailyStats = $context['daily_stats'] ?? [];
        $budgets = $context['budgets'] ?? [];
        $topExpenses = $context['top_expenses'] ?? [];

        $lines = [
            'Analise os gastos abaixo. Use APENAS os dados fornecidos. Seja específico com nomes e valores.',
            '',
            '## Período',
            sprintf(
                'De %s até %s (%d dias)',
                $period['start_date'] ?? '',
                $period['end_date'] ?? '',
                $period['days'] ?? 0
            ),
            '',
            '## Resumo',
            sprintf('- Receitas: R$ %.2f', $summary['total_income'] ?? 0),
            sprintf('- Despesas: R$ %.2f', $summary['total_expense'] ?? 0),
            sprintf('- Saldo: R$ %.2f', $summary['balance'] ?? 0),
            sprintf('- Média diária de gastos: R$ %.2f', $summary['average_daily_expense'] ?? 0),
            '',
            '## Gastos por categoria',
        ];

        foreach ($expensesByCategory as $item) {
            $lines[] = sprintf(
                '- %s: R$ %.2f (%s%% do total)%s',
                $item['category_name'] ?? 'Sem categoria',
                $item['total'] ?? 0,
                number_format($item['percentage'] ?? 0, 1, '.', ''),
                !empty($item['recurring_total']) ? sprintf(' [R$ %.2f recorrente]', $item['recurring_total']) : ''
            );
        }

        if (!empty($categoryBreakdowns)) {
            $lines[] = '';
            $lines[] = '## Detalhamento por categoria (use estes dados para insights específicos)';
            foreach ($categoryBreakdowns as $breakdown) {
                $lines[] = sprintf(
                    '### %s — total R$ %.2f',
                    $breakdown['category_name'] ?? 'Sem categoria',
                    $breakdown['total'] ?? 0
                );
                foreach ($breakdown['items'] ?? [] as $item) {
                    $lines[] = sprintf(
                        '  - %s: R$ %.2f (%s%% da categoria)%s%s',
                        $item['description'] ?? 'Sem descrição',
                        $item['amount'] ?? 0,
                        number_format($item['percentage_of_category'] ?? 0, 1, '.', ''),
                        !empty($item['is_recurring']) ? ' [recorrente]' : '',
                        !empty($item['transaction_count']) && $item['transaction_count'] > 1
                            ? sprintf(' (%dx no período)', $item['transaction_count'])
                            : ''
                    );
                }
            }
        }

        if (!empty($subscriptionsDetail['items'])) {
            $lines[] = '';
            $lines[] = sprintf(
                '## Assinaturas e serviços recorrentes — total R$ %.2f',
                $subscriptionsDetail['total'] ?? 0
            );
            foreach ($subscriptionsDetail['items'] as $item) {
                $lines[] = sprintf(
                    '- %s (%s): R$ %.2f (%s%% do total de assinaturas)%s',
                    $item['description'] ?? 'Sem descrição',
                    $item['category_name'] ?? 'Sem categoria',
                    $item['amount'] ?? 0,
                    number_format($item['percentage_of_subscriptions'] ?? 0, 1, '.', ''),
                    !empty($item['is_recurring']) ? ' [recorrente fixa]' : ''
                );
            }
        }

        $prevPeriod = $periodComparison['previous_period'] ?? [];
        $totalChange = $periodComparison['total_expense'] ?? [];
        if (!empty($prevPeriod['start_date'])) {
            $lines[] = '';
            $lines[] = '## Comparação com período anterior';
            $lines[] = sprintf(
                'Período anterior: %s a %s',
                $prevPeriod['start_date'] ?? '',
                $prevPeriod['end_date'] ?? ''
            );
            $lines[] = sprintf(
                '- Despesas totais: R$ %.2f (atual) vs R$ %.2f (anterior) — variação %s%%',
                $totalChange['current'] ?? 0,
                $totalChange['previous'] ?? 0,
                number_format($totalChange['change_percent'] ?? 0, 1, '.', '')
            );

            if (!empty($periodComparison['categories'])) {
                $lines[] = '- Variação por categoria:';
                foreach ($periodComparison['categories'] as $cat) {
                    $lines[] = sprintf(
                        '  - %s: R$ %.2f → R$ %.2f (%s%%)',
                        $cat['category_name'] ?? '',
                        $cat['previous'] ?? 0,
                        $cat['current'] ?? 0,
                        number_format($cat['change_percent'] ?? 0, 1, '.', '')
                    );
                }
            }

            if (!empty($periodComparison['notable_items'])) {
                $lines[] = '- Itens com maior variação:';
                foreach ($periodComparison['notable_items'] as $item) {
                    $lines[] = sprintf(
                        '  - %s (%s): R$ %.2f → R$ %.2f (%s%%)',
                        $item['description'] ?? '',
                        $item['category_name'] ?? '',
                        $item['previous'] ?? 0,
                        $item['current'] ?? 0,
                        number_format($item['change_percent'] ?? 0, 1, '.', '')
                    );
                }
            }
        }

        if (!empty($recurringExpenses)) {
            $lines[] = '';
            $lines[] = '## Despesas recorrentes cadastradas';
            foreach ($recurringExpenses as $item) {
                $lines[] = sprintf(
                    '- %s (%s): R$ %.2f/mês',
                    $item['description'] ?? 'Sem descrição',
                    $item['category_name'] ?? 'Sem categoria',
                    $item['amount'] ?? 0
                );
            }
        }

        if (!empty($dailyStats)) {
            $lines[] = '';
            $lines[] = '## Estatísticas diárias';
            $lines[] = sprintf('- Dias com gastos: %d', $dailyStats['days_with_expenses'] ?? 0);
            $lines[] = sprintf(
                '- Maior gasto em um dia: R$ %.2f (%s)',
                $dailyStats['max_day_total'] ?? 0,
                $dailyStats['max_day_date'] ?? '-'
            );
        }

        if (!empty($budgets)) {
            $lines[] = '';
            $lines[] = '## Orçamentos do mês';
            foreach ($budgets as $budget) {
                $lines[] = sprintf(
                    '- %s: orçado R$ %.2f, gasto R$ %.2f (%.0f%%)%s',
                    $budget['category_name'] ?? 'Sem categoria',
                    $budget['budget_amount'] ?? 0,
                    $budget['spent_amount'] ?? 0,
                    $budget['percentage'] ?? 0,
                    ($budget['percentage'] ?? 0) >= 100 ? ' [ESTOURADO]' : ''
                );
            }
        }

        if (!empty($topExpenses)) {
            $lines[] = '';
            $lines[] = '## Maiores despesas individuais';
            foreach ($topExpenses as $expense) {
                $lines[] = sprintf(
                    '- %s (%s, %s): R$ %.2f',
                    $expense['description'] ?? 'Sem descrição',
                    $expense['category_name'] ?? 'Sem categoria',
                    $expense['date'] ?? '',
                    $expense['amount'] ?? 0
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * O schema de saída estruturada já garante tipos, o enum de impacto e a
     * presença dos campos, então aqui resta apenas arredondar e derivar totais.
     */
    private function normalizeResponse(array $parsed): array
    {
        $insights = collect($parsed['insights'] ?? [])
            ->map(fn (array $item) => [
                'title' => trim($item['title']),
                'description' => trim($item['description']),
                'category' => trim($item['category']),
                'impact' => $item['impact'],
                'estimated_savings' => round(max(0, (float) $item['estimated_savings']), 2),
            ])
            ->filter(fn (array $item) => $item['title'] !== '' && $item['description'] !== '')
            ->values()
            ->all();

        $totalPotentialSavings = (float) ($parsed['total_potential_savings'] ?? 0);
        if ($totalPotentialSavings <= 0 && ! empty($insights)) {
            $totalPotentialSavings = array_sum(array_column($insights, 'estimated_savings'));
        }

        return [
            'summary' => trim($parsed['summary'] ?? ''),
            'total_potential_savings' => round($totalPotentialSavings, 2),
            'insights' => $insights,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
