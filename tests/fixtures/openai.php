<?php
declare(strict_types=1);

// Loaded with auto_prepend_file by the isolated PHP development server.
if (PHP_SAPI !== 'cli-server' || !getenv('JOIA_TEST_CALLS')) {
    http_response_code(404);
    exit;
}

foreach (['RETURNTRANSFER', 'CONNECTTIMEOUT', 'TIMEOUT', 'CONNECTTIMEOUT_MS', 'TIMEOUT_MS', 'HTTPHEADER', 'POST', 'POSTFIELDS', 'WRITEFUNCTION', 'LOW_SPEED_LIMIT', 'LOW_SPEED_TIME'] as $index => $option) {
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
        'completion_limit' => $body['max_completion_tokens'] ?? null,
    ];
    $record = static function (?string $text = null) use ($capture): void {
        file_put_contents(getenv('JOIA_TEST_CALLS'), json_encode($capture + ['simulated_text' => $text], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    };

    // Synthetic usage exercises the parser. Billing is never inferred from it.
    $usage = ['prompt_tokens' => 4800, 'completion_tokens' => 34, 'total_tokens' => 4834, 'prompt_tokens_details' => ['cached_tokens' => 2048]];
    if ($kind === 'embedding') {
        $record();
        return json_encode([
            'data' => array_map(fn ($index) => ['index' => $index, 'embedding' => array_fill(0, 256, 0.25)], array_keys($body['input'])),
            'usage' => ['prompt_tokens' => 42, 'total_tokens' => 42],
            'model' => $body['model'],
        ], JSON_THROW_ON_ERROR);
    }

    if ($latest === 'Falha de API sintética') {
        $record();
        $handle->error = CURLE_OPERATION_TIMEDOUT;
        return false;
    }

    if ($kind === 'chat' && str_contains($latest, 'teste concorrente de histórico')) {
        // Both processes reach the model before either can persist a reply.
        $barrier = dirname(getenv('JOIA_TEST_CALLS')) . '/race-participants';
        file_put_contents($barrier, "pronto\n", FILE_APPEND | LOCK_EX);
        $deadline = microtime(true) + 2;
        while (count(file($barrier, FILE_IGNORE_NEW_LINES)) < 2) {
            if (microtime(true) >= $deadline) throw new RuntimeException('Teste concorrente requer dois processos PHP.');
            usleep(10000);
        }
    }

    $prefix = "Na coluna, o alinhamento horizontal acontece no eixo transversal.\n\n";
    $example = match ($latest) {
        'Entregue um componente completo em React Native com NativeWind.' => ['jsx', <<<'JSX'
import { Text, View } from 'react-native';

export function Cartao() {
  return (
    <View className="p-4 rounded-lg">
      <Text>Olá, turma!</Text>
    </View>
  );
}
JSX],
        'Mostre uma animação Animated completa, com imports.' => ['tsx', <<<'TSX'
import { useRef } from 'react';
import { Animated } from 'react-native';

export function Painel() {
  const deslocamento = useRef(new Animated.Value(0)).current;
  return <Animated.View style={{ transform: [{ translateX: deslocamento }] }} />;
}
TSX],
        'Mostre o programa completo em Python, com duas caixas de código.' => ['python', <<<'PYTHON'
def contar_itens(itens):
    total = 0
    for item in itens:
        if item:
            total += 1
    return total


if __name__ == "__main__":
    print(contar_itens(["maçã", "", "pera"]))
PYTHON],
        'Escreva a solução completa em SQL.' => ['sql', <<<'SQL'
SELECT categoria,
       COUNT(*) AS total
FROM produtos
WHERE preco > 0
GROUP BY categoria
ORDER BY total DESC;
SQL],
        default => null,
    };
    $finishReason = match ($latest) {
        'Simule uma resposta longa interrompida no meio.' => 'length',
        'Simule uma resposta parcial sinalizada pelo provedor.' => 'content_filter',
        default => 'stop',
    };
    $aborted = $latest === 'Simule o fim do fluxo antes da conclusão do provedor.';
    $pieces = match ($kind) {
        'report' => ['## Síntese do percurso', "\n\n", 'A estudante comparou o eixo principal e o transversal.'],
        'onboarding' => ['Informação registrada. Siga para a próxima etapa do cadastro.'],
        'pedagogical_retry' => ["Observe o elemento que agrupa os filhos.\n\n", 'Compare a posição antes e depois.'],
        default => match (true) {
            $example !== null => [
                "\nAqui está um exemplo completo.\n\n``",
                "`{$example[0]}\n" . $example[1] . "\n```\n\n",
                "Você pode comparar com esta saída.\n\n```text\nTotal: 2\n```\n",
            ],
            $aborted || $finishReason !== 'stop' => [
                "Um teste curto pode começar assim.\n\n",
                "```python\ndef acumulador():\n    return total",
            ],
            str_contains($latest, 'teste concorrente de histórico') => ["Resposta para: {$latest}\n\n", "```text\nExemplo\n```"],
            $latest === 'Mostre a resposta de referência sem retoques.' => [
                "\n4. Aqui está um exemplo\n\n",
                "Compare os dados ou\n",
            ],
            $latest === 'Como testar o algoritmo de um acumulador quando a lista está vazia?' => [
                'Com uma lista vazia, o laço não roda e o acumulador conserva o estado inicial. Confira esse valor e compare com uma lista de um elemento.',
            ],
            $latest === 'Agora me da um exemplo de código de um objeto que pula na tela por exemplo.' => [
                "Use o deslocamento vertical para levantar o objeto e depois voltá-lo à posição inicial.\n\n",
                "Um valor negativo de translateY move o objeto para cima.",
            ],
            $latest === 'Quero ligar o valor animado à opacidade de uma View com Animated.' => [
                "O valor animado controla quanto do elemento fica visível.\n\n",
                "```jsx\nconst opacidade = useRef(new Animated.Value(0)).current;\n<Animated.View style={{ opacity: opacidade }} />\n```",
            ],
            str_contains($latest, 'No exemplo anterior de opacidade, por que o valor começa em zero') => str_contains($system, 'Fragmento efetivamente exibido na conversa')
                && str_contains($system, 'const opacidade = useRef(new Animated.Value(0)).current;')
                && str_contains($system, 'opacity: opacidade')
                && str_contains($system, 'Referências técnicas curadas')
                ? ["Naquele trecho, a variável opacidade começa em zero porque é o valor inicial de Animated.Value.\n\n", 'O elemento começa invisível e aparece quando o valor cresce em direção a um.']
                : ['O trecho usa uma variável substituta chamada valorErradoDaFixture.'],
            $latest === 'O que significa opacity: opacidade naquele exemplo?' => str_contains($system, 'Fragmento efetivamente exibido na conversa')
                && str_contains($system, 'opacity: opacidade')
                ? ['opacity é a propriedade e opacidade é a variável que fornece o valor.']
                : ['O trecho usa uma variável substituta chamada valorErradoDaFixture.'],
            str_contains($latest, 'NativeWind') => [$prefix, "```jsx\nclassName=\"flex-col items-center\"\n```"],
            default => [$prefix, 'Examine a direção do contêiner para determinar o eixo.'],
        },
    };
    $answer = implode('', $pieces);
    $record($answer);
    if (!$stream) {
        return json_encode([
            'model' => $body['model'],
            'choices' => [['message' => ['content' => $answer], 'finish_reason' => $finishReason]],
            'usage' => $usage,
        ], JSON_THROW_ON_ERROR);
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
    if ($aborted) return true;
    $write($handle, 'data: ' . json_encode(['choices' => [['delta' => new stdClass(), 'finish_reason' => $finishReason]]], JSON_THROW_ON_ERROR) . "\r\n\r\n");
    if ($body['stream_options']['include_usage'] ?? false) {
        $write($handle, 'data: ' . json_encode(['model' => $body['model'], 'choices' => [], 'usage' => $usage], JSON_THROW_ON_ERROR) . "\n\n");
    }
    $write($handle, "data: [DONE]\n\n");
    return true;
}
