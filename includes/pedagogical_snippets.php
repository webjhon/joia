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
    return $nativeWind === $animated ? null : ($nativeWind ? 'nativewind' : 'animated');
}

function joiaSnippetChangesTopic(string $text): bool
{
    return preg_match('/\b(?:reanimated|tailwind css|tailwind na web|python|java|html|flask|django|sql|banco de dados|node js|express|flutter|swift|kotlin|pdf|outro assunto|novo assunto|mudar de assunto)\b/', $text) === 1;
}

function joiaSnippetTopic(string $message, array $history): ?string
{
    $current = normalize($message);
    $current = preg_replace('/\b(?:esquece|deixa|nao e|nao quero)\s+(?:o\s+|a\s+)?(?:nativewind|native wind|animated)\b/', '', $current, -1, $negatedTopics) ?? $current;
    if ($topic = joiaExplicitSnippetTopic($current)) {
        return $topic === 'animated' && preg_match('/\breanimated\b/', $current) ? null : $topic;
    }
    if ($negatedTopics > 0 || joiaSnippetChangesTopic($current)
        || preg_match('/\b(?:nativewind|native wind)\b.*\banimated\b|\banimated\b.*\b(?:nativewind|native wind)\b/', $current)) return null;

    // The closest relevant turn can establish the topic for a natural
    // follow-up. A new subject named by the learner cuts off older turns.
    foreach (array_reverse(array_slice($history, -6)) as $turn) {
        $role = $turn['role'] ?? '';
        if ($role !== 'user' && $role !== 'assistant') continue;
        $previous = normalize((string) ($turn['content'] ?? ''));
        if ($role === 'user' && joiaSnippetChangesTopic($previous)) return null;
        if ($topic = joiaExplicitSnippetTopic($previous)) return $topic;
    }
    return null;
}

function joiaSnippetWantsFullSolution(string $message): bool
{
    $text = normalize($message);
    $object = '(?:soluc\w*|implementac\w*|codigo|exemplo|projeto|componente|arquivo|app|configurac\w*|animac\w*)';
    $all = '(?:' . $object . '\s+(?:(?:todo|toda)\s+)?(?:complet\w*|inteir\w*|pront\w*)|(?:toda|todo)\s+(?:a|o)\s+' . $object . ')';
    $text = preg_replace('/\b(?:sem|nao (?:quero|preciso(?: de)?|peco|mostre))\s+(?:(?:o|a|um|uma)\s+)?' . $all . '\b/', '', $text) ?? $text;
    return preg_match('/\b' . $all . '\b|\b(?:pront[oa] para|so(?: quero)?|para eu)\s+copiar e colar\b/', $text) === 1;
}

function joiaSnippetInstruction(string $topic): string
{
    $scope = $topic === 'nativewind'
        ? 'Ilustre uma aplicação de className, um trecho pequeno de JSX ou a integração específica do NativeWind que a dúvida pede.'
        : 'Ilustre o ponto de Animated do React Native, como preparar um valor, vinculá-lo ao estilo ou iniciar uma animação simples com timing e start. Mostre apenas a parte que o aluno precisa examinar.';
    return 'Use uma caixa Markdown com até quatro linhas curtas quando isso ajudar. '
        . 'Se o aluno pedir um exemplo para começar ou aplicar o conceito, mostre um fragmento concreto nesse limite, sem exigir tentativa anterior. '
        . $scope . ' Escolha a forma que melhor explique o caso, sem um padrão fixo. '
        . 'Explique o efeito e deixe o próximo passo para o aluno. Evite componentes completos, imports, arquivos de configuração e soluções prontas.';
}

function joiaSnippetAnswerHasCode(string $answer): bool
{
    if (str_contains($answer, '```')) return true;
    $prose = preg_replace('/(?<!`)`Animated\.(?:timing|spring|decay|Value)\(\h*\)`(?!`)/', 'método', $answer) ?? $answer;
    return preg_match('/^\h*~~~|<\/?[A-Za-z][\w.]*[\s\/>]|\bclassName\s*=|`[^`\r\n]*[=(){}<>;][^`\r\n]*`|\b[A-Za-z_$][\w$]*(?:\.[\w$]+)+\s*\(/mu', $prose) === 1
        || codeLineCount($prose) > 0;
}

function joiaSnippetAnswerIsAllowed(string $answer, string $topic): bool
{
    $fence = '/^\h*```(?:jsx|tsx|css|javascript|typescript|js|ts)?\h*\r?\n(.*?)^\h*```\h*(?=\r?$)/ims';
    if (preg_match($fence, $answer, $block, PREG_OFFSET_CAPTURE) !== 1) return false;
    $remaining = substr_replace($answer, '', $block[0][1], strlen($block[0][0]));
    if (joiaSnippetAnswerHasCode($remaining)) return false;

    $lines = array_map('trim', preg_split('/\R/u', $block[1][0]) ?: []);
    $lines = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    if (!$lines || count($lines) > JOIA_SNIPPET_MAX_LINES) return false;
    foreach ($lines as $line) {
        // Keep short fragments from carrying extra statements on one line.
        if (strlen($line) > 160 || substr_count($line, ';') > 1) return false;
    }

    $code = implode("\n", $lines);
    if (joiaSnippetLooksComplete($code)) return false;
    return match ($topic) {
        'nativewind' => preg_match('/\bclassName\s*\??\s*[:=]|\b(?:cssInterop|remapProps|styled)\s*\(|@apply\s+/', $code) === 1,
        'animated' => preg_match('/\bAnimated\s*\.\s*(?:Value(?:XY)?|View|Text|Image|ScrollView|timing|spring|decay|add|multiply|subtract|divide|modulo|diffClamp|event)\b|\.\s*(?:interpolate|setValue|start|stopAnimation|resetAnimation)\s*\(|\b(?:opacity|translate[XY]|scale[XY]?|rotate[XYZ]?)\s*:\s*[A-Za-z_$]/', $code) === 1,
        default => false,
    };
}

function joiaSnippetLooksComplete(string $code): bool
{
    // The length limit is complemented by shape checks. Small JSX and
    // direct method calls remain available without accepting full scaffolds.
    $boilerplate = '/(?:\b(?:import|export|require|function|class|extends|useEffect|useLayoutEffect|useState)\b|=>|\b(?:module\.exports|defineConfig|registerComponent|createRoot|withNativeWind)\b|^\h*(?:npm|npx|yarn|pnpm|bun|expo)\h|@(?:import|tailwind)\b)/m';
    if (preg_match($boilerplate, $code)) return true;
    if (preg_match('/\bon[A-Z][A-Za-z]*\s*=|<[a-z][\w-]*[\s\/>]/', $code)) return true;
    return preg_match_all('/<[A-Z][\w.]*(?=[\s\/>])/', $code) > 3;
}

function joiaHasVisibleCodeFence(string $answer): bool
{
    return preg_match('/^\h*```[\w.+-]*\h*\R(.*?)^\h*```\h*(?=\r?$)/ms', $answer, $block) === 1
        && trim($block[1]) !== '';
}

function joiaClaimsCodeIsShown(string $text, bool $includeReferences = true): bool
{
    $text = normalize($text);
    if (preg_match('/\b(?:aqui esta|aqui vai|segue)\b.{0,80}\b(?:codigo|trecho|bloco)\b/', $text)) return true;
    return $includeReferences && preg_match('/\b(?:o que (?:este|esse|o) codigo (?:faz|mostra)'
        . '|(?:teste|experimente|copie|execute|rode|veja) (?:este|esse|o) (?:codigo|trecho)'
        . '|(?:codigo|trecho|bloco) (?:acima|abaixo|a seguir|mostrado))\b/', $text) === 1;
}

function joiaWantsProse(string $message): bool
{
    return preg_match('/\b(?:sem (?:nenhum )?codigo|em palavras|exemplo conceitual|pseudocodigo|so em texto)\b/', normalize($message)) === 1;
}

function joiaAskedForCodeExample(string $message): bool
{
    $text = normalize($message);
    if (joiaWantsProse($message)
        || preg_match('/\b(?:explic\w*|revis\w*|analis\w*) (?:o |a )?(?:meu|minha) (?:codigo|trecho|implementacao)\b/', $text)) {
        return false;
    }
    return preg_match('/\b(?:exemplo|trecho|pedaco de codigo|sintaxe|fragmento)\b/', $text) === 1;
}

function joiaContextualCodeExample(string $topic, string $message, array $history): ?string
{
    $learnerMessages = [normalize($message)];
    foreach (array_reverse(array_slice($history, -4)) as $turn) {
        if (($turn['role'] ?? '') === 'user') $learnerMessages[] = normalize((string) ($turn['content'] ?? ''));
    }
    foreach ($learnerMessages as $text) {
        if ($topic === 'animated' && preg_match('/\b(?:opacidade|fade(?: in| out)?|fadein|fadeout|(?:aparecer|desaparecer|sumir) suavemente)\b/', $text)) {
            $fadeOut = preg_match('/\b(?:fade ?out|desaparec\w*|sumir|(?:de )?(?:um|1) (?:a|para|ate) (?:zero|0))\b/', $text);
            $start = $fadeOut ? 1 : 0;
            $target = $fadeOut ? 0 : 1;
            $effect = $fadeOut ? 'invisível' : 'visível';
            return "Com um valor animado de opacidade iniciado em {$start}, vincule esse valor ao estilo da `Animated.View`. "
                . "Esta chamada leva o valor até {$target}, tornando o elemento {$effect} gradualmente.\n\n"
                . "```jsx\nAnimated.timing(opacidade, {\n  toValue: {$target},\n  duration: 500, useNativeDriver: true,\n}).start();\n```";
        }
        if ($topic === 'animated' && preg_match('/\b(?:pul\w*|salto|translatey|deslocamento vertical)\b/', $text)) {
            return "Na posição de repouso, o deslocamento vertical é zero. Prepare um valor animado e ligue-o ao deslocamento da `Animated.View`. "
                . "Valores negativos sobem, e voltar a zero faz o elemento aterrissar.\n\n"
                . "```jsx\nconst salto = useRef(new Animated.Value(0)).current;\n<Animated.View style={{ transform: [{ translateY: salto }] }} />\n```";
        }
        if ($topic === 'nativewind' && preg_match('/\b(?:padding|espacamento interno|arredond\w*|rounded lg)\b/', $text)) {
            $spacing = preg_match('/\b(?:padding|espacamento interno)\b/', $text);
            $utility = $spacing ? 'p-4' : 'rounded-lg';
            $effect = $spacing ? 'adiciona espaço entre a borda e os filhos' : 'arredonda os cantos';
            return "Na `View` que precisa do ajuste, `{$utility}` {$effect}. Compare a mesma tela antes e depois de aplicar essa classe.\n\n"
                . "```jsx\n<View className=\"{$utility}\" />\n```";
        }
    }
    return null;
}
