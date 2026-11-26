<?php
declare(strict_types=1);

function joiaExplicitSnippetTopic(string $text): ?string
{
    $nativeWind = preg_match('/\b(?:nativewind|native wind)\b/', $text) === 1;
    $animated = preg_match('/\banimated\b|\banimac\w*\s+(?:com|do|no|nativo do)\s+react native\b/', $text) === 1;
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
        if ($topic = joiaExplicitSnippetTopic($previous)) {
            $shared = 'isso|isto|ainda|entendi|exemplo|codigo|sintaxe|aplic\w*|coloc\w*|uso|usar|fica|faco|tent\w*|aqui|nesse caso|na pratica|mostr\w*';
            $subject = $topic === 'nativewind'
                ? 'class\w*|estil\w*|view|text|container|centraliz\w*|alinh\w*|flex\w*|borda|cor|espac\w*'
                : 'animac\w*|interpol\w*|timing|spring|decay|opacidade|escala|transform\w*|duracao|atraso|moviment\w*|driver|valor';
            return preg_match('/\b(?:' . $shared . '|' . $subject . ')\b/', $current) ? $topic : null;
        }
        if ($role === 'user' && joiaSnippetChangesTopic($previous)) return null;
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
    $shape = $topic === 'nativewind'
        ? 'No NativeWind, mostre em uma linha um className curto com até três utilidades, sozinho ou aplicado a uma View ou Text autofechada com apenas essa propriedade.'
        : 'No Animated nativo do React Native, mostre em até duas linhas apenas o ponto relevante. Pode ser a criação de Animated.Value (inclusive em useRef), sua ligação ao estilo de uma Animated.View, uma chamada isolada de timing, spring, decay, interpolate, setValue ou start. Duas linhas só para ligar um Animated.Value ao estilo de uma Animated.View. Use números simples, sem rotinas completas de animação.';
    return 'Se um pequeno trecho ajudar a entender ou testar a dúvida atual, você pode incluir uma única caixa Markdown jsx ou tsx. Explique o efeito do trecho e deixe o próximo passo para o aluno. '
        . $shape . ' Use somente um fragmento didático, sem imports, componentes completos, configurações inteiras, lógica de aplicativo ou solução pronta.';
}

function joiaSnippetAnswerHasCode(string $answer): bool
{
    if (str_contains($answer, '```')) return true;
    $prose = preg_replace('/(?<!`)`Animated\.(?:timing|spring|decay|Value)\(\h*\)`(?!`)/', 'método', $answer) ?? $answer;
    return preg_match('/^\h*~~~|<\/?[A-Za-z][\w.]*[\s\/>]|\bclassName\s*=|`[^`\r\n]*[=(){}<>;][^`\r\n]*`|\b[A-Za-z_$][\w$]*(?:\.[\w$]+)+\s*\(/mu', $prose) === 1
        || codeLineCount($prose) > 0;
}

function joiaNativeWindSnippetIsAllowed(string $line): bool
{
    if (strlen($line) > 132) return false;
    $utility = '[A-Za-z0-9!][A-Za-z0-9!_:.\/-]{0,29}';
    $classes = $utility . '(?:\h+' . $utility . '){0,2}';
    $attribute = "className\\h*=\\h*(?:\"" . $classes . "\"|'" . $classes . "')";
    if (preg_match('~\A' . $attribute . '\z~D', $line)) return true;
    return preg_match('~\A<(?:View|Text)\h+' . $attribute . '\h*/>\z~D', $line) === 1;
}

function joiaAnimatedLineKind(string $line): ?string
{
    if (strlen($line) > 140) return null;
    $name = '[A-Za-z_$][A-Za-z0-9_$]{0,31}';
    $number = '-?\d{1,5}(?:\.\d{1,5})?';
    $declaration = '(?:(?:const|let)\h+' . $name . '\h*=\h*)?';
    $value = 'new\h+Animated\.Value\(\h*(?:' . $number . '|' . $name . ')\h*\)';
    if (preg_match('~^' . $declaration . '(?:' . $value . '|useRef\(\h*' . $value . '\h*\)\.current);?$~D', $line)) return 'value';

    $styleValue = '(?:' . $name . '|' . $number . ')';
    $property = '(?:opacity|width|height|left|top|right|bottom)\h*:\h*' . $styleValue;
    $transform = 'transform\h*:\h*\[\h*\{\h*(?:translateX|translateY|scale|scaleX|scaleY|rotate)\h*:\h*' . $styleValue . '\h*}\h*\]';
    if (preg_match('~^<Animated\.View\h+style\h*=\h*\{\h*\{\h*(?:' . $property . '|' . $transform . ')\h*}\h*}\h*/>$~D', $line)) return 'element';

    $setting = '(?:toValue\h*:\h*' . $styleValue
        . '|(?:duration|delay|friction|tension|bounciness|velocity|deceleration|stiffness|damping|mass)\h*:\h*' . $number
        . '|(?:useNativeDriver|isInteraction|overshootClamping)\h*:\h*(?:true|false))';
    if (preg_match('~^' . $declaration . 'Animated\.(?:timing|spring|decay)\(\h*' . $name . '\h*,\h*\{\h*' . $setting . '(?:\h*,\h*' . $setting . '){0,4}\h*,?\h*}\h*\);?$~D', $line)) return 'operation';

    $unit = $number . '(?:deg|rad|%)';
    $rangeValue = '(?:' . $number . '|"' . $unit . '"|' . "'" . $unit . "')";
    $range = '\[\h*' . $rangeValue . '(?:\h*,\h*' . $rangeValue . '){1,3}\h*\]';
    if (preg_match('~^' . $declaration . $name . '\.interpolate\(\h*\{\h*inputRange\h*:\h*' . $range . '\h*,\h*outputRange\h*:\h*' . $range . '\h*,?\h*}\h*\);?$~D', $line)) return 'operation';
    if (preg_match('~^' . $name . '\.(?:setValue\(\h*' . $styleValue . '\h*\)|(?:start|stop|reset|stopAnimation|resetAnimation)\(\h*\));?$~D', $line)) return 'operation';
    return null;
}

function joiaSnippetAnswerIsAllowed(string $answer, string $topic): bool
{
    if (!preg_match('/^\h*```(?:jsx|tsx|javascript|typescript|js|ts)?\h*\R(.*?)^\h*```\h*(?=\r?$)/ims', $answer, $block, PREG_OFFSET_CAPTURE)) return false;
    $remaining = substr_replace($answer, '', $block[0][1], strlen($block[0][0]));
    if (joiaSnippetAnswerHasCode($remaining)) return false;

    $lines = array_map('trim', preg_split('/\R/u', trim($block[1][0])) ?: []);
    if ($topic === 'nativewind') return count($lines) === 1 && joiaNativeWindSnippetIsAllowed($lines[0]);
    if ($topic !== 'animated' || !$lines || count($lines) > 2) return false;

    $kinds = array_map('joiaAnimatedLineKind', $lines);
    if (in_array(null, $kinds, true)) return false;
    return count($kinds) === 1 || $kinds === ['value', 'element'];
}
