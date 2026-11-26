<?php
declare(strict_types=1);

function joiaTeachingMaterials(): array
{
    // Resumos editoriais das aulas indicadas pelo professor. URLs e arquivos
    // das aulas não são enviados ao modelo nem ao frontend.
    return [
        'animated_jump' => [
            'titulo' => 'React Native 01 · O sapo que pula',
            'contexto' => 'Separe o estado do contador do valor animado. O valor de deslocamento vertical permanece estável entre renderizações, sobe com translateY negativo e volta a zero. No exercício, conte o salto quando o sapo aterrissar e evite sobrepor saltos.',
        ],
        'animated_score' => [
            'titulo' => 'React Native 03 · Conquista desbloqueada',
            'contexto' => 'A pontuação é estado e a escala do ícone é valor animado. Primeiro decida qual ícone corresponde ao marco de pontuação. Depois faça o ícone crescer e voltar ao tamanho original, sem usar a escala como fonte da pontuação.',
        ],
        'animated_bridge' => [
            'titulo' => 'Sapo na Ponte · Atividade dos alunos',
            'contexto' => 'A atividade guiada separa régua, teste da janela verde no instante do toque e salto. A régua percorre posições em ciclo. Verifique acerto ou erro no toque e atualize tentativa ou ponto ao terminar o movimento. Dê pistas por etapa e preserve a implementação como exercício.',
        ],
        'node' => [
            'titulo' => 'Bem-vindo ao Node.js — Guia SENAC para começar o React Native',
            'contexto' => 'O guia apresenta Node como ambiente JavaScript no terminal para ferramentas como Expo e Metro. Diferencie Node, npm e npx. Em falhas no Windows, verifique se o executável é reconhecido no CMD do VS Code e se o PATH está disponível na sessão. Relacione package.json às dependências do projeto.',
        ],
        'nativewind' => [
            'titulo' => 'NativeWind com Expo — Tailwind para React Native',
            'contexto' => 'O material usa Expo com TypeScript, separa configuração de uso e propõe um teste inicial antes de combinar classes. Consulte as utilidades pelo efeito visual e explique a diferença entre aplicar classes e declarar estilos com StyleSheet.',
        ],
        'visual' => [
            'titulo' => 'React Native Visual — Componentes, Estilos, Hierarquia e Layout',
            'contexto' => 'Leia a tela como uma árvore de componentes. Identifique o pai que organiza o layout e os filhos que exibem ou recebem dados. Relacione composição, props, estilos e Flexbox. Escolha View, Text, imagem, entrada, interação ou lista conforme o papel de cada parte.',
        ],
        'first_screen' => [
            'titulo' => 'React Native com TypeScript — Minha Primeira Tela com Expo',
            'contexto' => 'Comece com um projeto Expo em TypeScript e confirme que o projeto abre no celular com Expo Go. Depois monte e personalize a primeira tela com componentes e estilos. Confira o efeito de cada mudança conforme avança.',
        ],
        'apk' => [
            'titulo' => 'Gerando um APK do seu projeto Expo',
            'contexto' => 'É o roteiro de build Android em nuvem com EAS, sem Git no projeto. No CMD, o material usa o modo sem VCS, autenticação e configuração do EAS, e um perfil de prévia para o APK da aula. Confirme o perfil e o tipo de artefato antes de orientar a instalação.',
        ],
        'screens' => [
            'titulo' => 'React Native — Criando várias páginas e navegando entre arquivos',
            'contexto' => 'O exemplo usa componentes de tela em arquivos separados. App.tsx guarda a tela ativa com estado e passa às telas uma função de navegação por props. O toque pede a troca ao componente que controla o estado. Esse roteiro ensina a troca condicional sem depender de uma biblioteca de navegação.',
        ],
    ];
}

function joiaTeachingMaterialKeys(string $text): array
{
    // Match the strongest course concepts first, limiting each turn to two.
    $keys = [];
    $reactNative = preg_match('/\b(?:react native|expo|native ?wind|animated|app tsx)\b/', $text);
    $nativeWind = preg_match('/\bnative ?wind\b/', $text)
        || ($reactNative && preg_match('/\b(?:tailwind|classname)\b/', $text));
    $animated = preg_match('/\banimated\b|\bsapo que pula\b|\bsapo na ponte\b|\bconquista desbloqueada\b/', $text)
        || ($reactNative && preg_match('/\b(?:animac\w*|animar|translatey)\b/', $text));

    if ($nativeWind) $keys[] = 'nativewind';
    if ($animated) {
        $keys[] = match (true) {
            preg_match('/\b(?:sapo na ponte|janela verde|regua)\b/', $text) === 1 => 'animated_bridge',
            preg_match('/\b(?:placar|pontuacao|conquista|pulsar|escala)\b/', $text) === 1 => 'animated_score',
            default => 'animated_jump',
        };
    }
    if (preg_match('/\b(?:eas|apk|gerar build android)\b/', $text)
        && ($reactNative || !preg_match('/\b(?:android studio|kotlin|java nativo|flutter)\b/', $text))) $keys[] = 'apk';
    if (preg_match('/\b(?:node(?: js|js)?|npm|npx|metro|babel|package json|node modules)\b/', $text)) $keys[] = 'node';
    if ($reactNative && preg_match('/\b(?:varias telas|duas telas|mais de uma tela|navegar entre|navegacao entre|trocar(?: de)? tela|telas separadas|arquivo por tela|tela ativa|separ\w* (?:as )?telas|gerenci\w* (?:de |as )?telas)\b/', $text)) {
        $keys[] = 'screens';
    }
    if ($reactNative && preg_match('/\b(?:primeira tela|primeiro (?:app|projeto)|projeto (?:com|em|de) typescript|expo go|projeto novo|criar (?:um )?projeto)\b/', $text)) {
        $keys[] = 'first_screen';
    }
    if ($reactNative && preg_match('/\b(?:flexbox|view|estilos?|styling|componentes?|layout|hierarquia|props|text|lista|imagem)\b/', $text)) {
        $keys[] = 'visual';
    }
    return array_slice($keys, 0, 2);
}

function joiaSelectTeachingMaterials(string $message, array $history): array
{
    $text = normalize($message);
    $keys = joiaTeachingMaterialKeys($text);
    $followUp = '/\b(?:isso|nesse caso|neste caso|nesse exemplo|e agora|ainda|anterior|essa classe|esse componente|essa tela|nessa tela|essa animacao|como aplico|explicar melhor)\b/';
    if (!$keys && preg_match($followUp, $text) && !preg_match('/\b(?:mudando de assunto|outro assunto|python|flutter|django|sql|html|tailwind css na web|reanimated)\b/', $text)) {
        foreach (array_reverse(array_slice($history, -6)) as $turn) {
            if (($turn['role'] ?? null) !== 'user') continue;
            $previous = normalize((string) ($turn['content'] ?? ''));
            $keys = joiaTeachingMaterialKeys($text . ' ' . $previous);
            // Follow a short chain of continuations without crossing
            // an intervening turn on another subject.
            if ($keys || !preg_match($followUp, $previous)) break;
        }
    }

    if (!$keys) return [];
    $catalog = joiaTeachingMaterials();
    return array_map(static fn (string $key): array => $catalog[$key], $keys);
}
