<?php
declare(strict_types=1);

const JOIA_SNIPPET_MAX_LINES = 4;

function joiaExplicitSnippetTopic(string $text): ?string
{
    $reactNative = preg_match('/\breact native\b/', $text) === 1;
    $nativeWind = preg_match('/\b(?:nativewind|native wind)\b/', $text) === 1
        || ($reactNative && preg_match('/\b(?:tailwind|classname|cssinterop|remapprops)\b/', $text) === 1);
    $animated = preg_match('/\banimated\b/', $text) === 1
        || ($reactNative && preg_match('/\b(?:animac\w*|interpol\w*|driver nativo)\b/', $text) === 1);
    if ($nativeWind && $animated) return 'combined';
    return $nativeWind ? 'nativewind' : ($animated ? 'animated' : null);
}

function joiaSnippetChangesTopic(string $text): bool
{
    return preg_match('/\b(?:reanimated|tailwind css|tailwind na web|python|java|html|flask|django|sql|banco de dados|node js|express|flutter|swift|kotlin|pdf|rotas? (?:http|rest|da api|do servidor)|outro assunto|novo assunto|mudar de assunto)\b/', $text) === 1;
}

function joiaSnippetTurn(string $text): array
{
    $text = normalize($text);
    $excluded = [];
    $text = preg_replace_callback(
        '/\b(?:sem|esquec[ae]|deix[ae]|ignor[ae]|dispens[ae]|nao (?:e|quero|uso|use|estou usando|vou usar|pretendo usar))\s+'
        . '(?:(?:o|a)\s+)?(nativewind|native wind|reanimated|animated|react native)\b/',
        static function (array $match) use (&$excluded): string {
            $excluded[] = $match[1] === 'native wind' ? 'nativewind' : $match[1];
            return '';
        },
        $text
    ) ?? $text;
    $topic = joiaExplicitSnippetTopic($text);
    $changesSubject = preg_match('/\b(?:mudando de assunto|mudar de assunto|outro assunto|novo assunto)\b'
        . '|\b(?:agora|voltei|voltando|voltar|mudei|mudando|mudar|troquei|trocando|trocar)\b.{0,38}'
        . '\b(?:para|pra|sobre) (?:python|java|html|flask|django|sql|node js|express|flutter|swift|kotlin|reanimated|tailwind na web)\b/', $text) === 1;
    $boundary = in_array('react native', $excluded, true)
        || preg_match('/\bfora (?:do|de) react native\b/', $text) === 1
        || ($topic === null && joiaSnippetChangesTopic($text))
        || ($changesSubject && joiaSnippetChangesTopic($text)
            && !preg_match('/\b(?:assunto|para|pra|sobre) (?:e |o )?(?:nativewind|native wind|animated|react native)\b/', $text))
        || ($topic !== null && $topic !== 'nativewind' && preg_match('/\breanimated\b/', $text));
    return ['topic' => $topic, 'excluded' => $excluded, 'boundary' => $boundary, 'text' => $text];
}

function joiaSnippetTopicWithout(string $topic, array $excluded): ?string
{
    if ($topic !== 'combined') return in_array($topic, $excluded, true) ? null : $topic;
    $nativeWind = !in_array('nativewind', $excluded, true);
    $animated = !in_array('animated', $excluded, true);
    if ($nativeWind && $animated) return 'combined';
    return $nativeWind ? 'nativewind' : ($animated ? 'animated' : null);
}

function joiaSnippetTopic(string $message, array $history, ?string &$anchor = null): ?string
{
    $anchor = null;
    $current = joiaSnippetTurn($message);
    if ($current['boundary']) return null;
    if ($current['topic'] !== null) return joiaSnippetTopicWithout($current['topic'], $current['excluded']);
    if (preg_match('/^(?:oi|ola|bom dia|boa tarde|boa noite|obrigad[oa]|valeu|tchau)$/D', $current['text'])) return null;

    // The student's latest explicit subject remains active across follow-ups.
    // Assistant turns can supply a topic only when no learner turn names one.
    $excluded = $current['excluded'];
    $assistantTopic = null;
    foreach (array_reverse($history) as $turn) {
        $role = $turn['role'] ?? null;
        if ($role !== 'user' && $role !== 'assistant') continue;
        $previous = joiaSnippetTurn((string) ($turn['content'] ?? ''));
        if ($role === 'assistant') {
            if ($assistantTopic === null && !$previous['boundary']) $assistantTopic = $previous['topic'];
            continue;
        }
        if ($previous['boundary']) return null;
        $excluded = array_merge($excluded, $previous['excluded']);
        if ($previous['topic'] !== null) {
            $topic = joiaSnippetTopicWithout($previous['topic'], $excluded);
            if ($topic !== null) $anchor = (string) ($turn['content'] ?? '');
            return $topic;
        }
    }
    return $assistantTopic !== null ? joiaSnippetTopicWithout($assistantTopic, $excluded) : null;
}

function joiaSnippetWantsFullSolution(string $message): bool
{
    $text = normalize($message);
    $object = '(?:soluc\w*|implementac\w*|codigo|exemplo|projeto|componente|arquivo|app|configurac\w*|animac\w*|exercicio|prova|tarefa)';
    $all = '(?:' . $object . '\s+(?:(?:todo|toda)\s+)?(?:complet\w*|inteir\w*|pront\w*)|(?:toda|todo)\s+(?:a|o)\s+' . $object . ')';
    $text = preg_replace('/\b(?:sem|nao (?:quero|preciso(?: de)?|peco|mostre))\s+(?:(?:o|a|um|uma)\s+)?' . $all . '\b/', '', $text) ?? $text;
    return preg_match('/\b' . $all . '\b|\b(?:pront[oa] para|so(?: quero)?|para eu)\s+copiar e colar\b/', $text) === 1;
}

function joiaSnippetInstruction(string $topic): string
{
    $scope = match ($topic) {
        'nativewind' => 'Ilustre uma aplicação de className, um trecho pequeno de JSX ou a integração específica do NativeWind que a dúvida pede.',
        'combined' => 'Mostre a ligação entre as utilidades do NativeWind e o valor do Animated do React Native no mesmo fragmento. Use className para o estilo fixo e style da Animated.View para a propriedade animada, inclusive com uma View interna quando isso simplificar o exemplo.',
        default => 'Ilustre o ponto de Animated do React Native, como preparar um valor, vinculá-lo ao estilo ou iniciar uma animação simples com timing e start. Mostre apenas a parte que o aluno precisa examinar.',
    };
    return 'Use uma caixa Markdown com até quatro linhas curtas quando isso ajudar. '
        . 'Se o aluno pedir um exemplo para começar ou aplicar o conceito, mostre um fragmento concreto nesse limite, sem exigir tentativa anterior. '
        . $scope . ' Escolha a forma que melhor explique o caso, sem um padrão fixo. '
        . 'Explique o efeito e deixe o próximo passo para o aluno. Evite componentes completos, imports, arquivos de configuração e soluções prontas.';
}

function joiaSnippetAnswerHasCode(string $answer): bool
{
    if (str_contains($answer, '```')) return true;
    $prose = preg_replace('/(?<!`)`Animated\.(?:timing|spring|decay|Value)\(\h*\)(?:\.start\(\h*\))?`(?!`)/', 'método', $answer) ?? $answer;
    return preg_match('/^\h*~~~|<\/?[A-Za-z][\w.]*[\s\/>]|\bclassName\s*=|`[^`\r\n]*[=(){}<>;][^`\r\n]*`|\b[A-Za-z_$][\w$]*(?:\.[\w$]+)+\s*\(/mu', $prose) === 1
        || codeLineCount($prose) > 0;
}

function joiaSnippetAnswerIsAllowed(string $answer, string $topic): bool
{
    $fence = '/^\h*```(?:jsx|tsx|css|javascript|typescript|js|ts)\h*\r?\n(.*?)\r?\n^\h*```\h*(?=\r?$)/ims';
    if (preg_match($fence, $answer, $block, PREG_OFFSET_CAPTURE) !== 1) return false;
    $remaining = substr_replace($answer, '', $block[0][1], strlen($block[0][0]));
    if (joiaSnippetAnswerHasCode($remaining)) return false;

    $lines = preg_split('/\R/u', $block[1][0]) ?: [];
    if (trim($block[1][0]) === '' || count($lines) > JOIA_SNIPPET_MAX_LINES) return false;
    foreach ($lines as $line) {
        // Keep short fragments from carrying extra statements on one line.
        if (strlen($line) > 160 || substr_count($line, ';') > 1) return false;
    }

    $code = preg_replace('!^\h*//[^\r\n]*|/\*.*?\*/!ms', '', $block[1][0]) ?? '';
    if (joiaSnippetLooksComplete($code)) return false;
    $nativeWind = preg_match('/\bclassName\s*\??\s*[:=]|\b(?:cssInterop|remapProps|styled)\s*\(|@apply\s+/', $code) === 1;
    $animated = preg_match('/\bAnimated\s*\.\s*(?:Value(?:XY)?|View|Text|Image|ScrollView|timing|spring|decay|add|multiply|subtract|divide|modulo|diffClamp|event)\b|\.\s*(?:interpolate|setValue|start|stopAnimation|resetAnimation)\s*\(/', $code) === 1;
    return match ($topic) {
        'nativewind' => $nativeWind,
        'animated' => $animated || preg_match('/\b(?:opacity|translate[XY]|scale[XY]?|rotate[XYZ]?)\s*:\s*[A-Za-z_$]/', $code) === 1,
        'combined' => $nativeWind && $animated,
        default => false,
    };
}

function joiaSnippetLooksComplete(string $code): bool
{
    // The length limit is complemented by shape checks. Small JSX and
    // direct method calls remain available without accepting full scaffolds.
    $boilerplate = '/(?:\b(?:import|export|require|function|class|extends|useEffect|useLayoutEffect|useState)\b|=>|\b(?:module\.exports|defineConfig|registerComponent|createRoot|withNativeWind)\b|^\h*(?:npm|npx|yarn|pnpm|bun|expo)\h|@(?:import|tailwind)\b)/m';
    if (preg_match($boilerplate, $code)) return true;
    if (preg_match('/\b(?:fetch|XMLHttpRequest|readFile(?:Sync)?|writeFile(?:Sync)?|eval|setTimeout|setInterval|alert|prompt)\s*\(|\b(?:axios|console|process|document|window)\s*\./', $code)) return true;
    if (preg_match('/\bon[A-Z][A-Za-z]*\s*=|<[a-z][\w-]*[\s\/>]/', $code)) return true;
    return preg_match_all('/<[A-Z][\w.]*(?=[\s\/>])/', $code) > 3;
}

function joiaClaimsCodeIsShown(string $text, bool $includeReferences = true): bool
{
    $text = normalize($text);
    $intro = '(?:aqui (?:esta|vai|tem)|segue|veja a seguir|(?:vou|posso) (?:mostrar|passar|dar|escrever)|abaixo (?:esta|vai|segue))';
    if (preg_match('/\b' . $intro . '\b.{0,65}\b(?:codigo|trecho|bloco|caixa de codigo)\b/', $text)) return true;
    if (preg_match('/\b' . $intro . '\s+(?:um |o )?exemplo\b(?! (?:em palavras|conceitual|em prosa|sem codigo|verbal)\b)/', $text)) return true;
    return $includeReferences && preg_match('/\b(?:o que (?:este|esse|o) codigo (?:faz|mostra)'
        . '|explicacao (?:do|deste|desse) (?:codigo|trecho|bloco)'
        . '|(?:teste|experimente|copie|execute|rode|veja) (?:este|esse|o) (?:codigo|trecho|bloco)'
        . '|(?:codigo|trecho|bloco|exemplo) (?:acima|abaixo|a seguir|mostrado|exibido))\b/', $text) === 1;
}

function joiaWantsProse(string $message): bool
{
    return preg_match('/\b(?:sem (?:nenhum |mostrar )?codigo|nao (?:quero|preciso de|precisa de) codigo|'
        . 'em palavras|exemplo conceitual|pseudocodigo|(?:so|apenas) em texto)\b/', normalize($message)) === 1;
}

function joiaAskedForCodeExample(string $message): bool
{
    $text = normalize($message);
    if (joiaWantsProse($message)
        || preg_match('/\b(?:explic\w*|revis\w*|analis\w*) (?:o |a )?(?:meu|minha|este|esse|esta|essa) (?:codigo|trecho|implementacao)\b/', $text)) {
        return false;
    }
    return preg_match('/\b(?:exemplo|trecho|sintaxe|fragmento|pode mostrar|como (?:comeco|faco|escrevo|aplico|coloco|combino|fica)|'
        . 'por onde comeco|na pratica|(?:quero|da|mande|mostre|preciso de|vi|vejo|veio) (?:so (?:um )?|um |o |nenhum )?codigo)\b/', $text) === 1;
}

function joiaSnippetExampleRequests(string $topic, string $message, array $history): array
{
    $requests = [normalize($message)];
    foreach (array_reverse($history) as $turn) {
        if (($turn['role'] ?? '') !== 'user') continue;
        $previous = joiaSnippetTurn((string) ($turn['content'] ?? ''));
        if ($previous['boundary'] || in_array($topic, $previous['excluded'], true)) break;
        if ($topic !== 'combined' && $previous['topic'] !== null
            && !in_array($previous['topic'], [$topic, 'combined'], true)) break;
        $requests[] = $previous['text'];
        if (count($requests) >= 9) break;
    }
    return $requests;
}

function joiaSnippetExampleParts(string $topic, string $message, array $history): ?array
{
    $requests = joiaSnippetExampleRequests($topic, $message, $history);
    $motion = null;
    $style = null;
    foreach ($requests as $text) {
        if ($motion === null) {
            $motion = match (true) {
                preg_match('/\b(?:opacidade|fade(?: in| out)?|fadein|fadeout|(?:aparecer|desaparecer|sumir) suavemente)\b/', $text) === 1
                    => preg_match('/\b(?:fade ?out|desaparec\w*|sumir|(?:de )?(?:um|1) (?:a|para|ate) (?:zero|0))\b/', $text) ? 'fadeout' : 'fadein',
                preg_match('/\b(?:translatex|deslocamento horizontal|movimento horizontal|deslizar para o lado)\b/', $text) === 1 => 'translateX',
                preg_match('/\b(?:pul\w*|salto|translatey|deslocamento vertical|movimento vertical)\b/', $text) === 1 => 'translateY',
                preg_match('/\b(?:escala|scale|pulsar)\b/', $text) === 1 => 'scale',
                default => null,
            };
            if ($motion === null && $topic !== 'nativewind'
                && preg_match('/\b(?:rotac\w*|girar|rotate\w*|loop|sequence|parallel|gesto)\b/', $text)) return null;
        }
        if ($style === null) {
            $style = match (true) {
                preg_match('/\b(?:padding|espacamento interno|p 4)\b/', $text) === 1
                    => ['p-4', 'adiciona espaço entre a borda e os filhos'],
                preg_match('/\b(?:arredond\w*|rounded lg)\b/', $text) === 1
                    => ['rounded-lg', 'arredonda os cantos'],
                preg_match('/\b(?:centraliz\w*|central)[^.!?]*\b(?:filh\w*|card|conteudo|itens)\b|\bitems center\b/', $text) === 1
                    => ['items-center', 'centraliza os filhos no eixo transversal do contêiner'],
                preg_match('/\b(?:fundo azul|bg blue)\b/', $text) === 1
                    => ['bg-blue-500', 'deixa o fundo azul'],
                preg_match('/\b(?:fundo vermelh\w*|bg red)\b/', $text) === 1
                    => ['bg-red-500', 'deixa o fundo vermelho'],
                default => null,
            };
        }
        if ($motion !== null && $style !== null) break;
    }

    if ($topic === 'combined') {
        [$utility, $effect] = $style ?? ['p-4', 'adiciona espaçamento interno'];
        [$property, $goal] = match ($motion) {
            'fadeout' => ['opacity: opacidade', 'diminua de 1 para 0 para desaparecer'],
            'translateX' => ['transform: [{ translateX: deslocamento }]', 'varie o valor para mover o cartão da esquerda para a direita'],
            'translateY' => ['transform: [{ translateY: deslocamento }]', 'use valores negativos para subir e volte a zero para retornar ao repouso'],
            'scale' => ['transform: [{ scale: tamanho }]', 'varie o valor em torno de 1 para mudar o tamanho do cartão'],
            default => ['opacity: opacidade', 'aumente de 0 para 1 para aparecer aos poucos'],
        };
        return [
            "Separe o estilo fixo do valor animado: a classe `{$utility}` {$effect} na `View` interna. "
                . "Na `Animated.View` externa, use um valor animado já preparado no estilo e {$goal}. "
                . "O efeito passa para o conteúdo interno sem mexer nas classes.",
            "<Animated.View style={{ {$property} }}>\n  <View className=\"{$utility}\" />\n</Animated.View>",
        ];
    }

    if ($topic === 'animated' && ($motion === 'fadein' || $motion === 'fadeout')) {
        $fadeOut = $motion === 'fadeout';
        $start = $fadeOut ? 1 : 0;
        $target = $fadeOut ? 0 : 1;
        $effect = $fadeOut ? 'invisível' : 'visível';
        return [
            "Com um valor animado de opacidade iniciado em {$start}, ligue esse valor ao estilo da `Animated.View`. "
                . "Faça o valor chegar a {$target} para o elemento ficar {$effect} gradualmente.",
            "Animated.timing(opacidade, {\n  toValue: {$target},\n  duration: 500, useNativeDriver: true,\n}).start();",
        ];
    }
    if ($topic === 'animated' && $motion === 'translateY') {
        return [
            'Na posição de repouso, o deslocamento vertical é zero. Prepare um valor animado e ligue-o ao deslocamento da `Animated.View`. '
                . 'Valores negativos sobem, e voltar a zero faz o elemento aterrissar.',
            "const salto = useRef(new Animated.Value(0)).current;\n<Animated.View style={{ transform: [{ translateY: salto }] }} />",
        ];
    }
    if ($topic === 'animated' && ($motion === 'translateX' || $motion === 'scale')) {
        [$property, $initial, $effect] = $motion === 'translateX'
            ? ['translateX', 0, 'mude o deslocamento inicial de zero para mover o elemento na horizontal']
            : ['scale', 1, 'varie em torno de 1 para aumentar ou diminuir o elemento'];
        return [
            "Ligue o valor animado à propriedade `{$property}` da `Animated.View`. Em um teste, {$effect}.",
            "const valor = useRef(new Animated.Value({$initial})).current;\n"
                . "<Animated.View style={{ transform: [{ {$property}: valor }] }} />",
        ];
    }

    if ($topic === 'nativewind' && $style !== null) {
        [$utility, $effect] = $style;
        return [
            "Na `View` que precisa do ajuste, `{$utility}` {$effect}. Compare a mesma tela antes e depois de aplicar essa classe.",
            "<View className=\"{$utility}\" />",
        ];
    }
    return null;
}

function joiaContextualCodeExample(string $topic, string $message, array $history): ?string
{
    $parts = joiaSnippetExampleParts($topic, $message, $history);
    return $parts === null ? null : "{$parts[0]}\n\n```jsx\n{$parts[1]}\n```";
}

function joiaContextualProse(string $topic, string $message, array $history): ?string
{
    return joiaSnippetExampleParts($topic, $message, $history)[0] ?? null;
}
