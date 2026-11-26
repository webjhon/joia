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
    sendJoiaStreamEvent('start', ['mode' => 'paragraphs']);
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

function joiaParagraphKey(string $text): string
{
    return preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
}

function withoutRepeatedJoiaOpening(string $text, string $opening): string
{
    if ($opening === '') return $text;

    $paragraphs = preg_split('/\r?\n\h*\r?\n/', trim($opening)) ?: [];
    $shown = array_map('joiaParagraphKey', $paragraphs);
    while ($text !== '' && preg_match('/\A(.*?)(?:\r?\n\h*\r?\n|\z)/s', $text, $match)
        && in_array(joiaParagraphKey($match[1]), $shown, true)) {
        $text = ltrim(substr($text, strlen($match[0])));
    }
    return $text;
}

function joiaParagraphMayBePublished(string $text): bool
{
    // Publish self-contained prose only. Code, structured Markdown, headings
    // and lead-ins to material that may fail the full review all wait.
    $prose = preg_replace('/(?<!`)`[\p{L}\p{N}_:-]{1,48}`(?!`)/u', 'termo', trim($text));
    if ($prose === null || !preg_match('/[.!][*_]*$/u', $prose)) return false;
    if (preg_match('/[`~<>{}=;|\\\\]|\w+\(|(?:[\w$]+\.\w+|\])\s*\(|^\h*(?:#|[-*+]\h|\d+[.)]\h)/mu', $prose)
        || codeLineCount($prose) > 0) return false;
    if (preg_match('/\b(?:vou|vamos|posso|irei)\s+(?:te\s+)?(?:mostrar|exibir|escrever|montar|dar|gerar)\b|\b(?:abaixo|a seguir|aqui est[áa] (?:um|o) exemplo|segue (?:um|o) exemplo)\b/iu', $prose)) {
        return false;
    }
    return preg_match('/^(?:perfeito|claro|entendi|com certeza|boa|ótim[oa])(?:[!,.: ]|$)/iu', $prose) !== 1
        || str_word_count(normalize($prose)) >= 9;
}

final class JoiaAnswerStream
{
    private string $published = '';
    private string $buffer = '';
    private bool $atStart = true;
    private bool $hold = false;
    private array $previousParagraphs = [];

    public function append(string $delta): void
    {
        if ($this->hold) return;
        $this->buffer .= $delta;
        if ($this->atStart) {
            $this->buffer = ltrim($this->buffer);
            if ($this->buffer === '') return;
            $this->atStart = false;
        }
        while (preg_match('/\r?\n\h*\r?\n/', $this->buffer, $separator, PREG_OFFSET_CAPTURE)) {
            [$ending, $offset] = $separator[0];
            $paragraph = substr($this->buffer, 0, $offset);
            if (!joiaParagraphMayBePublished($paragraph)
                || in_array(joiaParagraphKey($paragraph), $this->previousParagraphs, true)) {
                $this->hold = true;
                return;
            }

            $bytes = $offset + strlen($ending);
            $text = substr($this->buffer, 0, $bytes);
            $this->buffer = substr($this->buffer, $bytes);
            $this->published .= $text;
            sendJoiaStreamEvent('delta', ['text' => $text]);
        }
    }

    public function beginRetry(): void
    {
        $paragraphs = preg_split('/\r?\n\h*\r?\n/', trim($this->published)) ?: [];
        $this->previousParagraphs = array_map('joiaParagraphKey', $paragraphs);
        $this->buffer = '';
        $this->atStart = true;
        $this->hold = false;
        sendJoiaStreamEvent('retry');
    }

    public function published(): string
    {
        return $this->published;
    }

    public function remaining(string $answer): string
    {
        if ($answer === rtrim($this->published)) return '';
        if (!str_starts_with($answer, $this->published)) {
            throw new LogicException('O texto revisado diverge dos parágrafos publicados.');
        }
        return substr($answer, strlen($this->published));
    }
}
