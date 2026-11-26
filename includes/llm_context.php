<?php
declare(strict_types=1);

const JOIA_REPORT_LAYOUT_PROMPT = ' Leia cada linha de conversas conforme a ordem em colunas. Use atividade para datas e contagens disponíveis.';

function joiaEstimatePromptTokens(array $messages): int
{
    // Safe, deliberately coarse estimate for common text encodings with byte
    // fallback. Treat every UTF-8 byte as a token, plus message framing.
    $estimate = 16;
    foreach ($messages as $message) {
        $estimate += 12 + strlen($message['role']) + strlen($message['content']);
    }
    return $estimate;
}

function joiaOperationBudget(string $operation): array
{
    static $budgets;
    if ($budgets === null) {
        $config = getenv('JOIA_TOKEN_BUDGETS_JSON');
        $budgets = $config !== false && $config !== '' ? json_decode($config, true) : [];
        if (!is_array($budgets)) $budgets = [];
    }
    return is_array($budgets[$operation] ?? null) ? $budgets[$operation] : [];
}

function joiaInputBudgetExceeded(string $operation, int $estimate): bool
{
    $limit = joiaOperationBudget($operation)['input'] ?? null;
    return is_int($limit) && $limit > 0 && $estimate > $limit;
}

function joiaRecentPairsWithoutDuplicates(array $turns, array $recent): array
{
    $seen = [];
    for ($index = 0; $index + 1 < count($recent); $index++) {
        $first = $recent[$index];
        $second = $recent[$index + 1];
        if (($first['role'] ?? '') === 'user' && ($second['role'] ?? '') === 'assistant') {
            $seen[] = [trim((string) ($first['content'] ?? '')), (string) ($second['content'] ?? '')];
        }
    }

    $unique = [];
    foreach ($turns as $turn) {
        $pair = [trim($turn['question']), $turn['answer']];
        if (in_array($pair, $seen, true)) continue;
        $seen[] = $pair;
        $unique[] = $turn;
    }
    return $unique;
}

function joiaReportEvidence(array $student): array
{
    $history = $student['history'] ?? [];
    $legacy = ['perfil' => $student['profile'], 'conversas' => $history];
    if (getenv('JOIA_COMPACT_REPORT') === '0' || !is_array($history) || array_values($history) !== $history) return $legacy;

    $rows = [];
    $dates = [];
    $countQuestions = 0;
    foreach ($history as $turn) {
        // Keep legacy or unexpected records losslessly in their original shape.
        if (!is_array($turn) || array_diff(array_keys($turn), ['role', 'content', 'timestamp'])
            || !is_string($turn['role'] ?? null) || !is_string($turn['content'] ?? null)
            || (array_key_exists('timestamp', $turn) && !is_string($turn['timestamp']))) return $legacy;

        $row = [$turn['role'], $turn['content']];
        if (isset($turn['timestamp'])) {
            $row[] = $turn['timestamp'];
            if (preg_match('/^\d{4}-\d{2}-\d{2}(?=T)/D', $turn['timestamp'], $date)) $dates[$date[0]] = true;
        }
        $rows[] = $row;
        if ($turn['role'] === 'user') $countQuestions++;
    }

    $days = array_keys($dates);
    sort($days);
    $activity = ['mensagens' => count($rows), 'mensagens_do_aluno' => $countQuestions, 'dias_registrados' => count($days)];
    if ($days) {
        $activity['primeiro_dia_registrado'] = $days[0];
        $activity['ultimo_dia_registrado'] = $days[count($days) - 1];
    }

    $compact = [
        'perfil' => $student['profile'],
        'colunas' => ['role', 'content', 'timestamp'],
        'conversas' => $rows,
        'atividade' => $activity,
    ];
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
    // Keep small reports in the old shape if the extra legend costs more
    // bytes than the repeated keys it would remove.
    return strlen(json_encode($compact, $jsonFlags)) + strlen(JOIA_REPORT_LAYOUT_PROMPT) < strlen(json_encode($legacy, $jsonFlags)) ? $compact : $legacy;
}
