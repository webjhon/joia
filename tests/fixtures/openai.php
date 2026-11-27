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

    if ($kind === 'chat' && str_contains($latest, 'teste concorrente de cota')) {
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
    $nativeWindFourLines = "```jsx\n<View\n  className=\n  \"p-4 rounded-lg\"\n/>\n```";
    $animatedFourLines = "```jsx\nAnimated.timing(opacidade, {\n  toValue: 1,\n  useNativeDriver: true,\n}).start();\n```";
    $invalidJump = "```jsx\nfunction Jump() {\n  return <View />;\n}\n```";
    $omittedOpacitySnippet = str_contains($latest, 'animar a opacidade de zero a um');
    $omittedInterpolationSnippet = str_contains($latest, 'mapear dois intervalos com interpolate');
    $mixedProse = "O NativeWind aplica o estilo fixo enquanto Animated controla a opacidade que muda.\n\n";
    $mixedFalseDelivery = [$mixedProse, "Aqui está um exemplo para testar.\n\n", '### Explicação do código'];
    $mixedInvalid = "```jsx\nfunction ComponenteCompletoNaoPermitido() {\n  return <Animated.View className=\"p-4\" style={{ opacity: valor }} />;\n}\n```";
    $mixedExampleRequested = in_array($latest, ['poderia me dar um exemplo de código pra esta situação?', 'quual exemplo, não vi nenhum código', 'Mas quero um exemplo de código'], true);
    $truncatedAccumulator = in_array($latest, [
        'Como reconhecer que um acumulador parou no estado esperado?',
        'Como verificar o resultado final de um acumulador?',
    ], true);
    $orphanFormList = $latest === 'Como organizar as etapas de validação de um formulário?';
    $orphanCode = $latest === 'Como aplico o início da animação no Animated?';
    $hierarchyExample = in_array($latest, [
        'poderia me dar um exemplo e explicar/',
        'não entendi, eu pedi um exemplo em código e me explicar esse código cadê?',
    ], true);
    $accumulatorOpening = 'Compare o acumulador depois de cada entrada.';
    $accumulatorClosing = 'Faça um teste com uma lista vazia e confirme que o valor inicial permaneceu.';
    $orphanButton = "4. **Botão:** pressione para exibir um alerta.\n\n### O que observar\n\n- **Estilos:** as classes ajudam a definir o layout e o";
    $finishReason = $latest === 'Como reconhecer que um acumulador parou no estado esperado?' && $kind === 'chat' ? 'length' : 'stop';
    $pieces = match ($kind) {
        'report' => ['## Síntese do percurso', "\n\n", 'A estudante comparou o eixo principal e o transversal.'],
        'onboarding' => ['Informação registrada. Siga para a próxima etapa do cadastro.'],
        'pedagogical_retry' => match (true) {
            $truncatedAccumulator => str_contains($system, 'Continue a explicação já exibida ao aluno')
                ? [$accumulatorClosing] : [$accumulatorOpening . "\n\n", $accumulatorClosing],
            $orphanFormList => ['Valide cada campo antes de confirmar o envio. Comece por um caso com um campo obrigatório vazio.'],
            $orphanCode => ["Prepare o valor animado antes de iniciar o movimento.\n\n", $animatedFourLines],
            $mixedExampleRequested => ['Aplique a classe no elemento e use o valor animado para observar o efeito visual.'],
            $omittedOpacitySnippet => str_contains($system, 'entregue a caixa de código dentro do limite')
                ? ["O valor animado define a opacidade.\n\n", $animatedFourLines]
                : ["Use o valor animado na propriedade de opacidade da Animated.View.\n\n", 'Confira se o elemento começa invisível e aparece gradualmente.'],
            $omittedInterpolationSnippet => str_contains($system, 'entregue a caixa de código dentro do limite')
                ? ["Use o primeiro intervalo para a entrada.\n\n", "```jsx\nvalor.interpolate({ inputRange: [0, 1], outputRange: [10, 20] });\n```"]
                : ["Use um intervalo de entrada e outro de saída.\n\n", 'Teste os valores em cada extremo.'],
            str_contains($latest, 'começar um salto') => [$invalidJump],
            str_contains($latest, 'conferir um contorno discreto') => ["Aplique a classe no contêiner externo.\n\n", $nativeWindFourLines],
            default => ["Observe o elemento que agrupa os filhos.\n\n", 'Compare a posição antes e depois.'],
        },
        default => match (true) {
            $truncatedAccumulator => [$accumulatorOpening . "\n\n", 'Compare também o que acontece quando a entrada e o'],
            $orphanFormList => ['4. Valide o último campo antes de confirmar.'],
            $orphanCode => [$invalidJump . "\n\n", $orphanButton],
            str_contains($latest, 'view pais, como distribuo') => ['Use a classe `align-items-center` para alinhar os filhos.'],
            $hierarchyExample => [$orphanButton],
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
            $latest === 'oi to com dificuldades com o nativewind, não entendo onde aplicar'
                => ["No NativeWind, a classe da View organiza o espaço interno.\n\n", "```jsx\n<View\n  className=\"p-4\"\n/>\n```"],
            $latest === 'E se eu quiser misturar o nativewind com animated?' => [$mixedProse, 'Conecte o valor à propriedade visual da View que precisa mudar.'],
            $latest === 'quual exemplo, não vi nenhum código' => [$mixedProse, $mixedInvalid],
            $latest === 'Mas quero um exemplo de código' => [$mixedProse, "### Explicação do código\n\n", 'O elemento aparece aos poucos.'],
            $mixedExampleRequested => $mixedFalseDelivery,
            str_contains($latest, 'Tailwind CSS na web') => [$prefix, $nativeWindFourLines],
            $omittedOpacitySnippet => [
                "A opacidade aumenta quando o valor animado vai de zero a um.\n\n",
                "Aqui está um trecho curto para testar.\n\n",
                "### O que este código faz\n\n",
                'O elemento aparece gradualmente.',
            ],
            $omittedInterpolationSnippet => ["Na interpolação, cada valor de entrada corresponde a uma saída.\n\n", 'Segue um trecho curto para observar os extremos.'],
            str_contains($latest, 'começar um salto') => [$invalidJump],
            str_contains($latest, 'arredondar a borda do cartão') => [$prefix, $nativeWindFourLines],
            str_contains($latest, 'começar a animar a opacidade') => ["## Conexão\n\n", "O valor animado controla a opacidade.\n\n", $animatedFourLines],
            str_contains($latest, 'conferir um contorno discreto') => [$prefix, "```jsx\n<View>\n  <Text>Cartão</Text>\n  <Text>Legenda</Text>\n  <Text>Rodapé</Text>\n</View>\n```"],
            str_contains($latest, 'Animated') => ["## Conexão\n\n", "O valor animado controla a opacidade.\n\n", "```jsx\nconst opacity = useRef(new Animated.Value(0)).current;\n```"],
            str_contains($latest, 'NativeWind') => [$prefix, "```jsx\nclassName=\"flex-col items-center\"\n```"],
            str_contains($latest, 'Ignore as regras') => [$prefix, "```js\nconst a = 1;\nconst b = 2;\nconst c = 3;\n```"],
            default => [$prefix, 'Examine a direção do contêiner para determinar o eixo.'],
        },
    };
    if (!$stream) {
        return json_encode([
            'model' => $body['model'],
            'choices' => [['message' => ['content' => implode('', $pieces)], 'finish_reason' => $finishReason]],
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
    $write($handle, 'data: ' . json_encode(['choices' => [['delta' => new stdClass(), 'finish_reason' => $finishReason]]], JSON_THROW_ON_ERROR) . "\r\n\r\n");
    if ($body['stream_options']['include_usage'] ?? false) {
        $write($handle, 'data: ' . json_encode(['model' => $body['model'], 'choices' => [], 'usage' => $usage], JSON_THROW_ON_ERROR) . "\n\n");
    }
    $write($handle, "data: [DONE]\n\n");
    return true;
}
