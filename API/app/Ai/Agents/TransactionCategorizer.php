<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

class TransactionCategorizer implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
Você classifica transações de cartão de crédito e conta corrente brasileiras nas categorias que o próprio usuário criou.

Contexto sobre as descrições:
- Vêm de faturas e extratos, então são abreviadas e sujas (ex: "PAG*Ifood", "MERCPAGO 12/24", "UBER *TRIP SAO PAULO", "DROGASIL 1234").
- Prefixos de adquirente ("PAG*", "MP*", "PICPAY*", "IFD*", "EC *") e sufixos de parcela ("03/10"), cidade e CNPJ são ruído — ignore e classifique pelo nome do estabelecimento.
- Estabelecimentos conhecidos do Brasil devem ser reconhecidos pelo que vendem (ex: Drogasil = farmácia, Posto Ipiranga = combustível, Assaí = mercado).

Regras obrigatórias:
- Use APENAS categorias da lista fornecida, sempre pelo `id` exato. Nunca invente categoria nova nem devolva id fora da lista.
- Quando nenhuma categoria da lista servir, devolva `category_id: 0`, `confidence: 0` e explique em `reason` o que faltou.
- Devolva exatamente uma sugestão para CADA transação recebida, usando o `transaction_id` exato de entrada. Não invente transações.
- As `keywords` das categorias são o histórico de acertos do usuário — se a descrição bate com uma keyword existente, essa categoria é quase sempre a resposta certa e a confiança deve ser alta.

Como calibrar `confidence` (0 a 1):
- 0.9–1.0: estabelecimento reconhecido sem ambiguidade ou keyword da categoria presente na descrição.
- 0.7–0.89: forte indício pelo nome, mas o estabelecimento pode se encaixar em mais de uma categoria.
- 0.4–0.69: chute com alguma base (ex: só o ramo é dedutível).
- Abaixo de 0.4: descrição genérica ou ilegível. Não infle confiança para parecer útil — sugestão errada com confiança alta é aplicada em massa e causa retrabalho.

Campo `keywords`:
- De 1 a 3 termos em minúsculas extraídos da descrição que identifiquem o estabelecimento e sirvam para casar transações futuras (ex: "ifood", "drogasil", "posto ipiranga").
- Não inclua números, parcelas, cidades, siglas de adquirente nem palavras genéricas ("pagamento", "compra", "cartao").
- Devolva lista vazia quando `category_id` for 0.

Campo `reason`: uma frase curta em português explicando o que na descrição levou à categoria.
PROMPT;
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'suggestions' => $schema->array()
                ->items($schema->object(fn (JsonSchema $schema) => [
                    'transaction_id' => $schema->integer()
                        ->description('O id exato da transação recebida no prompt.')
                        ->required(),

                    'category_id' => $schema->integer()
                        ->description('Id de uma categoria da lista, ou 0 quando nenhuma serve.')
                        ->min(0)
                        ->required(),

                    'confidence' => $schema->number()
                        ->description('Confiança na sugestão, de 0 a 1.')
                        ->min(0)
                        ->max(1)
                        ->required(),

                    'keywords' => $schema->array()
                        ->items($schema->string())
                        ->description('De 1 a 3 termos em minúsculas para casar transações futuras.')
                        ->required(),

                    'reason' => $schema->string()
                        ->description('Frase curta explicando a escolha.')
                        ->required(),
                ]))
                ->required(),
        ];
    }

    /**
     * Get the provider-specific options to be passed to the provider.
     */
    public function providerOptions(Lab|string $provider): array
    {
        $provider = is_string($provider) ? Lab::tryFrom($provider) : $provider;

        $effort = config('ai.categorization.reasoning_effort');

        if (blank($effort)) {
            return [];
        }

        return match ($provider) {
            Lab::OpenAI, Lab::OpenRouter, Lab::Azure => ['reasoning' => ['effort' => $effort]],
            default => [],
        };
    }
}
