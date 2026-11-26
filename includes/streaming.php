<?php
declare(strict_types=1);

const JOIA_STREAM_TYPE = 'application/x-ndjson';

function startJoiaStream(): void
{
    ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0 && ob_end_flush()) {}
    header('Content-Type: ' . JOIA_STREAM_TYPE . '; charset=utf-8');
    header('Cache-Control: no-cache, no-store, no-transform');
    header('X-Accel-Buffering: no');
    sendJoiaStreamEvent('start');
}

function sendJoiaStreamEvent(string $type, array $payload = []): void
{
    echo json_encode(['type' => $type] + $payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
    flush();
}

function readJoiaOpenAIStream(CurlHandle $request, callable $onDelta): string
{
    $buffer = '';
    $answer = '';
    $done = false;
    curl_setopt($request, CURLOPT_WRITEFUNCTION, static function (CurlHandle $handle, string $chunk) use (&$buffer, &$answer, &$done, $onDelta): int {
        if ($done) return strlen($chunk);
        $buffer .= $chunk;
        while (($newline = strpos($buffer, "\n")) !== false) {
            $line = rtrim(substr($buffer, 0, $newline), "\r");
            $buffer = substr($buffer, $newline + 1);
            if (!str_starts_with($line, 'data:')) continue;

            $data = ltrim(substr($line, 5));
            if ($data === '[DONE]') {
                $done = true;
                break;
            }
            $event = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            $text = $event['choices'][0]['delta']['content'] ?? '';
            if ($text !== '') {
                $answer .= $text;
                $onDelta($text);
            }
        }
        return strlen($chunk);
    });
    $ok = curl_exec($request);
    if ($ok === false || curl_getinfo($request, CURLINFO_HTTP_CODE) !== 200 || !$done || trim($answer) === '') {
        throw new RuntimeException('A geração foi interrompida. Envie a mensagem novamente.');
    }
    return trim($answer);
}

function joiaProsePreview(): Closure
{
    $pending = '';
    $hold = false;
    return static function (string $delta) use (&$pending, &$hold): void {
        if ($hold) return;
        $pending .= $delta;
        $preview = '';
        // Finish a phrase or line before showing it. A single inline name
        // is safe to quote, but code fences and executable syntax wait.
        while (preg_match('/^.*?(?:\r?\n|[,.!?](?=[ \t]))/us', $pending, $segment)) {
            $text = $segment[0];
            $prose = preg_replace('/(?<!`)`[\p{L}\p{N}_:-]{1,48}`(?!`)/u', 'termo', $text);
            if ($prose === null || preg_match('/[`~<>{}=;\\\\]|(?:\w+|\])\s*\(/u', $prose) || codeLineCount($prose) > 0) {
                $hold = true;
                break;
            }
            $preview .= $text;
            $pending = substr($pending, strlen($text));
        }
        if ($preview !== '') sendJoiaStreamEvent('delta', ['text' => $preview]);
    };
}
