<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\InvoiceParserService;
use App\Services\CategoryMatcherService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessInvoiceImport implements ShouldQueue
{
    use Queueable;

    /**
     * Contagem de ocorrências por fingerprint durante a importação atual.
     * Permite linhas idênticas no CSV (ex.: cobranças repetidas no extrato).
     *
     * @var array<string, int>
     */
    private array $importFingerprintCounts = [];

    /**
     * IDs de parcelas ja criadas ou reconciliadas nesta importacao.
     * Impede que duas compras distintas disputem o mesmo slot.
     *
     * @var array<int, int>
     */
    private array $reconciledIds = [];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $filePath,
        public int $userId,
        public string $bankName
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(
        InvoiceParserService $parserService,
        CategoryMatcherService $matcherService
    ): void {
        try {
            Log::info("Iniciando processamento de importação: {$this->filePath}");
            Log::info("User ID: {$this->userId}");
            Log::info("Bank Name: {$this->bankName}");
            
            // Verifica se o arquivo existe antes de processar
            $fullPath1 = Storage::path($this->filePath);
            $fullPath2 = storage_path('app/' . $this->filePath);
            $fullPath3 = storage_path('app/public/' . $this->filePath);
            
            Log::info("Tentando caminho 1: {$fullPath1}, Existe: " . (file_exists($fullPath1) ? 'sim' : 'não'));
            Log::info("Tentando caminho 2: {$fullPath2}, Existe: " . (file_exists($fullPath2) ? 'sim' : 'não'));
            Log::info("Tentando caminho 3: {$fullPath3}, Existe: " . (file_exists($fullPath3) ? 'sim' : 'não'));
            
            $fullPath = null;
            if (file_exists($fullPath1)) {
                $fullPath = $fullPath1;
            } elseif (file_exists($fullPath2)) {
                $fullPath = $fullPath2;
            } elseif (file_exists($fullPath3)) {
                $fullPath = $fullPath3;
            }
            
            if (!$fullPath || !file_exists($fullPath)) {
                Log::error("Arquivo não encontrado em nenhum caminho tentado", [
                    'file_path' => $this->filePath,
                    'paths_tried' => [$fullPath1, $fullPath2, $fullPath3],
                ]);
                throw new \RuntimeException("Arquivo não encontrado: {$this->filePath}");
            }
            
            Log::info("Arquivo encontrado em: {$fullPath}");

            // Detecta tipo de arquivo
            try {
                $fileType = $parserService->detectFileType($this->filePath);
                Log::info("Tipo de arquivo detectado: {$fileType}");
            } catch (\Exception $e) {
                Log::error("Erro ao detectar tipo de arquivo: " . $e->getMessage());
                throw $e;
            }
            
            // Extrai transações do arquivo
            try {
                Log::info("Iniciando extração de transações...");
                $transactions = match ($fileType) {
                    'csv' => $parserService->parseCsv($this->filePath),
                    'pdf' => $parserService->parsePdf($this->filePath),
                    'ofx' => $parserService->parseOfx($this->filePath),
                    default => throw new \RuntimeException("Tipo de arquivo não suportado: {$fileType}"),
                };
                Log::info("Extraídas " . count($transactions) . " transações do arquivo");
            } catch (\Exception $e) {
                Log::error("Erro ao extrair transações: " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }

            $processed = 0;
            $categorized = 0;
            $pending = 0;

            // Verifica se o usuário possui categorias com keywords
            $hasKeywords = $matcherService->hasCategoriesWithKeywords($this->userId);
            
            if (!$hasKeywords) {
                Log::info("Nenhuma categoria com keywords encontrada. Todas as transações serão criadas como pendentes.");
            }

            $this->importFingerprintCounts = [];
            $this->reconciledIds = [];

            // Processa cada transação
            foreach ($transactions as $transactionData) {
                try {
                    $description = trim($transactionData['description']);
                    $installmentMetadata = $parserService->extractInstallmentMetadata($description);
                    $isInstallment = $installmentMetadata !== null;
                    $transactionType = in_array($transactionData['type'] ?? null, ['income', 'expense'], true)
                        ? $transactionData['type']
                        : 'expense';

                    if ($isInstallment) {
                        $description = $installmentMetadata['normalized_description'];
                    }

                    $category = null;
                    
                    // Só tenta fazer match se houver categorias com keywords
                    if ($hasKeywords && $transactionType === 'expense') {
                        $descriptionForMatch = $installmentMetadata['base_description'] ?? $description;

                        // Tenta encontrar categoria por palavras-chave
                        $category = $matcherService->matchCategory(
                            $descriptionForMatch,
                            $this->userId
                        );
                    }

                    // Cria a transação atual (evita duplicidade em reimportação)
                    $basePayload = [
                        'user_id' => $this->userId,
                        'category_id' => $category?->id ?? null,
                        'type' => $transactionType,
                        'amount' => $transactionData['amount'],
                        'description' => $description,
                        'date' => $transactionData['date'],
                        'status' => $category ? 'categorized' : 'pending',
                        'bank_name' => $this->bankName,
                        'external_id' => $transactionData['external_id'] ?? null,
                        'external_ref' => $transactionData['external_ref'] ?? null,
                        'is_installment' => $isInstallment,
                    ];

                    $transaction = $this->createTransactionIfNotExists(
                        $basePayload,
                        $isInstallment ? ['is_actual' => true] : null
                    );
                    
                    // Log para debug (apenas primeira transação)
                    if ($transaction && $processed === 0) {
                        Log::info("Primeira transação criada com bank_name", [
                            'transaction_id' => $transaction->id,
                            'bank_name' => $transaction->bank_name,
                            'expected_bank_name' => $this->bankName,
                        ]);
                    }

                    if ($transaction) {
                        $processed++;
                        if ($category) {
                            $categorized++;
                        } else {
                            $pending++;
                        }
                    }

                    // Gera parcelas futuras quando a transação importada já é parcelada
                    if ($isInstallment) {
                        $currentInstallment = $installmentMetadata['current_installment'];
                        $totalInstallments = $installmentMetadata['total_installments'];
                        $baseDescription = $installmentMetadata['base_description'];

                        if ($currentInstallment < $totalInstallments) {
                            $baseDate = Carbon::parse($transactionData['date']);

                            for ($installmentNumber = $currentInstallment + 1; $installmentNumber <= $totalInstallments; $installmentNumber++) {
                                $monthsToAdd = $installmentNumber - $currentInstallment;
                                $futureDate = $baseDate->copy()->addMonthsNoOverflow($monthsToAdd)->toDateString();
                                $futureDescription = $parserService->buildInstallmentDescription(
                                    $baseDescription,
                                    $installmentNumber,
                                    $totalInstallments
                                );

                                $futurePayload = [
                                    'user_id' => $this->userId,
                                    'category_id' => $category?->id ?? null,
                                    'type' => $transactionType,
                                    'amount' => $transactionData['amount'],
                                    'description' => $futureDescription,
                                    'date' => $futureDate,
                                    'status' => $category ? 'categorized' : 'pending',
                                    'bank_name' => $this->bankName,
                                    'is_installment' => true,
                                ];

                                $futureTransaction = $this->createTransactionIfNotExists(
                                    $futurePayload,
                                    ['is_actual' => false]
                                );
                                if ($futureTransaction) {
                                    $processed++;
                                    if ($category) {
                                        $categorized++;
                                    } else {
                                        $pending++;
                                    }
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::error("Erro ao processar transação: " . $e->getMessage(), [
                        'transaction' => $transactionData,
                    ]);
                    // Continua processando outras transações
                }
            }

            Log::info("Processamento concluído: {$processed} processadas, {$categorized} categorizadas, {$pending} pendentes");

            // Remove arquivo temporário após processamento
            $fileToDelete = storage_path('app/' . $this->filePath);
            if (file_exists($fileToDelete)) {
                unlink($fileToDelete);
                Log::info("Arquivo temporário removido: {$fileToDelete}");
            } elseif (Storage::exists($this->filePath)) {
                Storage::delete($this->filePath);
                Log::info("Arquivo temporário removido via Storage: {$this->filePath}");
            }
        } catch (\Exception $e) {
            Log::error("Erro ao processar importação: " . $e->getMessage(), [
                'file_path' => $this->filePath,
                'user_id' => $this->userId,
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Re-throw para que o Laravel possa registrar como falha
            throw $e;
        }
    }

    private function createTransactionIfNotExists(array $payload, ?array $installment = null): ?Transaction
    {
        // O OFX traz uma chave deterministica por lancamento: quando existe, ela
        // decide sozinha se a transacao ja foi importada.
        if (!empty($payload['external_ref'])) {
            $ref = $payload['external_ref'];
            $this->importFingerprintCounts[$ref] = ($this->importFingerprintCounts[$ref] ?? 0) + 1;

            $existing = Transaction::query()
                ->where('user_id', $payload['user_id'])
                ->where('bank_name', $payload['bank_name'])
                ->where('external_ref', $ref)
                ->count();

            if ($existing >= $this->importFingerprintCounts[$ref]) {
                return null;
            }

            // A parcela real pode ja existir como projecao (sem external_ref) de uma
            // fatura anterior: reaproveita o registro em vez de duplicar.
            if ($installment !== null) {
                $slot = $this->findInstallmentSlot($payload);

                if ($slot !== null) {
                    $this->reconciledIds[] = $slot->id;
                    $slot->update([
                        'amount' => $payload['amount'],
                        'date' => $payload['date'],
                        'external_id' => $payload['external_id'] ?? null,
                        'external_ref' => $ref,
                    ]);

                    return null;
                }
            }

            // A mesma fatura pode ter sido importada antes em CSV ou PDF, formatos
            // sem identificador. Nesse caso o OFX adota o lancamento existente e lhe
            // da identidade, em vez de criar um segundo registro do mesmo gasto.
            $legacy = $this->findEquivalentWithoutRef($payload);

            if ($legacy !== null) {
                $this->reconciledIds[] = $legacy->id;
                $legacy->update([
                    'external_id' => $payload['external_id'] ?? null,
                    'external_ref' => $ref,
                ]);

                Log::info("Lancamento importado sem identificador adotado pelo OFX", [
                    'transaction_id' => $legacy->id,
                    'description' => $payload['description'],
                    'external_ref' => $ref,
                ]);

                return null;
            }

            $transaction = Transaction::create($payload);
            $this->reconciledIds[] = $transaction->id;

            return $transaction;
        }

        $fingerprint = $this->buildTransactionFingerprint($payload);
        $this->importFingerprintCounts[$fingerprint] = ($this->importFingerprintCounts[$fingerprint] ?? 0) + 1;
        $requiredCount = $this->importFingerprintCounts[$fingerprint];

        $existingCount = $this->countExistingTransactions($payload);

        if ($existingCount >= $requiredCount) {
            return null;
        }

        // Parcelas projetadas por uma fatura anterior ocupam o mesmo "slot" (mesma
        // serie, mesma parcela, mesmo mes) da linha que chega na fatura seguinte.
        // Sem reconciliar, a divergencia de centavos ou do dia de fechamento faz o
        // fingerprint diferir e a mesma parcela vira dois registros.
        if ($installment !== null) {
            $slot = $this->findInstallmentSlot($payload);

            if ($slot !== null) {
                $this->reconciledIds[] = $slot->id;

                // O dado da fatura real prevalece sobre a projecao.
                if ($installment['is_actual'] ?? false) {
                    $slot->update([
                        'amount' => $payload['amount'],
                        'date' => $payload['date'],
                    ]);

                    Log::info("Parcela projetada reconciliada com a fatura real", [
                        'transaction_id' => $slot->id,
                        'description' => $payload['description'],
                        'amount' => $payload['amount'],
                        'date' => $payload['date'],
                    ]);
                }

                return null;
            }
        }

        $transaction = Transaction::create($payload);

        if ($installment !== null) {
            $this->reconciledIds[] = $transaction->id;
        }

        return $transaction;
    }

    /**
     * Procura um lancamento identico ja importado por um formato sem identificador
     * (CSV ou PDF), para que o OFX o adote em vez de duplicar o mesmo gasto.
     */
    private function findEquivalentWithoutRef(array $payload): ?Transaction
    {
        $amount = round((float) $payload['amount'], 2);

        return Transaction::query()
            ->where('user_id', $payload['user_id'])
            ->where('bank_name', $payload['bank_name'])
            ->where('type', $payload['type'])
            ->where('description', $payload['description'])
            ->whereBetween('amount', [$amount - 0.005, $amount + 0.005])
            ->whereDate('date', Carbon::parse($payload['date'])->toDateString())
            ->whereNull('external_ref')
            ->whereNotIn('id', $this->reconciledIds ?: [0])
            ->orderBy('id')
            ->first();
    }

    /**
     * Procura uma parcela ja gravada que ocupe o mesmo slot do payload.
     *
     * A comparacao usa mes/ano em vez do dia exato e tolera variacao de centavos,
     * porque a projecao herda o valor e o dia da parcela de origem enquanto a
     * fatura real traz o valor cobrado e a data de fechamento do mes.
     */
    private function findInstallmentSlot(array $payload): ?Transaction
    {
        $date = Carbon::parse($payload['date']);
        $amount = round((float) $payload['amount'], 2);
        $tolerance = max(1.0, abs($amount) * 0.02);

        return Transaction::query()
            ->where('user_id', $payload['user_id'])
            ->where('bank_name', $payload['bank_name'])
            ->where('type', $payload['type'])
            ->where('is_installment', true)
            ->where('description', $payload['description'])
            ->whereYear('date', $date->year)
            ->whereMonth('date', $date->month)
            ->whereBetween('amount', [$amount - $tolerance, $amount + $tolerance])
            ->whereNotIn('id', $this->reconciledIds ?: [0])
            // Nao rouba o slot de um lancamento que ja tem identidade propria do OFX.
            ->where(function ($query) use ($payload) {
                $query->whereNull('external_ref');

                if (!empty($payload['external_ref'])) {
                    $query->orWhere('external_ref', $payload['external_ref']);
                }
            })
            ->orderBy('id')
            ->first();
    }

    private function buildTransactionFingerprint(array $payload): string
    {
        return implode('|', [
            (string) $payload['user_id'],
            (string) $payload['type'],
            number_format((float) $payload['amount'], 2, '.', ''),
            (string) $payload['description'],
            Carbon::parse($payload['date'])->toDateString(),
            (string) $payload['bank_name'],
        ]);
    }

    private function countExistingTransactions(array $payload): int
    {
        $amount = round((float) $payload['amount'], 2);

        return Transaction::query()
            ->where('user_id', $payload['user_id'])
            ->where('type', $payload['type'])
            // amount e coluna numerica: comparar com a string de number_format nunca casa.
            ->whereBetween('amount', [$amount - 0.005, $amount + 0.005])
            ->where('description', $payload['description'])
            // a coluna guarda "Y-m-d H:i:s"; comparar com "Y-m-d" cru nunca casa.
            ->whereDate('date', Carbon::parse($payload['date'])->toDateString())
            ->where('bank_name', $payload['bank_name'])
            ->count();
    }
}
