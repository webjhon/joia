<?php
declare(strict_types=1);

const JOIA_TECHNICAL_HISTORY_WINDOW = 24;
const JOIA_TECHNICAL_REFERENCE_LIMIT = 2;

function joiaTechnicalCodeContext(string $message, array $history): ?string
{
    $question = normalize($message);
    if (joiaSnippetTurn($message)['boundary']) return null;
    $followUp = preg_match('/\b(?:isso|nesse|nest[ea]|nessa|naquel[ea]|anterior|esse|essa|aquel[ea])\b'
        . '|\b(?:qual (?:e )?a diferenca|o que (?:significa|faz)|ja usei|lado direito|dois pontos)\b/', $question);
    if (!$followUp) return null;

    preg_match_all('/`([A-Za-z_$][\w$.]*)`/', $message, $quoted);
    preg_match_all('/\b(?:opacity|opacidade|translate[xy]|deslocamento|transform|animated|useref|useState|tovalue|duration|usenativedriver|classname|stylesheet)\b/i', $message, $named);
    $anchors = array_unique(array_map('normalize', array_merge($quoted[1], $named[0])));
    $best = null;
    $bestScore = 0;
    foreach (array_reverse(array_slice($history, -JOIA_TECHNICAL_HISTORY_WINDOW)) as $turn) {
        $content = (string) ($turn['content'] ?? '');
        if (($turn['role'] ?? '') === 'user' && joiaSnippetTurn($content)['boundary']) break;
        if (($turn['role'] ?? '') !== 'assistant') continue;
        // Keep the actual visible fence. Earlier entries can use older language labels.
        if (!preg_match('/^\h*```[\w.+-]*\h*\R.*?^\h*```\h*(?=\R|\z)/ms', $content, $match)
            || strlen($match[0]) > 700) continue;
        if (!$anchors) return $match[0];
        $candidate = ' ' . normalize($match[0]) . ' ';
        $score = 0;
        foreach ($anchors as $anchor) {
            if ($anchor !== '' && str_contains($candidate, ' ' . $anchor . ' ')) $score++;
        }
        if ($score > $bestScore) {
            $best = $match[0];
            $bestScore = $score;
        }
        if ($score === count($anchors)) break;
    }
    return $best;
}

function joiaExplainStyleBinding(string $message, ?string $code): ?string
{
    if ($code === null || !preg_match('/\b(?:qual (?:e )?a diferenca|o que significa|por que (?:tem|usa)|nome da variavel|'
        . 'lado (?:direito|esquerdo)|depois dos dois pontos|que vem depois)\b/', normalize($message))) return null;
    // Limit the local answer to simple property-to-variable bindings actually shown.
    // Expressions, literal values and requests to change the code go to the model.
    if (substr_count($message, '?') > 1 || preg_match('/\b(?:como (?:mudar|alterar|trocar|animo)|'
        . 'e (?:por que|como|onde)|(?:mostra|mostre|manda|mande|da|de) (?:outro|um) (?:exemplo|trecho|codigo))\b/', normalize($message))) return null;
    $properties = [
        'opacity' => 'define a opacidade do elemento. De 0 a 1, o elemento passa de transparente a totalmente opaco',
        'translateX' => 'desloca o elemento na horizontal',
        'translateY' => 'desloca o elemento na vertical. Um valor negativo move para cima',
    ];
    preg_match_all('/\b(opacity|translate[XY])\s*:\s*([A-Za-z_$][\w$]*)(?=\h*[,}\]\r\n]|\z)/', $code, $bindings, PREG_SET_ORDER);
    $question = ' ' . normalize($message) . ' ';
    foreach ($bindings as [, $property, $value]) {
        if (in_array($value, ['true', 'false', 'undefined', 'null'], true)) continue;
        $namesProperty = str_contains($question, ' ' . normalize($property) . ' ');
        $namesValue = str_contains($question, ' ' . normalize($value) . ' ');
        if (!$namesProperty && !$namesValue) continue;
        if (count($bindings) > 1 && !($namesProperty && $namesValue)) return null;
        $variation = $property === 'opacity'
            ? 'Para comparar, em um estilo fixo com `StyleSheet`, o valor de `opacity: 0.5` é sempre 0,5. Um `Animated.Value` pode mudar ao longo do tempo na `Animated.View`.'
            : 'Quando essa variável guarda um `Animated.Value`, a `Animated.View` acompanha a mudança. O valor precisa estar preparado antes dessa linha.';
        return "Em **`{$property}: {$value}`**, **`{$property}` é a propriedade**. Ela {$properties[$property]}.\n\n"
            . "**`{$value}` é a variável que fornece o valor**. O nome pode mudar, desde que corresponda à variável declarada antes.\n\n"
            . $variation;
    }
    return null;
}

function joiaTechnicalCatalog(): array
{
    // Technical notes are independent of the teacher's catalog. Sources are
    // kept for maintenance; the chat gets only the selected explanations.
    return [
        [
            'termos' => ['opacity', 'opacidade', 'stylesheet', 'propriedade valor', 'dois pontos', 'estilo fixo', 'valor animado'],
            'titulo' => 'React Native · Propriedade de estilo e valor',
            'conteudo' => 'Em um objeto de estilos, a chave indica o que muda e a expressão à direita produz o valor. Compare opacity: 0.5 em um StyleSheet com opacity: opacidade em uma Animated.View. A variável precisa estar definida. A opacidade vai de 0 (transparente) a 1 (totalmente opaco).',
            'fonte' => 'https://reactnative.dev/docs/view-style-props#opacity',
        ],
        [
            'termos' => ['animated', 'animated value', 'useref', 'timing', 'tovalue', 'usenativedriver', 'duracao', 'interpolar'],
            'titulo' => 'React Native · Valor e execução do Animated',
            'conteudo' => 'Guarde o mesmo Animated.Value entre renderizações, geralmente com useRef. Demonstre a ligação ao estilo e, em outra etapa, a configuração e o início da animação. Em timing, toValue é o destino e duration é o tempo em milissegundos. O driver nativo pode animar opacity e transform, mas não propriedades de layout como width e flex.',
            'fonte' => 'https://reactnative.dev/docs/animations',
        ],
        [
            'termos' => ['pula', 'pular', 'pulo', 'saltar', 'salto', 'aterrissar', 'translatey', 'efeito de pulo', 'deslocamento vertical', 'sobe e desce', 'sequence'],
            'titulo' => 'React Native · Algoritmo de movimento vertical',
            'conteudo' => 'Modele três pontos observáveis: repouso com translateY zero, subida com destino negativo e retorno a zero. A API Animated.sequence ordena as duas fases. Se o exercício contar saltos, atualize o estado no término confirmado e decida como tratar toques durante o movimento.',
            'fonte' => 'https://reactnative.dev/docs/animations',
        ],
        [
            'termos' => ['nativewind', 'native wind', 'classname', 'tailwind', 'p 4', 'rounded lg', 'bg blue', 'utilitarias'],
            'titulo' => 'NativeWind · Classes, estilos e componentes',
            'conteudo' => 'Classes de className expressam estilos no componente que as recebe; use p-4 para espaçamento interno. Para integrar valor animado e classe fixa, uma Animated.View externa pode cuidar de style e uma View interna de className. Confirme a versão do NativeWind antes de indicar configuração ou integração de componente incompatível.',
            'fonte' => 'https://www.nativewind.dev/docs',
        ],
        [
            'termos' => ['flexbox', 'flex col', 'flex row', 'items center', 'justify center', 'eixo transversal', 'eixo principal', 'layout'],
            'titulo' => 'React Native · Eixos do Flexbox',
            'conteudo' => 'flexDirection define o eixo principal no contêiner. justifyContent atua nesse eixo e alignItems atua no transversal. O padrão do Flexbox no React Native é column. Para className, items-center corresponde ao eixo transversal e justify-center ao principal. Use a direção real da tela no exemplo.',
            'fonte' => 'https://reactnative.dev/docs/flexbox',
        ],
        [
            'termos' => ['expo', 'expo go', 'sdk', 'eas', 'apk', 'aab', 'development build', 'dependencia nativa', 'biblioteca nativa'],
            'titulo' => 'Expo · Ambiente de execução e build',
            'conteudo' => 'Identifique o SDK e o ambiente do aluno antes de indicar comandos. Expo Go traz um conjunto fixo de módulos nativos; uma development build pode incluir os do projeto. Diferencie abrir um projeto local, criar a build Android e instalar o artefato. Confira perfil e formato APK ou AAB conforme o objetivo.',
            'fonte' => 'https://docs.expo.dev/develop/development-builds/introduction/',
        ],
        [
            'termos' => ['estado', 'usestate', 'useref', 'props', 'contador', 'pontuacao', 'app tsx', 'navegar', 'tela ativa'],
            'titulo' => 'React · Estado, referência e composição',
            'conteudo' => 'Estado representa dados que afetam a renderização e é uma fotografia de cada render. Use atualização funcional se o próximo valor depende do anterior. Ref mantém um valor entre renderizações sem disparar outra. Localize quem possui a informação e quem a recebe por props. Em uma troca condicional de telas, uma ação do filho pode pedir a alteração de estado ao pai.',
            'fonte' => 'https://react.dev/learn/state-as-a-snapshot',
        ],
        [
            'termos' => ['algoritmo', 'condicao', 'repeticao', 'se senao', 'loop', 'acumulador', 'vetor', 'lista', 'caso de teste', 'depurar', 'quando acontece', 'passo a passo'],
            'titulo' => 'Programação · Decomposição e teste de mesa',
            'conteudo' => 'Identifique entrada, estado inicial, regra que atualiza o estado e saída observável. Dê um exemplo concreto com valores pequenos e acompanhe estado antes, passo executado e estado depois. Use uma condição para separar casos e um laço quando a regra se repete. Compare um caso normal e um limite, como lista vazia ou empate, quando isso muda o resultado.',
            'fonte' => 'Síntese pedagógica da JOIA',
        ],
        [
            'termos' => ['typescript', 'tipagem', 'tipo de retorno', 'type', 'interface', 'opcional', 'parametro', 'argumento'],
            'titulo' => 'TypeScript · Contratos de dados e funções',
            'conteudo' => 'O parâmetro descreve o que uma função recebe e o retorno descreve o que entrega. Em React, o tipo das props é o contrato entre os componentes. Distinga uma propriedade opcional de uma obrigatória. Uma anotação de tipo orienta a compilação, enquanto dados vindos da rede precisam de verificação em tempo de execução.',
            'fonte' => 'https://www.typescriptlang.org/docs/handbook/2/everyday-types.html',
        ],
        [
            'termos' => ['fetch', 'http', 'requisicao', 'resposta da api', 'assincrono', 'token', 'json', 'carregando'],
            'titulo' => 'Programação · Fluxo assíncrono entre interface e servidor',
            'conteudo' => 'Identifique o evento que inicia o pedido, o contrato HTTP e os estados de carregamento, sucesso e erro da interface. Uma resposta HTTP 4xx ou 5xx também chega como resposta no fetch; confira o estado HTTP e valide o JSON antes de atualizar a tela. Separe o estado visual dos dados que o servidor confirma.',
            'fonte' => 'https://developer.mozilla.org/pt-BR/docs/Web/API/Fetch_API/Using_Fetch',
        ],
        [
            'termos' => ['jsx', 'componente', 'props', 'hierarquia', 'view', 'text', 'renderiza', 'composicao'],
            'titulo' => 'React Native · JSX e hierarquia',
            'conteudo' => 'O nome da tag escolhe o componente. Atributos em JSX são props e chaves externas introduzem uma expressão JavaScript. Em style={{ ... }}, as chaves internas formam um objeto. A hierarquia define quem envolve e posiciona quem; View agrupa elementos e Text exibe texto.',
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
