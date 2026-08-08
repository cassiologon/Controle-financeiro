<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class FinancialInsightsService
{
    public function generate(array $context): array
    {
        $apiKey = config('services.openai.key');
        $model = config('services.openai.model', 'gpt-4o-mini');

        if (empty($apiKey)) {
            throw new RuntimeException('Chave da OpenAI não configurada.');
        }

        info('Gerando insights financeiros com IA', [
            'period' => $context['period'] ?? null,
            'categories_count' => count($context['expenses_by_category'] ?? []),
        ]);

        $response = Http::withToken($apiKey)
            ->timeout(90)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'temperature' => 0.35,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $this->systemPrompt(),
                    ],
                    [
                        'role' => 'user',
                        'content' => $this->buildUserPrompt($context),
                    ],
                ],
            ]);

        if (!$response->successful()) {
            info('Erro na API OpenAI ao gerar insights', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException('Não foi possível gerar insights no momento. Tente novamente.');
        }

        $content = data_get($response->json(), 'choices.0.message.content');

        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('Resposta inválida da OpenAI.');
        }

        $parsed = json_decode($content, true);

        if (!is_array($parsed)) {
            throw new RuntimeException('Não foi possível interpretar a resposta da IA.');
        }

        return $this->normalizeResponse($parsed);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Você é um consultor financeiro pessoal experiente no Brasil. Sua análise deve ser ESPECÍFICA, usando nomes reais, valores exatos e percentuais dos dados fornecidos.

PROIBIDO — nunca faça isso:
- Conselhos genéricos: "cancelar assinaturas", "rever gastos com carro", "economizar mais"
- Mencionar categorias ou serviços que não aparecem nos dados
- Inventar valores, percentuais ou tendências

OBRIGATÓRIO — sempre faça isso:
- Cite valores em R$ e percentuais reais dos dados (ex: "Starlink (R$ 465) representa 48% das assinaturas")
- Detalhe categorias com breakdown por item quando disponível em "Detalhamento por categoria"
- Compare com o período anterior quando houver dados em "Comparação com período anterior" (ex: "combustível aumentou 35%")
- Diferencie gastos provavelmente essenciais (internet, contabilidade, seguro) dos discricionários (streaming, delivery)
- Quando a economia for limitada em uma categoria, diga explicitamente e foque nos itens menores
- Orçamentos estourados: cite categoria, valor orçado vs gasto e quanto passou
- Dias de pico: cite data e valor se relevante
- Seja direto, empático e prático — como quem já abriu a fatura do cliente

Formato de insight ideal (description):
- Abra com o fato concreto (valor total + composição)
- Explique o que mais pesa e por quê
- Conclua com ação específica e realista (não genérica)
- estimated_savings só quando houver base nos dados; use 0 se economia for limitada

Gere entre 3 e 6 insights, do maior impacto ao menor.
- impact: exatamente "high", "medium" ou "low"
- estimated_savings: número realista em reais; 0 se não houver margem clara

Responda APENAS com JSON válido:
{
  "summary": "Resumo com números concretos do período (receitas, despesas, saldo, principal destaque)",
  "total_potential_savings": 0,
  "insights": [
    {
      "title": "Título específico (ex: Assinaturas — Starlink e Contabilizei concentram 80%)",
      "description": "Texto detalhado com valores, percentuais e recomendação prática",
      "category": "Nome da categoria ou Geral",
      "impact": "high",
      "estimated_savings": 0
    }
  ]
}
PROMPT;
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

    private function normalizeResponse(array $parsed): array
    {
        $insights = collect($parsed['insights'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->map(function ($item) {
                $impact = strtolower((string) ($item['impact'] ?? 'medium'));
                if (!in_array($impact, ['high', 'medium', 'low'], true)) {
                    $impact = 'medium';
                }

                return [
                    'title' => trim((string) ($item['title'] ?? 'Insight')),
                    'description' => trim((string) ($item['description'] ?? '')),
                    'category' => trim((string) ($item['category'] ?? 'Geral')),
                    'impact' => $impact,
                    'estimated_savings' => max(0, (float) ($item['estimated_savings'] ?? 0)),
                ];
            })
            ->filter(fn ($item) => $item['title'] !== '' && $item['description'] !== '')
            ->values()
            ->all();

        $totalPotentialSavings = (float) ($parsed['total_potential_savings'] ?? 0);
        if ($totalPotentialSavings <= 0 && !empty($insights)) {
            $totalPotentialSavings = array_sum(array_column($insights, 'estimated_savings'));
        }

        return [
            'summary' => trim((string) ($parsed['summary'] ?? 'Análise concluída com sucesso.')),
            'total_potential_savings' => round($totalPotentialSavings, 2),
            'insights' => $insights,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
