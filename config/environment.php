<?php
declare(strict_types=1);

/**
 * Carrega variáveis simples de um arquivo .env sem sobrescrever variáveis já
 * disponibilizadas pelo servidor. O arquivo deve permanecer fora do Git.
 */
function loadEnvironmentFile(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Não foi possível ler a configuração do servidor.');
    }

    foreach ($lines as $lineNumber => $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $separator = strpos($line, '=');
        if ($separator === false) {
            throw new RuntimeException('Configuração inválida na linha ' . ($lineNumber + 1) . '.');
        }

        $name = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));
        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $name)) {
            throw new RuntimeException('Nome de variável inválido na configuração.');
        }

        if (strlen($value) >= 2) {
            $quote = $value[0];
            if (($quote === '"' || $quote === "'") && substr($value, -1) === $quote) {
                $value = substr($value, 1, -1);
                if ($quote === '"') {
                    $value = str_replace(['\\n', '\\r', '\\"', '\\\\'], ["\n", "\r", '"', '\\'], $value);
                }
            }
        }

        // A configuração nativa do servidor sempre tem precedência sobre .env.
        if (getenv($name) !== false) {
            continue;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

// Em produção, prefira um .env um nível acima do public_html. O arquivo na
// raiz do projeto é um fallback para planos em que isso não seja possível.
$environmentFiles = [
    dirname(__DIR__, 2) . '/.env',
    dirname(__DIR__) . '/.env',
];

foreach (array_unique($environmentFiles) as $environmentFile) {
    loadEnvironmentFile($environmentFile);
}
