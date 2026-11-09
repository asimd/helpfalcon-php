<?php

declare(strict_types=1);

namespace HelpFalcon;

/** Transport on PHP's http stream wrapper: no extensions or packages needed. */
final class StreamTransport implements Transport
{
    public function __construct(private readonly float $timeout = 30.0)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $lines),
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => $this->timeout,
            'follow_location' => 0,
        ]]);
        $stream = @fopen($url, 'r', false, $context);
        if (false === $stream) {
            throw new \RuntimeException(\sprintf('Could not connect to %s.', parse_url($url, \PHP_URL_HOST)));
        }
        $content = (string) stream_get_contents($stream);
        $meta = stream_get_meta_data($stream);
        fclose($stream);
        $status = 0;
        $out = [];
        foreach ($meta['wrapper_data'] ?? [] as $line) {
            if (1 === preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m)) {
                $status = (int) $m[1];
                $out = [];
            } elseif (str_contains((string) $line, ':')) {
                [$name, $value] = explode(':', (string) $line, 2);
                $out[strtolower(trim($name))] = trim($value);
            }
        }

        return ['status' => $status, 'headers' => $out, 'body' => $content];
    }
}
