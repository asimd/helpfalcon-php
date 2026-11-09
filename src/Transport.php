<?php

declare(strict_types=1);

namespace HelpFalcon;

/** Sends one HTTP request. The default uses PHP streams; swap in your own (or a PSR-18 adapter). */
interface Transport
{
    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string} header names lowercased
     */
    public function send(string $method, string $url, array $headers, ?string $body): array;
}
