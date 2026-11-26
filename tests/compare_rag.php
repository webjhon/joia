<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!isset($argv[1]) || !is_file($argv[1])) {
    fwrite(STDERR, "Informe o arquivo PHP de memória vetorial da revisão sob teste.
");
    exit(2);
}
require $argv[1];
function normalize(string $text): string {
    return trim(preg_replace('/[^a-z0-9]+/', ' ', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($text), 'UTF-8'))) ?? '');
}
function turn(string $text): array {
    return [['role' => 'user','content' => $text], ['role' => 'assistant','content' => 'Resposta sintética.']];
}
$base = [...turn('Oi!'), ...turn('Vamos falar de servidores.'), ...turn('Quais portas usar?'), ...turn('Entendi.'), ...turn('Obrigado.')];
$recent = [...array_slice($base, 0, -2), ...turn('Tentei avançar no exercício e ainda está difícil.')];
$cases = [
    'saudação' => ['Oi!', $base],
    'definição autônoma' => ['O que é uma API?', $base],
    'continuação' => ['Pode continuar do ponto anterior?', $base],
    'programação complexa' => ['Como lido com várias conexões assíncronas abertas?', $base],
    'dificuldade recente' => ['O que é uma API?', $recent],
];
$totalBatches = $totalItems = 0;
foreach ($cases as $name => [$message, $history]) {
    $calls = $items = 0;
    $db = new PDO('sqlite::memory:');
    $db->exec('CREATE TABLE student_vectors (student_id TEXT NOT NULL, model TEXT NOT NULL, digest TEXT NOT NULL, vector BLOB NOT NULL, PRIMARY KEY (student_id, model, digest)) WITHOUT ROWID');
    $embed = static function (array $texts) use (&$calls, &$items): array {
        $calls++;
        $items += count($texts);
        return array_map(static fn($text) => $text === 'Oi!'
            ? pack('g*', 0.0, 1.0, ...array_fill(0, JOIA_EMBEDDING_DIMENSIONS - 2, 0.0))
            : pack('g*', 1.0, ...array_fill(0, JOIA_EMBEDDING_DIMENSIONS - 1, 0.0)), $texts);
    };
    $turns = function_exists('joiaShouldRetrieveHistory') && !joiaShouldRetrieveHistory($message, $history) ? [] : joiaHistoricalContext('fixture', $history, $message, $db, $embed);
    $totalBatches += $calls;
    $totalItems += $items;
    printf("%s | lotes=%d | itens=%d | resultados=%s\n", $name, $calls, $items, implode(' / ', array_column($turns, 'question')));
}
printf("TOTAL | lotes=%d | itens=%d\n", $totalBatches, $totalItems);
