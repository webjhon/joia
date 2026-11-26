<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/api_metrics.php';

function usageError(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function usageAdd(array &$totals, array $event): void
{
    $totals['eventos'] = ($totals['eventos'] ?? 0) + 1;
    $calls = ($event['api_requests'] ?? 0) === 1 ? 1 : 0;
    $totals['requisicoes_api'] = ($totals['requisicoes_api'] ?? 0) + $calls;
    $status = $event['status'] ?? null;
    if ($status === 'error') $totals['erros'] = ($totals['erros'] ?? 0) + 1;
    if ($status === 'fallback' || ($event['fallback'] ?? false)) $totals['fallbacks'] = ($totals['fallbacks'] ?? 0) + 1;
    if ($status === 'skipped') $totals['operacoes_poupadas'] = ($totals['operacoes_poupadas'] ?? 0) + 1;
    if ($calls > 0 && (($event['retry'] ?? false) || in_array($event['operation'], ['pedagogical_retry', 'retry'], true))) {
        $totals['reformulacoes'] = ($totals['reformulacoes'] ?? 0) + 1;
    }
    $cost = $event['estimated_cost_usd'] ?? null;
    if (is_int($cost) || is_float($cost)) {
        $totals['custo_estimado_usd'] = ($totals['custo_estimado_usd'] ?? 0.0) + $cost;
        $totals['requisicoes_com_estimativa_de_custo'] = ($totals['requisicoes_com_estimativa_de_custo'] ?? 0) + 1;
    } elseif ($calls > 0) {
        $totals['requisicoes_sem_estimativa_de_custo'] = ($totals['requisicoes_sem_estimativa_de_custo'] ?? 0) + $calls;
    }
    if (isset($event['total_tokens'])) $totals['requisicoes_com_uso'] = ($totals['requisicoes_com_uso'] ?? 0) + $calls;
    foreach (['input_tokens', 'cached_input_tokens', 'output_tokens', 'total_tokens', 'estimated_input_tokens',
        'memories_retrieved', 'items'] as $key) {
        $value = $event[$key] ?? null;
        if (is_int($value) && $value >= 0) $totals[$key] = ($totals[$key] ?? 0) + $value;
    }
    $model = $event['model'] ?? '';
    if (is_string($model) && str_starts_with($model, 'text-embedding-') && is_int($event['input_tokens'] ?? null)) {
        $totals['embedding_input_tokens'] = ($totals['embedding_input_tokens'] ?? 0) + $event['input_tokens'];
    }
    if (is_int($event['cached_input_tokens'] ?? null) && is_int($event['input_tokens'] ?? null)) {
        $totals['input_tokens_com_cache_medido'] = ($totals['input_tokens_com_cache_medido'] ?? 0) + $event['input_tokens'];
    }
    if (is_int($event['duration_ms'] ?? null) || is_float($event['duration_ms'] ?? null)) {
        $totals['duracao_ms'] = ($totals['duracao_ms'] ?? 0) + $event['duration_ms'];
        $totals['eventos_com_duracao'] = ($totals['eventos_com_duracao'] ?? 0) + 1;
    }
}

function usageFinish(array $totals): array
{
    if (isset($totals['custo_estimado_usd'])) $totals['custo_estimado_usd'] = round($totals['custo_estimado_usd'], 12);
    if (isset($totals['duracao_ms'])) {
        $totals['duracao_media_ms'] = round($totals['duracao_ms'] / $totals['eventos_com_duracao'], 2);
        $totals['duracao_ms'] = round($totals['duracao_ms'], 2);
    }
    if (($totals['input_tokens_com_cache_medido'] ?? 0) > 0) {
        $totals['proporcao_tokens_em_cache'] = round($totals['cached_input_tokens'] / $totals['input_tokens_com_cache_medido'], 6);
    }
    return $totals;
}

$interval = $argv[1] ?? '';
if (!in_array($interval, ['day', 'week', 'month'], true) || count($argv) > 3) {
    usageError('Uso: php scripts/usage.php day|week|month [AAAA-MM-DD]');
}
try {
    $timezone = new DateTimeZone(getenv('JOIA_METRICS_TZ') ?: 'America/Sao_Paulo');
} catch (Throwable) {
    usageError('Fuso inválido em JOIA_METRICS_TZ.');
}
$anchor = $argv[2] ?? (new DateTimeImmutable('today', $timezone))->format('Y-m-d');
$anchorDate = DateTimeImmutable::createFromFormat('!Y-m-d', $anchor, $timezone);
if (!$anchorDate || $anchorDate->format('Y-m-d') !== $anchor) usageError('Use uma data válida no formato AAAA-MM-DD.');

$start = match ($interval) {
    'week' => $anchorDate->modify('monday this week'),
    'month' => $anchorDate->modify('first day of this month'),
    default => $anchorDate,
};
$end = $start->modify('+1 ' . $interval);
$utc = new DateTimeZone('UTC');
$startUtc = $start->setTimezone($utc);
$endUtc = $end->setTimezone($utc);
$format = 'Y-m-d\TH:i:s\Z';
$first = $startUtc->format($format);
$last = $endUtc->format($format);
$directory = joiaMetricsDirectory();
if ($directory === null) usageError('Defina JOIA_METRICS_DIR como diretório privado (0700), fora do repositório e da raiz pública.');

$totals = ['eventos' => 0, 'requisicoes_api' => 0];
$operations = [];
$models = [];
$badRecords = 0;
for ($day = $startUtc->setTime(0, 0); $day < $endUtc; $day = $day->modify('+1 day')) {
    $file = joiaMetricsFile($directory, $day->format('Y-m-d'));
    if (is_link($file) || !is_file($file)) continue;
    $handle = @fopen($file, 'rb');
    if ($handle === false) usageError('Não foi possível ler um arquivo de métricas do período.');
    try {
        while (($line = fgets($handle, 8192)) !== false) {
            $event = json_decode($line, true);
            if (!is_array($event) || !is_string($event['at'] ?? null) || !is_string($event['operation'] ?? null)) {
                $badRecords++;
                continue;
            }
            if ($event['at'] < $first || $event['at'] >= $last) continue;
            usageAdd($totals, $event);
            $operation = $event['operation'];
            $operations[$operation] ??= [];
            usageAdd($operations[$operation], $event);
            if (is_string($event['model'] ?? null)) {
                $model = $event['model'];
                $models[$model] ??= [];
                usageAdd($models[$model], $event);
            }
        }
    } finally {
        fclose($handle);
    }
}
ksort($operations);
ksort($models);

echo json_encode([
    'periodo' => $interval,
    'inicio' => $start->format(DATE_ATOM),
    'fim_exclusivo' => $end->format(DATE_ATOM),
    'totais' => usageFinish($totals),
    'por_operacao' => array_map('usageFinish', $operations),
    'por_modelo' => array_map('usageFinish', $models),
    'registros_invalidos' => $badRecords,
    'nota' => 'Os custos são estimativas com base no uso e nas tarifas disponíveis ao registrar a operação. Não representam o faturamento oficial.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
