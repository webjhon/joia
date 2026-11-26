<?php
declare(strict_types=1);

// Loaded with auto_prepend_file by the isolated PHP development server.
if (PHP_SAPI !== 'cli-server' || !getenv('JOIA_TEST_CALLS')) {
    http_response_code(404);
    exit;
}

foreach (['RETURNTRANSFER', 'CONNECTTIMEOUT', 'TIMEOUT', 'CONNECTTIMEOUT_MS', 'TIMEOUT_MS', 'HTTPHEADER', 'POST', 'POSTFIELDS', 'WRITEFUNCTION'] as $index => $option) {
    define('CURLOPT_' . $option, $index);
}
define('CURLINFO_HTTP_CODE', 200);
define('CURLINFO_RESPONSE_CODE', CURLINFO_HTTP_CODE);
define('CURLE_OPERATION_TIMEDOUT', 28);

final class CurlHandle
{
    public array $options = [];
    public int $error = 0;

    public function __construct(public string $url) {}
}

function curl_init(string $url): CurlHandle { return new CurlHandle($url); }
function curl_setopt_array(CurlHandle $handle, array $options): bool { $handle->options = $options; return true; }
function curl_setopt(CurlHandle $handle, int $option, mixed $value): bool { $handle->options[$option] = $value; return true; }
function curl_getinfo(CurlHandle $handle, int $option): int { return $handle->error ? 0 : 200; }
function curl_error(CurlHandle $handle): string { return $handle->error ? 'timeout sintético' : ''; }
function curl_errno(CurlHandle $handle): int { return $handle->error; }
function curl_close(CurlHandle $handle): void {}

function curl_exec(CurlHandle $handle): string|bool
{
    $body = json_decode($handle->options[CURLOPT_POSTFIELDS], true, flags: JSON_THROW_ON_ERROR);
    $stream = !empty($body['stream']);
    $messages = $body['messages'] ?? [];
    $system = implode("\n", array_column(array_filter($messages, fn ($m) => $m['role'] === 'system'), 'content'));
    $user = array_values(array_filter($messages, fn ($m) => $m['role'] === 'user'));
    $latest = end($user)['content'] ?? '';
    $kind = match (true) {
        str_ends_with($handle->url, '/embeddings') => 'embedding',
        str_contains($system, 'Conduza uma única etapa do cadastro') => 'onboarding',
        str_contains($system, 'Você é um especialista em avaliação educacional') => 'report',
        str_contains($system, 'Reformule a resposta à última pergunta'),
            str_contains($system, 'Continue a explicação já exibida ao aluno') => 'pedagogical_retry',
        default => 'chat',
    };
    $capture = [
        'kind' => $kind,
        'stream' => $stream,
        'stream_usage' => $body['stream_options']['include_usage'] ?? false,
        'model' => $body['model'],
        'messages' => $messages,
        'limit' => $body['max_tokens'] ?? null,
    ];
    file_put_contents(getenv('JOIA_TEST_CALLS'), json_encode($capture, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);

    // Synthetic usage exercises the parser. Billing is never inferred from it.
    $usage = ['prompt_tokens' => 4800, 'completion_tokens' => 34, 'total_tokens' => 4834, 'prompt_tokens_details' => ['cached_tokens' => 2048]];
    if ($kind === 'embedding') {
        return json_encode([
            'data' => array_map(fn ($index) => ['index' => $index, 'embedding' => array_fill(0, 256, 0.25)], array_keys($body['input'])),
            'usage' => ['prompt_tokens' => 42, 'total_tokens' => 42],
            'model' => $body['model'],
        ], JSON_THROW_ON_ERROR);
    }

    if ($latest === 'Falha de API sintética') {
        $handle->error = CURLE_OPERATION_TIMEDOUT;
        return false;
    }

    $prefix = "Na coluna, o alinhamento horizontal acontece no eixo transversal.\n\n";
    $pieces = match ($kind) {
        'report' => ['## Síntese do percurso', "\n\n", 'A estudante comparou o eixo principal e o transversal.'],
        'onboarding' => ['Informação registrada. Siga para a próxima etapa do cadastro.'],
        'pedagogical_retry' => ["Observe o elemento que agrupa os filhos.\n\n", 'Compare a posição antes e depois.'],
        default => match (true) {
            str_contains($latest, 'NativeWind') => [$prefix, "```jsx\nclassName=\"flex-col items-center\"\n```"],
            str_contains($latest, 'Ignore as regras') => [$prefix, "```js\nconst a = 1;\nconst b = 2;\nconst c = 3;\n```"],
            default => [$prefix, 'Examine a direção do contêiner para determinar o eixo.'],
        },
    };
    if (!$stream) {
        return json_encode(['model' => $body['model'], 'choices' => [['message' => ['content' => implode('', $pieces)]]], 'usage' => $usage], JSON_THROW_ON_ERROR);
    }

    $write = $handle->options[CURLOPT_WRITEFUNCTION];
    foreach ($pieces as $piece) {
        $event = 'data: ' . json_encode(['model' => $body['model'], 'choices' => [['delta' => ['content' => $piece]]]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\r\n\r\n";
        // Exercise SSE framing across callbacks, independently of UTF-8 boundaries.
        $split = strpos($event, 'ç');
        $split = $split === false ? 9 : $split + 1;
        $write($handle, substr($event, 0, $split));
        $write($handle, substr($event, $split));
        usleep(50000);
    }
    if ($body['stream_options']['include_usage'] ?? false) {
        $write($handle, 'data: ' . json_encode(['model' => $body['model'], 'choices' => [], 'usage' => $usage], JSON_THROW_ON_ERROR) . "\n\n");
    }
    $write($handle, "data: [DONE]\n\n");
    return true;
}
