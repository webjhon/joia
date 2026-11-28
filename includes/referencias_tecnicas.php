<?php
declare(strict_types=1);

const JOIA_TECHNICAL_HISTORY_WINDOW = 24;
const JOIA_TECHNICAL_REFERENCE_LIMIT = 2;

function joiaTechnicalCodeContext(string $message, array $history): ?string
{
    $question = normalize($message);
    $topicChange = '/\b(?:mudando de assunto|mudar de assunto|troquei de assunto|outro assunto|novo assunto)\b/';
    if (preg_match($topicChange, $question)) return null;
    $refersToHistory = preg_match('/\b(?:isso|nesse|nest[ea]|nessa|naquel[ea]|anterior|esse|essa|aquel[ea]|de antes|onde parou|lado direito|dois pontos)\b/', $question);
    if (!$refersToHistory && !preg_match('/\b(?:qual (?:e )?a diferenca|o que (?:significa|faz)|ja usei)\b/', $question)) return null;

    preg_match_all('/(?<!`)`([A-Za-z_$][\w$.-]*)`(?!`)/', $message, $quoted);
    preg_match_all('/\b(?:[A-Za-z][a-z0-9]+[A-Z][A-Za-z0-9]*|[A-Za-z]\w*(?:[._][A-Za-z]\w*)+|'
        . '(?i:opacity|opacidade|translate[xy]|deslocamento|transform|animated|useref|usestate|tovalue|duration|usenativedriver|classname|stylesheet))\b/', $message, $named);
    $anchors = array_filter(array_unique(array_map('normalize', array_merge($quoted[1], $named[0]))));
    if (!$refersToHistory && !$anchors) return null;
    $best = null;
    $bestScore = 0;
    foreach (array_reverse(array_slice($history, -JOIA_TECHNICAL_HISTORY_WINDOW)) as $turn) {
        $content = (string) ($turn['content'] ?? '');
        $role = $turn['role'] ?? '';
        if ($role === 'user' && preg_match($topicChange, normalize($content))) break;
        if ($role !== 'user' && $role !== 'assistant') continue;
        preg_match_all('/^\h*((`|~)\2{2,})(?!\2)[^\r\n]*\R.*?^\h*\1\2*\h*(?=\R|\z)/ms', $content, $matches);
        foreach (array_reverse($matches[0]) as $block) {
            if (!$anchors) return $block;
            $candidate = ' ' . normalize($block) . ' ';
            $score = 0;
            foreach ($anchors as $anchor) {
                if (str_contains($candidate, ' ' . $anchor . ' ')) $score++;
            }
            if ($score === count($anchors)) return $block;
            if ($score > $bestScore) {
                $best = $block;
                $bestScore = $score;
            }
        }
    }
    return $best;
}

function joiaTechnicalCatalog(): array
{
    // Technical notes are independent of the teacher's catalog. Sources are
    // kept for maintenance; the chat gets only the selected explanations.
    return [
        [
            'termos' => ['opacity', 'opacidade', 'stylesheet', 'propriedade valor', 'dois pontos', 'estilo fixo', 'valor animado'],
            'titulo' => 'React Native · Propriedade de estilo e valor',
            'conteudo' => 'Em um objeto de estilos, a chave indica o que muda e a expressão à direita produz o valor. Em opacity: 0.5 o valor é constante. Em opacity: opacidade na Animated.View, o valor vem da variável opacidade. A opacidade vai de 0 (transparente) a 1 (totalmente opaco).',
            'fonte' => 'https://reactnative.dev/docs/view-style-props#opacity',
        ],
        [
            'termos' => ['animated', 'animated value', 'useref', 'timing', 'tovalue', 'usenativedriver', 'duracao', 'interpolar'],
            'titulo' => 'React Native · Valor e execução do Animated',
            'conteudo' => 'O mesmo Animated.Value pode persistir entre renderizações em um useRef. O vínculo ao estilo faz o componente acompanhar o valor. Em Animated.timing, toValue é o destino e duration é o tempo em milissegundos. A animação inicia com start, que pode ser chamado na montagem ou em um evento. O driver nativo pode animar opacity e transform, mas não propriedades de layout como width e flex.',
            'fonte' => 'https://reactnative.dev/docs/animations',
        ],
        [
            'termos' => ['pula', 'pular', 'pulo', 'saltar', 'salto', 'aterrissar', 'translatey', 'efeito de pulo', 'deslocamento vertical', 'sobe e desce', 'sequence'],
            'titulo' => 'React Native · Algoritmo de movimento vertical',
            'conteudo' => 'O salto na tela tem três pontos: repouso com translateY zero, subida com destino negativo e retorno a zero. A API Animated.sequence ordena a subida e a descida. O callback final permite distinguir o término de uma interrupção e contar o salto concluído.',
            'fonte' => 'https://reactnative.dev/docs/animations',
        ],
        [
            'termos' => ['nativewind', 'native wind', 'classname', 'tailwind', 'p 4', 'rounded lg', 'bg blue', 'utilitarias', 'align items center'],
            'titulo' => 'NativeWind · Classes, estilos e componentes',
            'conteudo' => 'Classes de className expressam estilos no componente que as recebe. A classe p-4 define espaçamento interno e items-center corresponde a alignItems: center. Uma Animated.View externa pode cuidar de style e uma View interna de className. A configuração de instalação varia por versão.',
            'fonte' => 'https://www.nativewind.dev/docs',
        ],
        [
            'termos' => ['flexbox', 'flex col', 'flex row', 'items center', 'justify center', 'eixo transversal', 'eixo principal', 'layout'],
            'titulo' => 'React Native · Eixos do Flexbox',
            'conteudo' => 'flexDirection define o eixo principal no contêiner. justifyContent atua nesse eixo e alignItems atua no transversal. O padrão do Flexbox no React Native é column. Para className, items-center corresponde ao eixo transversal e justify-center ao principal.',
            'fonte' => 'https://reactnative.dev/docs/flexbox',
        ],
        [
            'termos' => ['expo', 'expo go', 'sdk', 'eas', 'apk', 'aab', 'development build', 'dependencia nativa', 'biblioteca nativa'],
            'titulo' => 'Expo · Ambiente de execução e build',
            'conteudo' => 'Expo Go traz um conjunto fixo de módulos nativos. Uma development build pode incluir os módulos do projeto. Executar o projeto local, criar a build Android e instalar o artefato são etapas distintas. A escolha entre APK e AAB depende do perfil de build e da forma de distribuição.',
            'fonte' => 'https://docs.expo.dev/develop/development-builds/introduction/',
        ],
        [
            'termos' => ['estado', 'usestate', 'useref', 'props', 'contador', 'pontuacao', 'app tsx', 'navegar', 'tela ativa'],
            'titulo' => 'React · Estado, referência e composição',
            'conteudo' => 'Estado representa dados que afetam a renderização e é uma fotografia de cada render. Uma atualização funcional calcula o próximo valor a partir do anterior. Ref mantém um valor entre renderizações sem disparar outra. Em uma troca condicional de telas, o pai pode guardar o estado e passar uma prop para que o filho solicite a atualização.',
            'fonte' => 'https://react.dev/learn/state-as-a-snapshot',
        ],
        [
            'termos' => ['algoritmo', 'condicao', 'repeticao', 'se senao', 'loop', 'acumulador', 'vetor', 'lista', 'caso de teste', 'depurar', 'quando acontece', 'passo a passo'],
            'titulo' => 'Programação · Decomposição e teste de mesa',
            'conteudo' => 'Um teste de mesa acompanha a entrada, o estado inicial, cada passo que atualiza esse estado e a saída. Condições separam casos e laços executam regras repetidas. Casos de limite, como lista vazia ou empate, podem revelar comportamentos diferentes dos casos usuais.',
            'fonte' => 'Síntese pedagógica da JOIA',
        ],
        [
            'termos' => ['typescript', 'tipagem', 'tipo de retorno', 'type', 'interface', 'opcional', 'parametro', 'argumento'],
            'titulo' => 'TypeScript · Contratos de dados e funções',
            'conteudo' => 'O parâmetro descreve o que uma função recebe e o retorno descreve o que entrega. Em React, o tipo das props é o contrato entre os componentes e pode distinguir propriedades opcionais das obrigatórias. Uma anotação é verificada em compilação e os dados externos podem ser validados em tempo de execução.',
            'fonte' => 'https://www.typescriptlang.org/docs/handbook/2/everyday-types.html',
        ],
        [
            'termos' => ['fetch', 'http', 'requisicao', 'resposta da api', 'assincrono', 'token', 'json', 'carregando'],
            'titulo' => 'Programação · Fluxo assíncrono entre interface e servidor',
            'conteudo' => 'Um evento de interface pode iniciar um pedido HTTP, passando pelos estados de carregamento, sucesso e erro. Uma resposta 4xx ou 5xx também chega como resposta em fetch. O status HTTP e o corpo JSON fornecem informações distintas ao cliente.',
            'fonte' => 'https://developer.mozilla.org/pt-BR/docs/Web/API/Fetch_API/Using_Fetch',
        ],
        [
            'termos' => ['jsx', 'componente', 'props', 'hierarquia', 'view', 'view pai', 'view mae', 'views maes', 'filhos diretos', 'text', 'renderiza', 'composicao'],
            'titulo' => 'React Native · JSX e hierarquia',
            'conteudo' => 'Pai e mãe nomeiam o mesmo contêiner. Os filhos diretos são os componentes entre as tags dele. O layout aplicado ao contêiner organiza esses filhos, e o estilo aplicado a cada filho altera esse elemento. Uma View pode ter os dois papéis em níveis diferentes. Em style={{ ... }}, as chaves internas formam um objeto e as externas introduzem a expressão em JSX.',
            'fonte' => 'https://reactnative.dev/docs/intro-react',
        ],
    ];
}

function joiaSelectTechnicalReferences(string $message, ?string $code): array
{
    $question = ' ' . normalize($message) . ' ';
    $fragment = ' ' . normalize($code ?? '') . ' ';
    $matches = [];
    foreach (joiaTechnicalCatalog() as $entry) {
        $direct = 0;
        $context = 0;
        foreach ($entry['termos'] as $term) {
            $search = ' ' . $term . ' ';
            if (str_contains($question, $search)) $direct += str_contains($term, ' ') ? 2 : 1;
            if ($code !== null && str_contains($fragment, $search)) $context++;
        }
        if ($direct || $context) {
            $matches[] = [
                'rank' => [$direct, $context],
                'titulo' => $entry['titulo'],
                'conteudo' => $entry['conteudo'],
            ];
        }
    }
    usort($matches, static fn (array $a, array $b): int => $b['rank'] <=> $a['rank']);
    return array_map(static fn (array $entry): array => ['titulo' => $entry['titulo'], 'conteudo' => $entry['conteudo']],
        array_slice($matches, 0, JOIA_TECHNICAL_REFERENCE_LIMIT));
}
