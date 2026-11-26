<?php
declare(strict_types=1);

const JOIA_EMBEDDING_MODEL = 'text-embedding-3-small';
const JOIA_EMBEDDING_DIMENSIONS = 256;
const JOIA_RECENT_MESSAGES = 4;
const JOIA_RETRIEVED_TURNS = 2;
const JOIA_BACKFILL_TURNS = 6;
const JOIA_MIN_SIMILARITY = 0.45;

function joiaEmbeddings(array $texts): array
{
    $request = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt_array($request, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 600,
        CURLOPT_TIMEOUT_MS => 1800,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . openAIApiKey()],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => JOIA_EMBEDDING_MODEL,
            'dimensions' => JOIA_EMBEDDING_DIMENSIONS,
            'input' => array_values($texts),
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
    ]);
    $raw = curl_exec($request);
    if ($raw === false || curl_getinfo($request, CURLINFO_HTTP_CODE) !== 200) {
        throw new RuntimeException('Embeddings indisponíveis.');
    }

    $vectors = [];
    foreach (json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['data'] ?? [] as $item) {
        $vector = $item['embedding'] ?? [];
        if (count($vector) !== JOIA_EMBEDDING_DIMENSIONS) throw new RuntimeException('Dimensão inválida.');
        $vectors[$item['index']] = pack('g*', ...$vector);
    }
    if (count($vectors) !== count($texts)) throw new RuntimeException('Lote incompleto.');
    return $vectors;
}

function joiaVectorDatabase(string $directory): PDO
{
    $path = realpath($directory);
    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__);
    $projectRoot = dirname(__DIR__);
    foreach ([$documentRoot, $projectRoot] as $root) {
        if ($path === $root || ($path !== false && str_starts_with($path, rtrim($root, '/') . '/'))) {
            throw new RuntimeException('O diretório vetorial deve ser privado.');
        }
    }
    if ($path === false || !is_dir($path) || !is_writable($path)) {
        throw new RuntimeException('Diretório vetorial indisponível.');
    }

    $file = $path . '/joia-vetores.sqlite3';
    if (is_link($file)) throw new RuntimeException('Arquivo vetorial inválido.');
    $db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout = 150');
    $db->exec('CREATE TABLE IF NOT EXISTS student_vectors (
        student_id TEXT NOT NULL, model TEXT NOT NULL, digest TEXT NOT NULL, vector BLOB NOT NULL,
        PRIMARY KEY (student_id, model, digest)
    ) WITHOUT ROWID');
    return $db;
}

function joiaCosine(array $query, string $encoded): float
{
    if (strlen($encoded) !== JOIA_EMBEDDING_DIMENSIONS * 4) return -1;
    $vector = unpack('g*', $encoded);
    $dot = $queryNorm = $norm = 0.0;
    foreach ($query as $index => $value) {
        $other = $vector[$index];
        $dot += $value * $other;
        $queryNorm += $value * $value;
        $norm += $other * $other;
    }
    return $queryNorm > 0 && $norm > 0 ? $dot / sqrt($queryNorm * $norm) : -1;
}

function joiaHistoricalContext(string $studentId, array $history, string $message, PDO $db, callable $embed): array
{
    $turns = [];
    $recentStart = count($history) - JOIA_RECENT_MESSAGES;
    for ($index = 0; $index + 1 < $recentStart; $index++) {
        $question = $history[$index];
        $answer = $history[$index + 1];
        if (($question['role'] ?? null) !== 'user' || ($answer['role'] ?? null) !== 'assistant') continue;
        $text = trim((string) ($question['content'] ?? ''));
        if ($text === '') continue;
        $turns[hash('sha256', $text)] = ['index' => $index, 'question' => $text, 'answer' => (string) ($answer['content'] ?? '')];
    }
    if (!$turns) return [];

    $model = JOIA_EMBEDDING_MODEL . ':' . JOIA_EMBEDDING_DIMENSIONS;
    $select = $db->prepare('SELECT digest, vector FROM student_vectors WHERE student_id = ? AND model = ?');
    $select->execute([$studentId, $model]);
    $vectors = $select->fetchAll(PDO::FETCH_KEY_PAIR);
    $select->closeCursor();

    $queryDigest = hash('sha256', $message);
    $pending = [$queryDigest => $message];
    foreach (array_reverse($turns, true) as $digest => $turn) {
        if (count($pending) >= 1 + JOIA_BACKFILL_TURNS) break;
        if (!isset($vectors[$digest])) $pending[$digest] = $turn['question'];
    }
    $pending = array_diff_key($pending, $vectors);
    if ($pending) {
        $batch = $embed(array_values($pending));
        $insert = $db->prepare('INSERT OR IGNORE INTO student_vectors VALUES (?, ?, ?, ?)');
        $db->beginTransaction();
        try {
            foreach (array_keys($pending) as $index => $digest) {
                $encoded = $batch[$index] ?? '';
                if (strlen($encoded) !== JOIA_EMBEDDING_DIMENSIONS * 4) {
                    throw new RuntimeException('Vetor inválido.');
                }
                $insert->bindValue(1, $studentId);
                $insert->bindValue(2, $model);
                $insert->bindValue(3, $digest);
                $insert->bindValue(4, $encoded, PDO::PARAM_LOB);
                $insert->execute();
                $vectors[$digest] = $encoded;
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }
    $query = unpack('g*', $vectors[$queryDigest]);
    $scores = [];
    foreach ($turns as $digest => $turn) {
        if (!isset($vectors[$digest])) continue;
        $score = joiaCosine($query, $vectors[$digest]);
        if ($score >= JOIA_MIN_SIMILARITY) $scores[$digest] = $score;
    }
    arsort($scores);
    $selected = array_intersect_key($turns, array_slice($scores, 0, JOIA_RETRIEVED_TURNS, true));
    uasort($selected, static fn(array $a, array $b): int => $a['index'] <=> $b['index']);
    return array_values($selected);
}
