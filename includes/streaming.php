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
    header('X-Content-Type-Options: nosniff');
    sendJoiaStreamEvent('start', ['mode' => 'paragraphs', 'stage' => 'context']);
}

function sendJoiaStreamEvent(string $type, array $payload = []): void
{
    echo json_encode(['type' => $type] + $payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
    flush();
}

function readJoiaOpenAIStream(CurlHandle $request, callable $onDelta, array &$usage, ?string &$finishReason): string
{
    $buffer = '';
    $answer = '';
    $done = false;
    curl_setopt($request, CURLOPT_WRITEFUNCTION, static function (CurlHandle $handle, string $chunk) use (&$buffer, &$answer, &$done, &$usage, &$finishReason, $onDelta): int {
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
            if (is_array($event['usage'] ?? null)) $usage = $event['usage'];
            $choice = $event['choices'][0] ?? [];
            if (is_string($choice['finish_reason'] ?? null)) $finishReason = $choice['finish_reason'];
            $text = $choice['delta']['content'] ?? $choice['delta']['refusal'] ?? '';
            if ($text !== '') {
                $answer .= $text;
                $onDelta($text);
            }
        }
        return strlen($chunk);
    });
    $ok = curl_exec($request);
    if (curl_getinfo($request, CURLINFO_HTTP_CODE) !== 200 || trim($answer) === '') {
        throw new RuntimeException('A geração foi interrompida. Envie a mensagem novamente.');
    }
    if ($ok === false || !$done) $finishReason = 'transport';
    return $answer;
}
