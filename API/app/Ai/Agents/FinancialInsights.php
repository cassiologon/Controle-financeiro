<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

class FinancialInsights implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
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

Gere entre 3 e 6 insights, do maior impacto ao menor.
- summary: resumo com números concretos do período (receitas, despesas, saldo, principal destaque)
- title: título específico (ex: "Assinaturas — Starlink e Contabilizei concentram 80%")
- category: nome da categoria analisada ou "Geral"
- estimated_savings: número realista em reais, com base nos dados; use 0 quando a margem de economia não for clara
PROMPT;
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()
                ->description('Resumo do período com receitas, despesas, saldo e o principal destaque.')
                ->required(),

            'total_potential_savings' => $schema->number()
                ->description('Soma das economias estimadas de todos os insights, em reais.')
                ->min(0)
                ->required(),

            'insights' => $schema->array()
                ->items($schema->object(fn (JsonSchema $schema) => [
                    'title' => $schema->string()->required(),
                    'description' => $schema->string()->required(),
                    'category' => $schema->string()->required(),
                    'impact' => $schema->string()->enum(['high', 'medium', 'low'])->required(),
                    'estimated_savings' => $schema->number()->min(0)->required(),
                ]))
                ->min(3)
                ->max(6)
                ->required(),
        ];
    }

    /**
     * Get the provider-specific options to be passed to the provider.
     */
    public function providerOptions(Lab|string $provider): array
    {
        $provider = is_string($provider) ? Lab::tryFrom($provider) : $provider;

        $effort = config('ai.insights.reasoning_effort');

        if (blank($effort)) {
            return [];
        }

        return match ($provider) {
            Lab::OpenAI, Lab::OpenRouter, Lab::Azure => ['reasoning' => ['effort' => $effort]],
            default => [],
        };
    }
}
