<?php

namespace App\Services;

use League\Csv\Reader;
use Smalot\PdfParser\Parser;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class InvoiceParserService
{
    /**
     * Detecta o tipo de arquivo
     */
    public function detectFileType(string $filePath): string
    {
        // Obtém a extensão do caminho relativo ou absoluto
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        Log::info("Detectando tipo de arquivo: {$filePath}, extensão: {$extension}");

        if ($extension === 'csv') {
            return 'csv';
        }

        if ($extension === 'pdf') {
            return 'pdf';
        }

        throw new \InvalidArgumentException('Tipo de arquivo não suportado. Use CSV ou PDF.');
    }

    /**
     * Extrai transações de um arquivo CSV (Nubank)
     */
    public function parseCsv(string $filePath): array
    {
        $transactions = [];

        try {
            // Tenta obter o caminho completo do arquivo
            // O arquivo está em storage/app/ mas o disco padrão pode apontar para storage/app/private
            $fullPath = storage_path('app/' . $filePath);

            // Se não existir, tenta o caminho do Storage padrão
            if (!file_exists($fullPath)) {
                $fullPath = Storage::path($filePath);
            }

            // Se ainda não existir, tenta o caminho absoluto
            if (!file_exists($fullPath)) {
                $fullPath = $filePath;
            }

            Log::info("Processando CSV: {$fullPath}");
            Log::info("Arquivo existe: " . (file_exists($fullPath) ? 'sim' : 'não'));

            if (!file_exists($fullPath)) {
                throw new \RuntimeException("Arquivo não encontrado: {$filePath} (tentou: {$fullPath})");
            }

            $csv = Reader::createFromPath($fullPath, 'r');
            $csv->setHeaderOffset(0);

            // Obtém os cabeçalhos para debug
            $headers = $csv->getHeader();
            Log::info("Cabeçalhos encontrados no CSV: " . implode(', ', $headers));

            $records = $csv->getRecords();
            $rowCount = 0;

            foreach ($records as $record) {
                $rowCount++;

                // Normaliza as chaves do CSV (case-insensitive e remove espaços)
                $normalized = [];
                foreach ($record as $key => $value) {
                    $normalizedKey = strtolower(trim($key));
                    $normalizedValue = trim($value);

                    // Ignora valores vazios
                    if ($normalizedValue === '') {
                        continue;
                    }

                    $normalized[$normalizedKey] = $normalizedValue;
                }

                // Pula linhas vazias
                if (empty($normalized)) {
                    continue;
                }

                // Log das primeiras 3 linhas para debug
                if ($rowCount <= 3) {
                    Log::info("Linha {$rowCount} normalizada: " . json_encode($normalized));
                }

                // Tenta identificar colunas comuns do Nubank
                $date = $this->extractDate($normalized);
                $description = $this->extractDescription($normalized);
                $amount = $this->extractAmount($normalized);

                // Log das primeiras 3 linhas para debug
                if ($rowCount <= 3) {
                    Log::info("Linha {$rowCount} - Data: " . ($date ?? 'null') . ", Descrição: " . ($description ?? 'null') . ", Valor: " . ($amount ?? 'null'));
                }

                if ($date && $description && $amount !== null) {
                    $transactions[] = [
                        'date' => $date,
                        'description' => trim($description),
                        'amount' => abs($amount), // Garante valor positivo para despesas
                    ];
                } else {
                    // Log apenas as primeiras 3 linhas ignoradas para não poluir o log
                    if ($rowCount <= 3) {
                        Log::warning("Linha {$rowCount} ignorada - dados incompletos", [
                            'date' => $date,
                            'description' => $description,
                            'amount' => $amount,
                            'normalized' => $normalized,
                        ]);
                    }
                }
            }

            Log::info("Total de linhas processadas: {$rowCount}, Transações extraídas: " . count($transactions));
        } catch (\Exception $e) {
            Log::error('Erro ao processar CSV: ' . $e->getMessage(), [
                'file_path' => $filePath,
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \RuntimeException('Erro ao processar arquivo CSV: ' . $e->getMessage());
        }

        return $transactions;
    }

    /**
     * Extrai texto de PDF usando pdftotext (mais preciso) ou fallback para smalot/pdfparser
     */
    private function extractPdfText(string $filePath): string
    {
        // Se o caminho já é absoluto e existe, usa diretamente
        if (file_exists($filePath) && (strpos($filePath, '/') === 0 || strpos($filePath, '\\') === 0 || preg_match('/^[A-Za-z]:/', $filePath))) {
            $fullPath = $filePath;
        } else {
            // Tenta resolver caminho relativo
            $fullPath = storage_path('app/' . $filePath);
            if (!file_exists($fullPath)) {
                $fullPath = Storage::path($filePath);
            }
            if (!file_exists($fullPath)) {
                $fullPath = $filePath;
            }
        }

        if (!file_exists($fullPath)) {
            throw new \RuntimeException("Arquivo não encontrado: {$filePath}");
        }

        // Tenta usar pdftotext primeiro (mais preciso, menos corrupção)
        if ($this->isPdftotextAvailable()) {
            Log::info("Usando pdftotext para extrair texto do PDF");
            return $this->extractTextWithPdftotext($fullPath);
        }

        // Fallback para smalot/pdfparser
        Log::info("Usando smalot/pdfparser como fallback");
        $parser = new Parser();
        $pdf = $parser->parseFile($fullPath);
        return $pdf->getText();
    }

    /**
     * Verifica se pdftotext está disponível no sistema
     */
    private function isPdftotextAvailable(): bool
    {
        static $available = null;

        if ($available === null) {
            $output = [];
            $returnVar = 0;
            @exec('which pdftotext 2>&1', $output, $returnVar);
            $available = ($returnVar === 0 && !empty($output));
        }

        return $available;
    }

    /**
     * Extrai texto de PDF usando pdftotext (ferramenta de linha de comando)
     */
    private function extractTextWithPdftotext(string $filePath): string
    {
        // Cria arquivo temporário para o texto extraído
        $tempFile = tempnam(sys_get_temp_dir(), 'pdf_text_') . '.txt';

        // Executa pdftotext
        $command = escapeshellarg('pdftotext') . ' -layout ' . escapeshellarg($filePath) . ' ' . escapeshellarg($tempFile) . ' 2>&1';
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);

        if ($returnVar !== 0 || !file_exists($tempFile)) {
            Log::warning("Erro ao executar pdftotext: " . implode("\n", $output));
            @unlink($tempFile);
            throw new \RuntimeException("Erro ao extrair texto com pdftotext");
        }

        // Lê o texto extraído
        $text = file_get_contents($tempFile);
        @unlink($tempFile);

        if ($text === false) {
            throw new \RuntimeException("Erro ao ler texto extraído do PDF");
        }

        return $text;
    }

    /**
     * Extrai texto de PDF usando smalot/pdfparser diretamente.
     */
    private function extractPdfTextWithParser(string $filePath): string
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($filePath);

        return $pdf->getText();
    }

    /**
     * Extrai transações de um arquivo PDF (Mercado Pago/Santander)
     */
public function parsePdf(string $filePath): array
    {
        $transactions = [];

        try {
            $fullPath = storage_path('app/' . $filePath);
            if (!file_exists($fullPath)) {
                $fullPath = Storage::path($filePath);
            }
            if (!file_exists($fullPath)) {
                $fullPath = $filePath;
            }

            if (!file_exists($fullPath)) {
                throw new \RuntimeException("Arquivo não encontrado: {$filePath}");
            }

            Log::info("Processando PDF: {$fullPath}");

            // Extrai texto primeiro para identificar layouts específicos sem depender de OCR.
            $text = '';
            try {
                $text = $this->extractPdfText($fullPath);
            } catch (\Exception $e) {
                Log::warning("Não foi possível extrair texto do PDF antes do OCR: " . $e->getMessage());
            }

            $invoiceYear = !empty($text) ? $this->extractInvoiceYear($text) : (int)date('Y');
            Log::info("Ano da fatura extraído: " . ($invoiceYear ?? 'não encontrado'));

            if (!empty($text) && $this->isSantanderPdf($text)) {
                Log::info("Layout Santander detectado, usando parser dedicado");
                $santanderText = $text;

                try {
                    $santanderText = $this->extractPdfTextWithParser($fullPath);
                } catch (\Exception $e) {
                    Log::warning("Falha ao extrair texto do Santander com pdfparser, usando texto já extraído: " . $e->getMessage());
                }

                $invoiceYear = $this->extractInvoiceYear($santanderText);
                $transactions = $this->removeDuplicateTransactions(
                    $this->parseSantanderPdfText($santanderText, $invoiceYear)
                );

                Log::info("Total de transações extraídas do Santander: " . count($transactions));

                if (!empty($transactions)) {
                    return $transactions;
                }

                Log::warning("Parser do Santander não encontrou transações, seguindo para fallback");
            }

            // Tenta usar Python OCR primeiro (mais preciso)
            if ($this->isPythonOcrAvailable()) {
                Log::info("Tentando usar Python OCR para extrair transações");
                try {
                    $pythonTransactions = $this->parsePdfWithPython($fullPath);
                    if (!empty($pythonTransactions)) {
                        Log::info("Python OCR extraiu " . count($pythonTransactions) . " transações");
                        return $pythonTransactions;
                    }
                } catch (\Exception $e) {
                    Log::warning("Erro ao usar Python OCR, usando fallback: " . $e->getMessage());
                }
            }

            // Fallback: extrai texto usando pdftotext ou smalot/pdfparser
            if (empty($text)) {
                $text = $this->extractPdfText($fullPath);
                $invoiceYear = $this->extractInvoiceYear($text);
                Log::info("Ano da fatura extraído no fallback: " . ($invoiceYear ?? 'não encontrado'));
            }

            // Procura por seções de transações (pode haver múltiplas tabelas)
            // Processa todas as seções "Cartão Visa" que contêm as transações
            $allTransactions = [];
            $processedPositions = [];
            $billingDate = $this->extractBillingPeriodEndDate($text, $invoiceYear);

            // Encontra todas as ocorrências de "Cartão Visa"
            $pos = 0;
            while (($pos = stripos($text, 'Cartão Visa', $pos)) !== false) {
                // Verifica se já processou uma seção próxima (evita duplicatas)
                $isDuplicate = false;
                foreach ($processedPositions as $processedPos) {
                    if (abs($pos - $processedPos) < 200) {
                        $isDuplicate = true;
                        break;
                    }
                }

                if (!$isDuplicate) {
                    $processedPositions[] = $pos;
                    Log::info("Seção 'Cartão Visa' encontrada na posição: {$pos}");
                    $sectionTransactions = $this->extractTransactionsFromSection(
                        $text,
                        $pos,
                        $invoiceYear,
                        $billingDate
                    );
                    $allTransactions = array_merge($allTransactions, $sectionTransactions);
                    Log::info("Transações extraídas desta seção: " . count($sectionTransactions));
                }

                $pos += 11; // Avança para próxima ocorrência
            }

            // Se não encontrou seções "Cartão Visa", tenta outros padrões
            if (empty($allTransactions)) {
                $sectionPatterns = [
                    '/Movimenta[çc][õo]es na fatura/i',
                    '/Detalhes de consumo/i',
                    '/Movimenta[çc][õo]es/i',
                ];

                foreach ($sectionPatterns as $pattern) {
                    if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                        $sectionPos = $matches[0][1];

                        $isDuplicate = false;
                        foreach ($processedPositions as $processedPos) {
                            if (abs($sectionPos - $processedPos) < 100) {
                                $isDuplicate = true;
                                break;
                            }
                        }

                        if (!$isDuplicate) {
                            $processedPositions[] = $sectionPos;
                            Log::info("Seção encontrada na posição: {$sectionPos}");
                            $sectionTransactions = $this->extractTransactionsFromSection(
                                $text,
                                $sectionPos,
                                $invoiceYear,
                                $billingDate
                            );
                            $allTransactions = array_merge($allTransactions, $sectionTransactions);
                            Log::info("Transações extraídas desta seção: " . count($sectionTransactions));
                        }
                    }
                }
            }

            // Remove duplicatas baseado em data + descrição + valor
            $transactions = $this->removeDuplicateTransactions($allTransactions);

            Log::info("Total de transações extraídas: " . count($transactions));

        } catch (\Exception $e) {
            Log::error('Erro ao processar PDF: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \RuntimeException('Erro ao processar arquivo PDF: ' . $e->getMessage());
        }

        return $transactions;
    }

    /**
     * Extrai o ano da fatura do texto do PDF
     */
    private function extractInvoiceYear(string $text): ?int
    {
        // Tenta encontrar o período da fatura (ex: "Consumos de 30/11 a 29/12")
        if (preg_match('/Consumos de \d{2}\/\d{2}\/(\d{4})/', $text, $matches)) {
            return (int)$matches[1];
        }

        // Tenta encontrar data de vencimento (ex: "Vence em 05/01/2026")
        if (preg_match('/Vence em \d{2}\/\d{2}\/(\d{4})/', $text, $matches)) {
            return (int)$matches[1];
        }

        // Tenta encontrar data de emissão (ex: "Emitido em: 30/12/2025")
        if (preg_match('/Emitido em[:\s]+\d{2}\/\d{2}\/(\d{4})/', $text, $matches)) {
            return (int)$matches[1];
        }

        // Tenta encontrar ano em qualquer data completa
        if (preg_match('/\d{2}\/\d{2}\/(\d{4})/', $text, $matches)) {
            $year = (int)$matches[1];
            // Valida se é um ano razoável (entre 2020 e 2030)
            if ($year >= 2020 && $year <= 2030) {
                return $year;
            }
        }

        // Se não encontrou, usa o ano atual
        return (int)date('Y');
    }

    /**
     * Detecta se o PDF é uma fatura/cartão do Santander.
     */
    private function isSantanderPdf(string $text): bool
    {
        return stripos($text, 'Santander') !== false
            && stripos($text, 'Detalhamento da Fatura') !== false;
    }

    /**
     * Extrai a data de fechamento da fatura (quando as parcelas entram na conta).
     * Usada para compras parceladas em vez da data da compra.
     */
    private function extractBillingPeriodEndDate(string $text, ?int $invoiceYear): ?string
    {
        $year = $invoiceYear ?? (int)date('Y');

        // Santander: "Esta Fatura 10/02/26 a 11/03/26" (prioriza período da fatura atual)
        if (preg_match('/[Ee]sta\s+[Ff]atura\s+\d{2}\/\d{2}\/\d{2}\s+a\s+(\d{2})\/(\d{2})\/(\d{2})/', $text, $m)) {
            $day = (int)$m[1];
            $month = (int)$m[2];
            $y = (int)$m[3];
            $year = $y >= 0 && $y <= 99 ? 2000 + $y : $y;
            if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                return $this->parseDate(sprintf('%02d/%02d/%04d', $day, $month, $year));
            }
        }

        // Santander/Mercado Pago: "10/02/26 a 11/03/26" - pega a última ocorrência (fatura atual)
        if (preg_match_all('/\d{2}\/\d{2}(?:\/\d{2})?\s+a\s+(\d{2})\/(\d{2})(?:\/(\d{2}))?/', $text, $allMatches, PREG_SET_ORDER)) {
            $m = end($allMatches);
            $day = (int)$m[1];
            $month = (int)$m[2];
            $y = isset($m[3]) ? (int)$m[3] : null;
            if ($y !== null) {
                $year = $y >= 0 && $y <= 99 ? 2000 + $y : $y;
            }
            if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                $date = $this->parseDate(sprintf('%02d/%02d/%04d', $day, $month, $year));
                return $date;
            }
        }

        // Mercado Pago: "Consumos de 30/11 a 29/12"
        if (preg_match('/[Cc]onsumos de \d{2}\/\d{2}\s+a\s+(\d{2})\/(\d{2})/', $text, $m)) {
            $day = (int)$m[1];
            $month = (int)$m[2];
            if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                $date = $this->parseDate(sprintf('%02d/%02d/%04d', $day, $month, $year));
                return $date;
            }
        }

        return null;
    }

    /**
     * Extrai o mês de referência da fatura para resolver virada de ano.
     */
    private function extractInvoiceMonth(string $text): ?int
    {
        $patterns = [
            '/Vencimento\s+(\d{2})\/(\d{2})\/(\d{4})/i',
            '/Vence(?:\s+em)?\s+(\d{2})\/(\d{2})\/(\d{4})/i',
            '/Data Documento\s+(\d{2})\/(\d{2})\/(\d{4})/i',
            '/\b(\d{2})\/(\d{2})\/(\d{4})\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $month = (int)$matches[2];
                if ($month >= 1 && $month <= 12) {
                    return $month;
                }
            }
        }

        return null;
    }

    /**
     * Extrai transações do layout de fatura do Santander.
     */
    private function parseSantanderPdfText(string $text, ?int $invoiceYear): array
    {
        $transactions = [];
        $invoiceMonth = $this->extractInvoiceMonth($text);
        $billingDate = $this->extractBillingPeriodEndDate($text, $invoiceYear);
        $lines = preg_split('/\R/u', $text) ?: [];
        $insideDetails = false;

        foreach ($lines as $rawLine) {
            $line = trim(preg_replace('/\s+/', ' ', $rawLine));

            if ($line === '') {
                continue;
            }

            if (!$insideDetails) {
                if (stripos($line, 'Detalhamento da Fatura') !== false) {
                    $insideDetails = true;
                }
                continue;
            }

            if (
                stripos($line, 'Resumo da Fatura') !== false ||
                stripos($line, 'Juros e Custo Efetivo Total') !== false
            ) {
                break;
            }

            $transaction = $this->parseSantanderTransactionLine($line, $invoiceYear, $invoiceMonth, $billingDate);
            if ($transaction !== null) {
                $transactions[] = $transaction;
            }
        }

        return $transactions;
    }

    /**
     * Parseia uma linha do detalhamento da fatura Santander.
     * Para compras parceladas, usa a data do fechamento da fatura (quando a parcela entra na conta).
     */
    private function parseSantanderTransactionLine(string $line, ?int $invoiceYear, ?int $invoiceMonth, ?string $billingDate): ?array
    {
        $line = trim(preg_replace('/\s+/', ' ', $line));

        if (!preg_match('/^(?:\d+\s+)?(\d{2}\/\d{2})\s*(.+?)\s+(?:(\d{1,2}\/\d{1,2})\s+)?(-?\d{1,3}(?:\.\d{3})*,\d{2})$/u', $line, $matches)) {
            return null;
        }

        $dateStr = $matches[1];
        $description = trim($matches[2]);
        $installment = $matches[3] ?? null;
        $amount = $this->parseAmount($matches[4]);

        if ($amount === null || $amount <= 0) {
            return null;
        }

        $ignorePatterns = [
            'PAGAMENTO DE FATURA',
            'VALOR TOTAL',
            'Pagamento e Demais Créditos',
            'Compra Data Descrição',
        ];

        foreach ($ignorePatterns as $pattern) {
            if (stripos($description, $pattern) !== false) {
                return null;
            }
        }

        if ($installment) {
            $description .= ' Parcela ' . $installment;
        }

        // Compras parceladas: usa data do fechamento da fatura (quando a parcela entra na conta)
        if ($installment && $billingDate) {
            $date = $billingDate;
        } else {
            $month = (int)substr($dateStr, 3, 2);
            $year = $invoiceYear ?? (int)date('Y');

            if ($invoiceMonth !== null) {
                if ($month > $invoiceMonth) {
                    $year--;
                }
            } else {
                $currentMonth = (int)date('m');
                if ($month > $currentMonth) {
                    $year--;
                }
            }

            $date = $this->parseDate("{$dateStr}/{$year}");
        }

        if (!$date) {
            return null;
        }

        return [
            'date' => $date,
            'description' => $this->cleanDescription($description),
            'amount' => abs($amount),
        ];
    }

    /**
     * Extrai transações de uma seção específica do PDF (Mercado Pago).
     * Para compras parceladas, usa a data do fechamento da fatura.
     */
    private function extractTransactionsFromSection(string $text, int $sectionPos, ?int $invoiceYear, ?string $billingDate = null): array
    {
        $transactions = [];

        // Extrai o texto a partir da seção (pega próximo 5000 caracteres ou até próxima seção)
        $sectionText = substr($text, $sectionPos, 5000);

        // Para na próxima seção "Cartão Visa" (mas não na primeira ocorrência que é o próprio cabeçalho)
        // ou "Total" para evitar pegar transações de outras seções
        $nextSection = stripos($sectionText, 'Cartão Visa', 500);
        $totalPos = stripos($sectionText, 'TotalR$');
        $totalPos2 = stripos($sectionText, 'Total R$');

        $endPos = false;
        if ($nextSection !== false && $nextSection > 500) {
            $endPos = $nextSection;
        }
        if ($totalPos !== false && ($endPos === false || $totalPos < $endPos)) {
            $endPos = $totalPos;
        }
        if ($totalPos2 !== false && ($endPos === false || $totalPos2 < $endPos)) {
            $endPos = $totalPos2;
        }

        if ($endPos !== false) {
            $sectionText = substr($sectionText, 0, $endPos);
        }

        // Normaliza o texto (substitui caracteres corrompidos comuns)
        $sectionText = $this->normalizePdfText($sectionText);

        Log::info("Texto da seção normalizado (primeiros 500 chars): " . substr($sectionText, 0, 500));

        // Abordagem: encontra todas as datas DD/MM (incluindo corrompidas como 2M/11) e processa cada uma individualmente
        // Aceita também datas corrompidas como "2M/11" (deve ser "24/11")
        // Mas garante que tem pelo menos 1 dígito antes e 2 dígitos depois da barra
        preg_match_all('/(\d{1,2}[M\d%]\/\d{2})/', $sectionText, $dateMatches, PREG_OFFSET_CAPTURE);

        // Filtra datas inválidas (que não têm formato correto)
        $validDateMatches = [];
        foreach ($dateMatches[0] as $idx => $match) {
            $dateStr = $dateMatches[1][$idx][0];
            // Verifica se tem formato válido (pelo menos 1 dígito antes e 2 depois da barra)
            if (preg_match('/^\d{1,2}[M\d%]\/\d{2}$/', $dateStr)) {
                $validDateMatches[] = ['match' => $match, 'date' => $dateStr, 'pos' => $match[1]];
            }
        }

        // Reorganiza para usar apenas datas válidas
        $dateMatches = [0 => [], 1 => []];
        foreach ($validDateMatches as $valid) {
            $dateMatches[0][] = [$valid['match'][0], $valid['pos']];
            $dateMatches[1][] = [$valid['date'], $valid['pos']];
        }

        $matches = [];
        $seen = [];

        for ($i = 0; $i < count($dateMatches[0]); $i++) {
            $datePos = $dateMatches[0][$i][1];
            $dateStr = $dateMatches[1][$i][0];

            // Pega texto até próxima data ou até 400 caracteres (para capturar descrições longas e valores em linhas diferentes)
            $nextDatePos = isset($dateMatches[0][$i + 1]) ? $dateMatches[0][$i + 1][1] : strlen($sectionText);
            $lineText = substr($sectionText, $datePos, min($nextDatePos - $datePos, 400));

            // Normaliza esta linha específica antes de procurar valores
            $lineText = $this->normalizePdfText($lineText);

            // Procura por R$ ou R4 seguido de valor nesta linha (valores já normalizados)
            // Aceita também valores corrompidos que serão normalizados depois
            // Tenta múltiplos padrões para capturar diferentes formatos
            $amountMatch = null;
            $amountPos = -1;

            // Padrão 1: R$ seguido de valor formatado (X.XXX,XX ou XXX,XX)
            if (preg_match('/R[\$4]\s+([\d]{1,3}(?:\.[\d]{3})*,\d{2})/', $lineText, $m, PREG_OFFSET_CAPTURE)) {
                $amountMatch = $m;
                $amountPos = $m[0][1];
            }
            // Padrão 2: R$ seguido de valor simples (XXX,XX)
            elseif (preg_match('/R[\$4]\s+([\d]+,\d{2})/', $lineText, $m, PREG_OFFSET_CAPTURE)) {
                $amountMatch = $m;
                $amountPos = $m[0][1];
            }
            // Padrão 3: R$ seguido de valor corrompido (aceita caracteres especiais)
            elseif (preg_match('/R[\$4]\s+([\d\s\.,%í\)JMóõ]{3,20})/', $lineText, $m, PREG_OFFSET_CAPTURE)) {
                $amountMatch = $m;
                $amountPos = $m[0][1];
            }
            // Padrão 4: R$ seguido de qualquer coisa que pareça um número (último recurso)
            elseif (preg_match('/R[\$4]\s+([^\s]{2,15})/', $lineText, $m, PREG_OFFSET_CAPTURE)) {
                // Verifica se parece um valor (tem números)
                if (preg_match('/[\d]/', $m[1][0])) {
                    $amountMatch = $m;
                    $amountPos = $m[0][1];
                }
            }

            if ($amountMatch) {

                $amountStr = $amountMatch[1][0];
                $amountStart = $amountMatch[0][1];

                // Extrai descrição (tudo entre data e R$ ou R4)
                $description = substr($lineText, strlen($dateStr), $amountStart - strlen($dateStr));
                $description = trim($description);

                // Remove "Parcela X de Y" da descrição principal (será adicionado depois)
                $description = preg_replace('/\s*Parcela\s+\d+\s+de\s+\d+\s*/i', ' ', $description);
                $description = trim($description);

                // Extrai parcela se existir (pode estar antes ou depois da descrição)
                $parcelaNum = null;
                $parcelaTotal = null;
                // Procura parcela na linha completa (não só na descrição)
                if (preg_match('/Parcela\s+(\d+)\s+de\s+(\d+)/', $lineText, $parcelaMatch)) {
                    $parcelaNum = $parcelaMatch[1];
                    $parcelaTotal = $parcelaMatch[2];
                    // Remove parcela da descrição se estiver lá
                    $description = preg_replace('/\s*Parcela\s+\d+\s+de\s+\d+\s*/i', ' ', $description);
                    $description = trim($description);
                }

                // Valida descrição básica
                if (strlen($description) >= 3) {
                    $key = $dateStr . '|' . substr($description, 0, 40) . '|' . $amountStr;
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $matches[] = [
                            1 => $dateStr,
                            2 => $description,
                            3 => $parcelaNum,
                            4 => $parcelaTotal,
                            5 => 'R$ ' . $amountStr, // Adiciona R$ para normalização
                        ];
                    }
                }
            }
        }

        Log::info("Padrão regex encontrou " . count($matches) . " possíveis transações");

        foreach ($matches as $match) {
            $dateStr = $match[1];
            $description = trim($match[2]);
            $parcelaNum = isset($match[3]) ? $match[3] : null;
            $parcelaTotal = isset($match[4]) ? $match[4] : null;
            $amountStr = $match[5];

            // Normaliza data corrompida (ex: "2M/11" -> "24/11", "0M/12" -> "04/12", "0%/12" -> "09/12")
            // Primeiro normaliza caracteres corrompidos
            $dateStr = str_replace(['M', '%'], ['4', '9'], $dateStr);

            // Corrige padrões específicos conhecidos
            $dateStr = preg_replace('/(\d)4\//', '$14/', $dateStr); // 24/11 (já corrigido)
            $dateStr = preg_replace('/(\d)9\//', '$19/', $dateStr); // 09/12 (já corrigido)
            $dateStr = preg_replace('/^04\//', '04/', $dateStr); // 04/12
            $dateStr = preg_replace('/^09\//', '09/', $dateStr); // 09/12

            // Garante formato DD/MM
            if (!preg_match('/^\d{2}\/\d{2}$/', $dateStr)) {
                // Se tem apenas 1 dígito antes da barra, adiciona zero à esquerda
                if (preg_match('/^(\d)\/(\d{2})$/', $dateStr, $dMatch)) {
                    $dateStr = '0' . $dMatch[1] . '/' . $dMatch[2];
                } else {
                    // Se não conseguiu normalizar, pula esta transação
                    Log::warning("Data não pôde ser normalizada: {$match[1]}");
                    continue;
                }
            }

            // Adiciona informação de parcela se existir
            if ($parcelaNum && $parcelaTotal) {
                $description .= ' Parcela ' . $parcelaNum . ' de ' . $parcelaTotal;
            }

            // Limpa a descrição de caracteres inválidos, mas mantém alguns caracteres especiais comuns
            $description = preg_replace('/[^A-Za-z0-9\s\*\-\.]/', ' ', $description);
            $description = preg_replace('/\s+/', ' ', trim($description));

            // Corrige descrições conhecidas corrompidas do pdftotext
            $descriptionReplacements = [
                'L y lipaG b Z NFUE LB' => 'DL *Alipay MAGAZINE LU',
                'ELLO QOBTF BE E bO' => 'ELLO BOUTIQUE E MODA',
                'bERC OLFVREybERC OLFVRE' => 'MERCADOLIVRE*MERCADOLIVRE',
                'bERC OLFVREy' => 'MERCADOLIVRE*',
                'y bERC OLFVRE' => '*MERCADOLIVRE',
                'FZFT LOCE UJCOb' => 'DIGITALOCEAN.COM',
                'PZ yUBVEb RFSTOCR CKC' => 'PG *NUVEM ARISTOCRACYC',
                'bPybELFb FS' => 'MP*MELIMAIS',
                'bERC OLFVREy3PRO BTOS' => 'MERCADOLIVRE*3PRODUTOS',
                'MERCADOLIVRE*3PRO BTOS' => 'MERCADOLIVRE*3PRODUTOS',
                'bERC OLFVREy8 bFLEb HE' => 'MERCADOLIVRE*JAMILEMAKE',
                'MERCADOLIVRE*8 bFLEb HE' => 'MERCADOLIVRE*JAMILEMAKE',
                'bERC OLFVREyESCBT OVEFO' => 'MERCADOLIVRE*ESCUTAOVEIO',
                'MERCADOLIVRE*ESCBT OVEFO' => 'MERCADOLIVRE*ESCUTAOVEIO',
                'PZ yLO8 PHTSWOP' => 'PG *LOJA PKTSHOP',
                'CBRSOR F PO ERE F E' => 'CURSOR, AI POWERED IDE',
                'bPyLO8 VESTFS' => 'MP*LOJAVESTIS',
                'SERVFCOS CL yCL R' => 'SERVICOS CLA*CLAR',
                '8FbJCOb' => 'JIM.COM',
                'bERC OLFVREy L SWCObPR S' => 'MERCADOLIVRE*FLASHCOMPRAS',
                'MERCADOLIVRE* L SWCObPR S' => 'MERCADOLIVRE*FLASHCOMPRAS',
                'bERCE RF b TWEBS' => 'MERCEARIA MATHEUS',
                'SWOPEE yUorthStar' => 'SHOPEE *NorthStar',
                'SWOPEE yQO b FS' => 'SHOPEE *BOAMAIS',
                'õó FTFy' => 'DAFITI',
                'õó FTFyM' => 'DAFITI',
                'õó+FTFy' => 'DAFITI',
            ];

            foreach ($descriptionReplacements as $corrupted => $correct) {
                $description = str_replace($corrupted, $correct, $description);
            }

            // Corrige padrões comuns com regex mais flexível
            $description = preg_replace('/\bL\s+y\s+lipaG\s+b\s+Z\s+NFUE\s+LB\b/i', 'DL *Alipay MAGAZINE LU', $description);
            $description = preg_replace('/\bbERC\s+OLFVRE\b/i', 'MERCADOLIVRE', $description);
            $description = preg_replace('/\bPZ\s+yUBVEb\b/i', 'PG', $description);
            $description = preg_replace('/\bFZFT\s+LOCE\s+UJCOb\b/i', 'DIGITALOCEAN.COM', $description);
            $description = preg_replace('/\bMERCADOLIVRE\*MERCADOLIVRE\s+M\b/i', 'MERCADOLIVRE*MERCADOLIVRE', $description);

            // Normaliza o valor antes de parsear (corrige caracteres corrompidos)
            // Remove R$ se já estiver presente
            $amountStr = str_replace('R$ ', '', $amountStr);
            $normalizedAmount = str_replace(['J', '%', 'í', ')', 'M', 'ó', 'õ'], ['9', '5', '7', '0', '3', '6', '0'], $amountStr);
            // Parse do valor
            $amount = $this->parseAmount('R$ ' . $normalizedAmount);

            // Compras parceladas: usa data do fechamento da fatura (quando a parcela entra na conta)
            if ($parcelaNum && $parcelaTotal && $billingDate) {
                $date = $billingDate;
            } else {
                // Determina o ano baseado no mês da data
                $month = (int)substr($dateStr, 3, 2);
                $year = $invoiceYear ?? (int)date('Y');

                // Se a data é de um mês futuro em relação ao mês atual, provavelmente é do ano anterior
                $currentMonth = (int)date('m');
                if ($month > $currentMonth && $month <= 12) {
                    $year = $year - 1;
                }

                $date = $this->parseDate("{$dateStr}/{$year}");
            }

            // Validações
            if (!$date) {
                Log::warning("Data inválida: {$dateStr}/{$year}");
                continue;
            }

            if (strlen($description) < 3) {
                Log::warning("Descrição muito curta: {$description}");
                continue;
            }

            if ($amount === null || $amount <= 0 || $amount > 100000) {
                Log::warning("Valor inválido: {$amountStr} -> {$amount}");
                continue;
            }

            // Ignora se for pagamento de fatura, crédito ou outras informações não-transacionais
            $ignorePatterns = [
                'Pagamento da fatura',
                'Crédito concedido',
                'Parcelamento de fatura',
                'Limite do cart',
                'VocD pode',
                'primeira parcela deve',
                'Limite utili',
            ];

            foreach ($ignorePatterns as $pattern) {
                if (stripos($description, $pattern) !== false) {
                    Log::info("Ignorando transação (padrão ignorado): {$description}");
                    continue 2; // Continua para próxima transação
                }
            }

            $transactions[] = [
                'date' => $date,
                'description' => $this->cleanDescription($description),
                'amount' => abs($amount),
            ];

            Log::info("Transação extraída: {$date} - {$description} - R$ " . number_format($amount, 2, ',', '.'));
        }

        Log::info("Transações encontradas com regex direto: " . count($transactions));

        return $transactions;
    }

    /**
     * Normaliza texto extraído do PDF para lidar com corrupção de caracteres
     */
    private function normalizePdfText(string $text): string
    {
        // Normaliza R4 para R$ (corrupção comum)
        $text = preg_replace('/R4\s*/', 'R$ ', $text);

        // Remove cabeçalhos de tabela corrompidos
        $text = str_replace('õatabovimentaçzes', ' ', $text);
        $text = str_replace('Valor em R$', ' ', $text);
        $text = str_replace('Valor em R', ' ', $text);

        // Normaliza valores monetários corrompidos ANTES de processar
        // Padrão mais amplo: R$ seguido de qualquer coisa que pareça um valor
        $text = preg_replace_callback('/R\$\s*([^\s]{2,20})/', function($m) {
            $value = $m[1];

            // Mapeia caracteres corrompidos comuns para dígitos
            $value = str_replace(['J', '%', 'í', ')', 'M', 'ó', 'õ'], ['9', '5', '7', '0', '3', '6', '0'], $value);

            // Remove caracteres não numéricos exceto vírgula e ponto
            $cleaned = preg_replace('/[^0-9,.]/', '', $value);

            // Se ficou vazio, retorna original
            if (empty($cleaned)) {
                return $m[0];
            }

            // Se não tem vírgula nem ponto, assume que os últimos 2 dígitos são centavos
            if (strpos($cleaned, ',') === false && strpos($cleaned, '.') === false) {
                if (strlen($cleaned) >= 2) {
                    $cleaned = substr($cleaned, 0, -2) . ',' . substr($cleaned, -2);
                } else {
                    $cleaned = '0,' . str_pad($cleaned, 2, '0', STR_PAD_LEFT);
                }
            }

            // Se tem ponto mas não vírgula, converte para formato brasileiro
            if (strpos($cleaned, '.') !== false && strpos($cleaned, ',') === false) {
                $parts = explode('.', $cleaned);
                if (count($parts) == 2 && strlen($parts[1]) == 2) {
                    $cleaned = $parts[0] . ',' . $parts[1];
                } elseif (count($parts) == 2) {
                    // Pode ser formato americano com milhar
                    $cleaned = implode('', $parts);
                    if (strlen($cleaned) >= 2) {
                        $cleaned = substr($cleaned, 0, -2) . ',' . substr($cleaned, -2);
                    }
                }
            }

            // Garante que tem vírgula e 2 dígitos após
            if (strpos($cleaned, ',') !== false) {
                $parts = explode(',', $cleaned);
                if (count($parts) == 2) {
                    $parts[1] = substr($parts[1], 0, 2); // Limita a 2 dígitos
                    $parts[1] = str_pad($parts[1], 2, '0', STR_PAD_RIGHT);
                    $cleaned = $parts[0] . ',' . $parts[1];
                }
            } else {
                // Se não tem vírgula, adiciona
                if (strlen($cleaned) >= 2) {
                    $cleaned = substr($cleaned, 0, -2) . ',' . substr($cleaned, -2);
                } else {
                    $cleaned = '0,' . str_pad($cleaned, 2, '0', STR_PAD_LEFT);
                }
            }

            return 'R$ ' . $cleaned;
        }, $text);

        // Remove múltiplos espaços mas mantém estrutura básica
        $text = preg_replace('/\s+/', ' ', $text);

        return $text;
    }

    /**
     * Remove transações duplicadas
     */
    private function removeDuplicateTransactions(array $transactions): array
    {
        $unique = [];
        $seen = [];

        foreach ($transactions as $transaction) {
            $key = $transaction['date'] . '|' . $transaction['description'] . '|' . $transaction['amount'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $transaction;
            }
        }

        return $unique;
    }

    /**
     * Parseia uma linha do PDF do Mercado Pago
     */
    private function parsePdfLine(string $line, ?int $invoiceYear = null): ?array
    {
        // Remove múltiplos espaços
        $line = preg_replace('/\s+/', ' ', trim($line));

        // Ignora linhas que são claramente cabeçalhos ou totais
        if (preg_match('/[Dd]ata.*Movimenta[çc][õo]es/i', $line)) {
            return null;
        }
        if (preg_match('/^[Tt]otal/i', $line) && preg_match('/R[\$4]/', $line) && count(explode(' ', $line)) < 5) {
            return null;
        }

        // Normaliza R4 para R$ (corrupção comum no PDF)
        $line = preg_replace('/R4\s*/', 'R$ ', $line);

        // Padrão 1: DD/MM/YYYY Descrição R$ X.XXX,XX
        $pattern1 = '/^(\d{2}\/\d{2}\/\d{4})\s+(.+?)\s+(R\$\s*[\d.,]+)$/i';
        if (preg_match($pattern1, $line, $matches)) {
            $date = $this->parseDate($matches[1]);
            $description = trim($matches[2]);
            $amount = $this->parseAmount($matches[3]);

            if ($date && $description && $amount !== null && $amount > 0) {
                return [
                    'date' => $date,
                    'description' => $this->cleanDescription($description),
                    'amount' => abs($amount),
                ];
            }
        }

        // Padrão 2: DD/MM Descrição Parcela X de Y R$ X.XXX,XX (formato Mercado Pago)
        // Aceita parcela antes ou depois do valor
        $pattern2a = '/^(\d{2}\/\d{2})\s+(.+?)\s+Parcela\s+(\d+)\s+de\s+(\d+)\s+(R\$\s*[\d.,]+)$/i';
        if (preg_match($pattern2a, $line, $matches)) {
            $dateStr = $matches[1];
            $description = trim($matches[2]) . ' Parcela ' . $matches[3] . ' de ' . $matches[4];
            $amount = $this->parseAmount($matches[5]);

            if ($invoiceYear) {
                $date = $this->parseDate("{$dateStr}/{$invoiceYear}");
            } else {
                $date = $this->parseDate("{$dateStr}/" . date('Y'));
            }

            if ($date && $amount !== null && $amount > 0) {
                return [
                    'date' => $date,
                    'description' => $this->cleanDescription($description),
                    'amount' => abs($amount),
                ];
            }
        }

        // Padrão 2b: DD/MM Descrição R$ X.XXX,XX Parcela X de Y
        $pattern2b = '/^(\d{2}\/\d{2})\s+(.+?)\s+(R\$\s*[\d.,]+)\s+Parcela\s+(\d+)\s+de\s+(\d+)$/i';
        if (preg_match($pattern2b, $line, $matches)) {
            $dateStr = $matches[1];
            $description = trim($matches[2]) . ' Parcela ' . $matches[4] . ' de ' . $matches[5];
            $amount = $this->parseAmount($matches[3]);

            if ($invoiceYear) {
                $date = $this->parseDate("{$dateStr}/{$invoiceYear}");
            } else {
                $date = $this->parseDate("{$dateStr}/" . date('Y'));
            }

            if ($date && $amount !== null && $amount > 0) {
                return [
                    'date' => $date,
                    'description' => $this->cleanDescription($description),
                    'amount' => abs($amount),
                ];
            }
        }

        // Padrão 3: DD/MM Descrição R$ X.XXX,XX (sem parcela)
        $pattern3 = '/^(\d{2}\/\d{2})\s+(.+?)\s+(R\$\s*[\d.,]+)$/i';
        if (preg_match($pattern3, $line, $matches)) {
            $dateStr = $matches[1];
            $description = trim($matches[2]);
            $amount = $this->parseAmount($matches[3]);

            // Completa a data com o ano da fatura
            if ($invoiceYear) {
                $date = $this->parseDate("{$dateStr}/{$invoiceYear}");
            } else {
                $date = $this->parseDate("{$dateStr}/" . date('Y'));
            }

            if ($date && $description && $amount !== null && $amount > 0) {
                return [
                    'date' => $date,
                    'description' => $this->cleanDescription($description),
                    'amount' => abs($amount),
                ];
            }
        }

        // Padrão 4: DD/MM Descrição Parcela X de Y Valor (sem R$ explícito)
        $pattern4a = '/^(\d{2}\/\d{2})\s+(.+?)\s+Parcela\s+(\d+)\s+de\s+(\d+)\s+([\d.,]+)$/';
        if (preg_match($pattern4a, $line, $matches)) {
            $dateStr = $matches[1];
            $description = trim($matches[2]) . ' Parcela ' . $matches[3] . ' de ' . $matches[4];
            $amount = $this->parseAmount($matches[5]);

            if ($invoiceYear) {
                $date = $this->parseDate("{$dateStr}/{$invoiceYear}");
            } else {
                $date = $this->parseDate("{$dateStr}/" . date('Y'));
            }

            if ($date && $amount !== null && $amount > 0.01) {
                return [
                    'date' => $date,
                    'description' => $this->cleanDescription($description),
                    'amount' => abs($amount),
                ];
            }
        }

        // Padrão 4b: DD/MM Descrição Valor Parcela X de Y
        $pattern4b = '/^(\d{2}\/\d{2})\s+(.+?)\s+([\d.,]+)\s+Parcela\s+(\d+)\s+de\s+(\d+)$/';
        if (preg_match($pattern4b, $line, $matches)) {
            $dateStr = $matches[1];
            $description = trim($matches[2]) . ' Parcela ' . $matches[4] . ' de ' . $matches[5];
            $amount = $this->parseAmount($matches[3]);

            if ($invoiceYear) {
                $date = $this->parseDate("{$dateStr}/{$invoiceYear}");
            } else {
                $date = $this->parseDate("{$dateStr}/" . date('Y'));
            }

            if ($date && $amount !== null && $amount > 0.01) {
                return [
                    'date' => $date,
                    'description' => $this->cleanDescription($description),
                    'amount' => abs($amount),
                ];
            }
        }

        // Padrão 5: DD/MM Descrição Valor (sem R$, valor no final)
        // Só aceita se o valor for razoável (maior que 0.01 e menor que 100000)
        $pattern5 = '/^(\d{2}\/\d{2})\s+(.+?)\s+([\d.,]+)$/';
        if (preg_match($pattern5, $line, $matches)) {
            $dateStr = $matches[1];
            $description = trim($matches[2]);
            $amount = $this->parseAmount($matches[3]);

            // Verifica se o valor parece ser um valor monetário razoável
            if ($amount !== null && $amount > 0.01 && $amount < 100000) {
                // Verifica se a descrição não é muito curta (pode ser só um número)
                if (strlen($description) > 3) {
                    // Completa a data com o ano da fatura
                    if ($invoiceYear) {
                        $date = $this->parseDate("{$dateStr}/{$invoiceYear}");
                    } else {
                        $date = $this->parseDate("{$dateStr}/" . date('Y'));
                    }

                    if ($date) {
                        return [
                            'date' => $date,
                            'description' => $this->cleanDescription($description),
                            'amount' => abs($amount),
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Limpa e normaliza a descrição da transação
     */
    private function cleanDescription(string $description): string
    {
        // Remove múltiplos espaços
        $description = preg_replace('/\s+/', ' ', trim($description));

        // Remove informações redundantes no final
        $description = preg_replace('/\s+Cartão Visa.*$/i', '', $description);

        return $description;
    }

    /**
     * Extrai data do array normalizado
     */
    private function extractDate(array $normalized): ?string
    {
        $dateKeys = ['data', 'date', 'dia', 'vencimento'];

        foreach ($dateKeys as $key) {
            if (isset($normalized[$key]) && !empty($normalized[$key])) {
                $date = $this->parseDate($normalized[$key]);
                if ($date) {
                    return $date;
                }
            }
        }

        return null;
    }

    /**
     * Extrai descrição do array normalizado
     */
    private function extractDescription(array $normalized): ?string
    {
        $descKeys = ['title', 'descrição', 'descricao', 'description', 'desc', 'movimentações', 'movimentacoes', 'estabelecimento', 'nome'];

        foreach ($descKeys as $key) {
            if (isset($normalized[$key]) && !empty($normalized[$key])) {
                return $normalized[$key];
            }
        }

        return null;
    }

    /**
     * Extrai valor do array normalizado
     */
    private function extractAmount(array $normalized): ?float
    {
        $amountKeys = ['valor', 'value', 'amount', 'total', 'valor em r$'];

        foreach ($amountKeys as $key) {
            if (isset($normalized[$key]) && !empty($normalized[$key])) {
                $amount = $this->parseAmount($normalized[$key]);
                if ($amount !== null) {
                    return $amount;
                }
            }
        }

        return null;
    }

    /**
     * Converte string de data para formato Y-m-d
     */
    private function parseDate(string $dateString): ?string
    {
        // Remove espaços
        $dateString = trim($dateString);

        if (empty($dateString)) {
            return null;
        }

        // Tenta formato YYYY-MM-DD (formato ISO comum do Nubank)
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateString, $matches)) {
            $year = $matches[1];
            $month = $matches[2];
            $day = $matches[3];

            if (checkdate((int)$month, (int)$day, (int)$year)) {
                return $dateString;
            }
        }

        // Tenta formato DD/MM/YYYY
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dateString, $matches)) {
            $day = $matches[1];
            $month = $matches[2];
            $year = $matches[3];

            if (checkdate((int)$month, (int)$day, (int)$year)) {
                return sprintf('%s-%s-%s', $year, $month, $day);
            }
        }

        // Tenta formato DD-MM-YYYY
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $dateString, $matches)) {
            $day = $matches[1];
            $month = $matches[2];
            $year = $matches[3];

            if (checkdate((int)$month, (int)$day, (int)$year)) {
                return sprintf('%s-%s-%s', $year, $month, $day);
            }
        }

        // Tenta usar DateTime para parsing automático
        try {
            $date = new \DateTime($dateString);
            return $date->format('Y-m-d');
        } catch (\Exception $e) {
            Log::warning("Não foi possível parsear data: {$dateString}");
        }

        return null;
    }

    /**
     * Converte string de valor para float
     */
    private function parseAmount(string $amountString): ?float
    {
        if (empty($amountString)) {
            return null;
        }

        // Normaliza R4 para R$ (corrupção comum no PDF)
        $amountString = preg_replace('/R4/i', 'R$', $amountString);

        // Remove R$, espaços e outros caracteres não numéricos exceto sinal negativo, vírgula e ponto
        $amountString = preg_replace('/[R$\s]/', '', $amountString);

        // Detecta se é negativo
        $isNegative = (strpos($amountString, '-') !== false);
        $amountString = str_replace('-', '', $amountString);

        // Verifica se tem vírgula (formato brasileiro: 1.234,56)
        if (strpos($amountString, ',') !== false) {
            // Formato brasileiro: remove pontos de milhar e substitui vírgula por ponto
            $amountString = str_replace('.', '', $amountString);
            $amountString = str_replace(',', '.', $amountString);
        }

        $amount = filter_var($amountString, FILTER_VALIDATE_FLOAT);

        if ($amount === false) {
            Log::warning("Não foi possível parsear valor: {$amountString}");
            return null;
        }

        // Se era negativo, mantém negativo; senão retorna positivo
        // No Nubank, despesas são negativas, mas vamos converter para positivo já que são sempre despesas
        return abs((float)$amount);
    }

    /**
     * Verifica se Python OCR está disponível
     */
    private function isPythonOcrAvailable(): bool
    {
        static $available = null;

        if ($available === null) {
            // Verifica se Python 3 está disponível
            $output = [];
            $returnVar = 0;
            @exec('python3 --version 2>&1', $output, $returnVar);
            $hasPython = ($returnVar === 0 && !empty($output));

            // Verifica se o script Python existe
            $scriptPath = base_path('scripts/extract_pdf_transactions.py');
            $hasScript = file_exists($scriptPath);

            // Verifica se Tesseract está disponível
            @exec('tesseract --version 2>&1', $output2, $returnVar2);
            $hasTesseract = ($returnVar2 === 0);

            $available = $hasPython && $hasScript && $hasTesseract;

            Log::info("Python OCR disponível: " . ($available ? 'sim' : 'não'), [
                'has_python' => $hasPython,
                'has_script' => $hasScript,
                'has_tesseract' => $hasTesseract,
            ]);
        }

        return $available;
    }

    /**
     * Extrai transações usando script Python com OCR
     */
    private function parsePdfWithPython(string $filePath): array
    {
        $scriptPath = base_path('scripts/extract_pdf_transactions.py');

        if (!file_exists($scriptPath)) {
            throw new \RuntimeException("Script Python não encontrado: {$scriptPath}");
        }

        // Tenta usar ambiente virtual primeiro, depois python3 direto
        $venvPython = base_path('scripts/venv/bin/python');
        $pythonCmd = file_exists($venvPython) ? $venvPython : 'python3';

        // Executa script Python
        $command = escapeshellarg($pythonCmd) . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($filePath) . ' 2>&1';
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            $error = implode("\n", $output);
            Log::error("Erro ao executar script Python: {$error}");
            throw new \RuntimeException("Erro ao executar script Python: {$error}");
        }

        // Parse JSON retornado
        $jsonOutput = implode("\n", $output);
        $data = json_decode($jsonOutput, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("Erro ao parsear JSON do Python: " . json_last_error_msg());
            Log::error("Output: " . substr($jsonOutput, 0, 500));
            throw new \RuntimeException("Erro ao parsear JSON retornado pelo Python");
        }

        if (isset($data['error'])) {
            throw new \RuntimeException("Erro no script Python: " . $data['error']);
        }

        // Verifica método usado (coordinates ou fallback)
        $method = $data['method'] ?? 'unknown';

        // Converte formato Python para formato PHP
        $transactions = [];
        foreach ($data['transactions'] ?? [] as $txn) {
            // Data já vem no formato Y-m-d do Python
            $date = $txn['data'] ?? null;
            $description = $txn['descricao'] ?? '';
            $amount = (float)($txn['valor'] ?? 0);

            if ($date && $description && $amount > 0) {
                $transactions[] = [
                    'date' => $date,
                    'description' => trim($description),
                    'amount' => abs($amount),
                ];
            }
        }

        Log::info("Python OCR ({$method}) extraiu " . count($transactions) . " transações de " . ($data['total_encontrado'] ?? 0) . " encontradas");

        // Se método foi fallback e extraiu poucas transações, loga aviso mas não mistura com pdftotext
        if ($method === 'fallback_text' && count($transactions) < 10) {
            Log::warning("Python OCR usou fallback e extraiu apenas " . count($transactions) . " transações. Resultado mantido separado.");
        }

        return $transactions;
    }
}

