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
- Prefira SEMPRE uma categoria da lista fornecida, pelo `id` exato. Nunca devolva um id que não esteja na lista.
- Só quando nenhuma categoria da lista servir, devolva `category_id: 0` e proponha uma categoria nova em `new_category_name` — ela será criada para o usuário.
- Devolva exatamente uma sugestão para CADA transação recebida, usando o `transaction_id` exato de entrada. Não invente transações.
- As `keywords` das categorias são o histórico de acertos do usuário — se a descrição bate com uma keyword existente, essa categoria é quase sempre a resposta certa e a confiança deve ser alta.

Categoria nova (`new_category_name` e `new_category_icon`):
- Preencha os dois APENAS quando `category_id` for 0. Quando usar uma categoria da lista, devolva os dois como string vazia.
- `new_category_name`: nome curto em português, no singular e capitalizado, do tipo que serve para muitos gastos parecidos ("Farmácia", "Combustível", "Material de Construção"). Nunca use o nome do estabelecimento ("Drogasil", "Merkel Elétrica") nem nomes longos ou com barra.
- Antes de propor, releia a lista: se já existe categoria equivalente com outro nome ou grafia, use o `id` dela em vez de criar outra.
- Reaproveite o mesmo nome dentro do lote: dois gastos do mesmo tipo devem propor exatamente a mesma categoria nova, escrita igual.
- `new_category_icon`: um único emoji que represente a categoria ("💊", "⛽", "🔧").
- A confiança de uma categoria nova segue a mesma régua: ela mede o quanto você reconheceu o gasto, não o quanto gostou do nome.

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
                        ->description('Id de uma categoria da lista, ou 0 quando nenhuma serve e uma nova é proposta.')
                        ->min(0)
                        ->required(),

                    'new_category_name' => $schema->string()
                        ->description('Nome da categoria a criar quando category_id for 0. String vazia caso contrário.')
                        ->required(),

                    'new_category_icon' => $schema->string()
                        ->description('Um emoji para a categoria nova. String vazia quando category_id não for 0.')
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
                        ->description('Frase curta explicando a escolha da categoria, existente ou nova.')
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
