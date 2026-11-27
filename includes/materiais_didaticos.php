<?php
declare(strict_types=1);

function joiaTeachingMaterials(): array
{
    // Resumos editoriais das aulas indicadas pelo professor. URLs e arquivos
    // das aulas não são enviados ao modelo nem ao frontend.
    return [
        'animated_jump' => [
            'titulo' => 'React Native 01 · O sapo que pula',
            'contexto' => 'O exemplo liga o valor animado `altura` ao deslocamento vertical com `translateY`. O valor começa em zero, fica negativo na subida e volta a zero na descida. `Animated.sequence` organiza as duas etapas, e o contador só avança após a aterrissagem. Para conferir o raciocínio, altere somente o tempo da descida e observe que a altura permanece igual.',
            'localizador' => 'O que acontece na tela',
        ],
        'animated_score' => [
            'titulo' => 'React Native 03 · Conquista desbloqueada',
            'contexto' => 'A pontuação e a escala do ícone têm papéis diferentes. A pontuação determina a conquista, e a escala faz o ícone crescer e voltar ao tamanho original. Compare o comportamento um ponto antes, na meta e um ponto depois.',
            'localizador' => 'O que acontece na tela',
        ],
        'animated_bridge' => [
            'titulo' => 'Sapo na Ponte · Atividade dos alunos',
            'contexto' => 'Separe o deslocamento do sapo da posição da bolinha. A etapa 3 decide o acerto no instante do toque, e a etapa 4 usa o valor animado para subir e voltar a zero antes de atualizar o placar. Verifique que a régua pausa durante o salto e que dois toques rápidos não contam duas tentativas.',
            'localizador' => '4 · Dê movimento ao sapo',
        ],
        'node' => [
            'titulo' => 'Bem-vindo ao Node.js — Guia SENAC para começar o React Native',
            'contexto' => 'O guia situa o Node como ambiente para as ferramentas do React Native. Compare o npm, que administra as dependências do projeto, com o npx, que executa uma ferramenta de linha de comando. Para um problema de terminal no Windows, consulte também a parte sobre CMD e PATH.',
            'localizador' => '6 Entendendo o npm e 7 Entendendo o npx',
        ],
        'nativewind' => [
            'titulo' => 'NativeWind com Expo — Tailwind para React Native',
            'contexto' => 'Leia cada classe pelo efeito que ela descreve. O material separa a configuração do uso no componente e propõe um teste inicial. Aplique uma utilidade visual a um elemento simples antes de combinar as classes.',
            'localizador' => '4. Como ler uma sequência de classes',
        ],
        'visual' => [
            'titulo' => 'React Native Visual — Componentes, Estilos, Hierarquia e Layout',
            'contexto' => 'A tela tem uma hierarquia de componentes. Identifique qual View organiza os filhos e o que cada filho deve exibir ou receber. Depois confira a direção do contêiner e qual eixo é afetado pelo alinhamento.',
            'localizador' => '1. Modelo mental: uma tela é uma árvore',
        ],
        'first_screen' => [
            'titulo' => 'React Native com TypeScript — Minha Primeira Tela com Expo',
            'contexto' => 'Confirme primeiro que o projeto Expo com TypeScript abre no celular pelo Expo Go. Na parte de montar a primeira tela, use textos, blocos e estilos. Mude um elemento de cada vez e confira o efeito na tela.',
            'localizador' => '1. Antes de começar',
        ],
        'apk' => [
            'titulo' => 'Gerando um APK do seu projeto Expo',
            'contexto' => 'O roteiro prepara um build Android com EAS e usa o perfil de prévia para produzir um APK. Confira o perfil selecionado e o tipo de artefato. A compilação ocorre nos servidores do Expo.',
            'localizador' => '5 Gerar o APK',
        ],
        'screens' => [
            'titulo' => 'React Native — Criando várias páginas e navegando entre arquivos',
            'contexto' => 'No roteiro, cada tela é um componente separado. O App.tsx guarda a tela ativa em estado e passa às telas uma função por props. Na parte 8, observe como uma página solicita a troca e como o App decide qual tela mostrar.',
            'localizador' => '6. O App.tsx vai controlar qual página aparece',
        ],
    ];
}

function joiaTeachingMaterialKeys(string $text): array
{
    $keys = [];
    $reactNative = preg_match('/\b(?:react native|expo|native ?wind|animated|app tsx)\b/', $text);
    $nativeWind = preg_match('/\bnative ?wind\b/', $text)
        || ($reactNative && preg_match('/\b(?:tailwind|classname)\b/', $text));
    $bridge = preg_match('/\b(?:sapo na ponte|janela verde)\b/', $text)
        || (preg_match('/\bregua\b/', $text) && preg_match('/\b(?:bolinha|tentativas|salto|sapo)\b/', $text));
    $jump = preg_match('/\b(?:sapo que pula|salto vertical|efeito de pulo|translatey|translacao vertical|deslocamento vertical)\b/', $text);
    if (!$jump && !preg_match('/\b(?:pulo|pular|salto|saltar) (?:a |uma |de )?(?:linha|etapa|tela|fase)\b/', $text)) {
        $jump = preg_match('/\b(?:objeto|elemento|sapo|personagem|imagem)\b.{0,35}\b(?:pul\w*|salt\w*)\b|'
            . '\b(?:pul\w*|salt\w*)\b.{0,30}\b(?:objeto|elemento|sapo|personagem|imagem|na tela)\b/', $text)
            || ($reactNative && preg_match('/\b(?:pulo|pular|salto|saltar|saltos|pulos)\b/', $text));
    }
    $score = preg_match('/\b(?:conquista desbloqueada|placar|pontuacao)\b/', $text)
        && ($reactNative || preg_match('/\b(?:pulso|pulsar|icone|escala|conquista desbloqueada)\b/', $text));

    if ($nativeWind) $keys[] = 'nativewind';
    if ($bridge) $keys[] = 'animated_bridge';
    elseif ($jump) $keys[] = 'animated_jump';
    if ($score && !$bridge) $keys[] = 'animated_score';
    if (preg_match('/\b(?:eas|apk|gerar build android)\b/', $text)
        && ($reactNative || !preg_match('/\b(?:android studio|kotlin|java nativo|flutter)\b/', $text))) $keys[] = 'apk';
    if (preg_match('/\bnode(?: js|js)?\b/', $text)
        || (!$keys && preg_match('/\b(?:npm|npx|metro|babel|package json|node modules)\b/', $text))) $keys[] = 'node';
    if ($reactNative && preg_match('/\b(?:varias telas|duas telas|mais de uma tela|navegar entre|navegacao entre|trocar(?: de)? tela|telas separadas|arquivo por tela|tela ativa|separ\w* (?:as )?telas|gerenci\w* (?:de |as )?telas)\b/', $text)) {
        $keys[] = 'screens';
    }
    if ($reactNative && preg_match('/\b(?:primeira tela|primeiro (?:app|projeto)|projeto (?:com|em|de) typescript|expo go|projeto novo|criar (?:um )?projeto)\b/', $text)) {
        $keys[] = 'first_screen';
    }
    $visual = $reactNative && preg_match('/\b(?:flexbox|estilos?|styling|layout|hierarquia|props|view pai|componentes? visuais)\b/', $text);
    if ($visual || ($reactNative && !$keys && !preg_match('/\banimated\b/', $text)
        && preg_match('/\b(?:view|text|imagem|componentes?|lista)\b/', $text))) $keys[] = 'visual';

    return array_slice($keys, 0, 2);
}

function joiaTeachingMaterialRequest(string $text): bool
{
    $reference = '(?:material|materiais|aula|aulas|guia|roteiro|apostila|referencia)';
    return preg_match('/\b(?:qual|quais) (?:e |sao |seria |seriam )?(?:o |a |os |as |um |uma )?' . $reference . '\b'
        . '|\b(?:em que|em qual|em quais|em algum|em alguma) ' . $reference . '\b'
        . '|\b(?:tem|ha|existe|conhece|recomenda|indica|temos) (?:algum |alguma |um |uma |o |a |os |as )?' . $reference . '\b'
        . '|\b(?:onde (?:encontro|vejo|leio|localizo|procuro|esta|fica|tem)|em (?:que|qual) (?:parte|secao|capitulo))\b.{0,45}\b' . $reference . '\b'
        . '|\b(?:liste|lista|catalogo|todos|todas|me refiro|me referi)\b.{0,40}\b' . $reference . '\b'
        . '|^(?:(?:e|mas|no|na|o|a) )?' . $reference . ' (?:do|da) (?:profe|professor|professora)\b'
        . '|^(?:e )?qual (?:a |o )?(?:parte|secao|item|capitulo) (?:dele|dela|do material|da aula)$/D', $text) === 1;
}

function joiaTeachingMaterialFollowUp(string $text): bool
{
    return joiaTeachingMaterialRequest($text)
        || preg_match('/\b(?:isso|nesse caso|neste caso|nesse exemplo|e agora|ainda|anterior|essa classe|esse componente|essa tela|nessa tela|essa animacao|como aplico|explicar melhor|me refiro|me referi|qual deles|qual delas)\b/', $text) === 1;
}

function joiaTeachingMaterialOtherSubject(string $text): bool
{
    return preg_match('/\b(?:mudando de assunto|outro assunto|python|flutter|django|sql|html|tailwind css na web|reanimated)\b/', $text) === 1;
}

function joiaSelectTeachingMaterials(string $message, array $history): array
{
    $text = normalize($message);
    $keys = joiaTeachingMaterialKeys($text);
    if (!$keys && joiaTeachingMaterialFollowUp($text) && !joiaTeachingMaterialOtherSubject($text)) {
        foreach (array_reverse($history) as $turn) {
            if (($turn['role'] ?? null) !== 'user') continue;
            $previous = normalize((string) ($turn['content'] ?? ''));
            if (joiaTeachingMaterialOtherSubject($previous)) break;
            $keys = joiaTeachingMaterialKeys($previous);
            if ($keys || !joiaTeachingMaterialFollowUp($previous)) break;
        }
    }

    if (!$keys) return [];
    $catalog = joiaTeachingMaterials();
    return array_map(static fn (string $key): array => $catalog[$key], $keys);
}

function joiaTeachingMaterialAnswer(string $message, array $history): ?array
{
    $text = normalize($message);
    if (!joiaTeachingMaterialRequest($text)) return null;

    $materials = joiaSelectTeachingMaterials($message, $history);
    $fullCatalog = preg_match('/\b(?:todos os materiais|lista dos materiais|catalogo de materiais|lista completa)\b/', $text) === 1
        || (!$materials && preg_match('/\b(?:quais (?:sao )?(?:os )?materiais|lista de materiais)\b/', $text));
    if ($fullCatalog) {
        $titles = array_column(joiaTeachingMaterials(), 'titulo');
        return [
            'resposta' => "Conheço estes materiais do professor João no catálogo da JOIA:\n\n- " . implode("\n- ", $titles),
            'referencias' => [],
        ];
    }

    if (!$materials) {
        $topic = match (true) {
            preg_match('/\bsql\b/', $text) === 1 => 'SQL',
            preg_match('/\bpython\b/', $text) === 1 => 'Python',
            preg_match('/\bflutter\b/', $text) === 1 => 'Flutter',
            preg_match('/\bdjango\b/', $text) === 1 => 'Django',
            preg_match('/\bhtml\b/', $text) === 1 => 'HTML',
            preg_match('/\breanimated\b/', $text) === 1 => 'Reanimated',
            preg_match('/\btailwind css na web\b/', $text) === 1 => 'Tailwind CSS na web',
            default => null,
        };
        $answer = 'Conheço os materiais do professor João cadastrados na JOIA.';
        if ($topic !== null) {
            $answer .= " Ainda não encontrei no catálogo um roteiro sobre {$topic}.";
        } else {
            $answer .= ' Ainda não encontrei um roteiro específico para essa dúvida.';
            if (!preg_match('/\b(?:sobre|para) [a-z0-9]/', $text)) $answer .= ' Qual assunto você quer localizar?';
        }
        return ['resposta' => $answer, 'referencias' => []];
    }

    $answer = 'Sim. Conheço os materiais cadastrados do professor João. Para essa dúvida:';
    foreach ($materials as $material) {
        $answer .= "\n\n**{$material['titulo']}**\n\n"
            . "Procure **{$material['localizador']}**. {$material['contexto']}";
    }
    return ['resposta' => $answer, 'referencias' => array_column($materials, 'titulo')];
}
