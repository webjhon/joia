<?php
declare(strict_types=1);

function joiaUsage(array $usage): array
{
    $tokens = static fn(mixed $value): ?int => is_int($value) && $value >= 0 ? $value : null;
    $input = $tokens($usage['prompt_tokens'] ?? null);
    $cached = $tokens($usage['prompt_tokens_details']['cached_tokens'] ?? null);

    return [
        'input_tokens' => $input,
        'cached_input_tokens' => $input !== null && $cached !== null && $cached <= $input ? $cached : null,
        'output_tokens' => $tokens($usage['completion_tokens'] ?? null),
        'total_tokens' => $tokens($usage['total_tokens'] ?? null),
    ];
}

function joiaMetricsDirectory(): ?string
{
    $configured = getenv('JOIA_METRICS_DIR');
    if (!$configured) return null;

    $path = realpath($configured);
    if ($path === false || !is_dir($path)) return null;
    $mode = @fileperms($path);
    if ($mode === false || ($mode & 0077) !== 0) return null;

    foreach ([dirname(__DIR__), $_SERVER['DOCUMENT_ROOT'] ?? ''] as $root) {
        if ($root === '') continue;
        $realRoot = realpath($root);
        if ($realRoot !== false && ($path === $realRoot || str_starts_with($path, rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
            return null;
        }
    }
    return $path;
}

function joiaMetricsFile(string $directory, string $date): string
{
    return $directory . DIRECTORY_SEPARATOR . 'openai-' . $date . '.jsonl';
}

function joiaApiPrices(): array
{
    static $prices = null;
    if ($prices !== null) return $prices;

    // USD por milhão de tokens no processamento padrão (não Batch).
    $defaults = [
        'gpt-4o-mini' => ['input' => 0.15, 'cached_input' => 0.075, 'output' => 0.60],
        'text-embedding-3-small' => ['input' => 0.02],
    ];
    $configured = getenv('JOIA_API_PRICES_JSON');
    if ($configured === false || trim($configured) === '') return $prices = $defaults;
    $overrides = json_decode($configured, true);
    return $prices = is_array($overrides) ? array_replace($defaults, $overrides) : [];
}

function joiaEstimatedCostUsd(array $metric): ?float
{
    $rates = joiaApiPrices()[$metric['model'] ?? ''] ?? null;
    $input = $metric['input_tokens'] ?? null;
    if (!is_array($rates) || $input === null) return null;

    $price = static function (mixed $value): ?float {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0
            ? (float) $value : null;
    };
    $inputRate = $price($rates['input'] ?? null);
    if ($inputRate === null) return null;
    if (str_starts_with($metric['model'], 'text-embedding-')) return round($input * $inputRate / 1000000, 12);

    $output = $metric['output_tokens'] ?? null;
    $outputRate = $price($rates['output'] ?? null);
    $cached = $metric['cached_input_tokens'] ?? null;
    $cachedRate = $price($rates['cached_input'] ?? null);
    if ($output === null || $outputRate === null || ($input > 0 && ($cached === null || $cached > $input))
        || ($cached > 0 && $cachedRate === null)) return null;

    return round((($input - ($cached ?? 0)) * $inputRate + ($cached ?? 0) * ($cachedRate ?? 0) + $output * $outputRate) / 1000000, 12);
}

function joiaMetric(string $operation, array $fields): void
{
    try {
        $remote = in_array($operation, ['chat', 'conversation', 'pedagogical_retry', 'retry', 'onboarding', 'report', 'embedding', 'embeddings', 'document', 'documents'], true);
        if (!$remote && !in_array($operation, ['rag', 'retrieval', 'budget'], true)) return;
        $directory = joiaMetricsDirectory();
        if ($directory === null) return;

        $now = time();
        $status = $fields['status'] ?? 'ok';
        $defaultRequests = (int) ($remote && !in_array($status, ['skipped', 'disabled'], true));
        $record = [
            'at' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'operation' => $operation,
            'api_requests' => ($fields['api_requests'] ?? $defaultRequests) === 1 ? 1 : 0,
        ];
        if (in_array($status, ['ok', 'error', 'skipped', 'fallback', 'disabled'], true)) $record['status'] = $status;
        $model = $fields['model'] ?? null;
        if (is_string($model) && preg_match('/^(?:gpt-[a-z0-9][a-z0-9.-]{0,70}|text-embedding-[a-z0-9][a-z0-9.-]{0,70})$/D', $model)) {
            $record['model'] = $model;
        }
        foreach (['input_tokens', 'cached_input_tokens', 'output_tokens', 'total_tokens', 'estimated_input_tokens',
            'output_limit', 'memories_retrieved', 'items', 'http_status'] as $key) {
            $value = $fields[$key] ?? null;
            if (is_int($value) && $value >= 0) $record[$key] = $value;
        }
        foreach (['fallback', 'retry'] as $key) {
            if (is_bool($fields[$key] ?? null)) $record[$key] = $fields[$key];
        }
        $duration = $fields['duration_ms'] ?? null;
        if ((is_int($duration) || is_float($duration)) && is_finite((float) $duration) && $duration >= 0) {
            $record['duration_ms'] = round((float) $duration, 3);
        }
        $reason = $fields['error_code'] ?? null;
        if (is_string($reason) && preg_match('/^(?:http_[1-5][0-9]{2}|timeout|transport|response|stream|json|invalid_response|unavailable|storage|budget)$/D', $reason)) {
            $record['error_code'] = $reason;
        }
        $record['estimated_cost_usd'] = $record['api_requests'] ? joiaEstimatedCostUsd($record) : null;

        $path = joiaMetricsFile($directory, gmdate('Y-m-d', $now));
        if (is_link($path) || (file_exists($path) && !is_file($path))) return;
        $mode = umask(0077);
        try {
            $handle = @fopen($path, 'ab');
        } finally {
            umask($mode);
        }
        if ($handle === false) return;

        try {
            if (!@flock($handle, LOCK_EX | LOCK_NB)) return;
            $stat = @fstat($handle);
            if ($stat === false || ($stat['mode'] & 0077) !== 0 || $stat['nlink'] !== 1) return;
            $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            $written = @fwrite($handle, $line);
            if ($written !== false && $written !== strlen($line)) @ftruncate($handle, $stat['size']);
        } finally {
            fclose($handle);
        }
    } catch (Throwable) {
        // A coleta é auxiliar. Falhas de configuração ou escrita não alteram o fluxo do aluno.
    }
}
