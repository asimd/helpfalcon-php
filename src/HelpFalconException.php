<?php

declare(strict_types=1);

namespace HelpFalcon;

/** The API answered with an error status; $body is the decoded problem+json (or the raw text). */
final class HelpFalconException extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly mixed $body, string $method, string $path)
    {
        $detail = \is_array($body) ? ($body['detail'] ?? $body['description'] ?? $body['title'] ?? $body['error'] ?? null) : $body;
        parent::__construct(\sprintf('%s %s failed with HTTP %d%s', $method, $path, $status, \is_string($detail) && '' !== $detail ? ': '.$detail : ''), $status);
    }
}
