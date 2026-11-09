<?php

declare(strict_types=1);

namespace HelpFalcon;

/**
 * One API resource (`$client->tickets`): every generated operation is a method, called with path
 * parameters, `body` and query parameters in one array, e.g. `$client->tickets->update(['id' => $id, 'body' => ['status' => 'solved']])`.
 */
final class Resource
{
    /** @param array<string, array{method: string, path: string, pathParams: list<string>, query: list<string>, body: bool, contentType: string|null, summary: string}> $operations */
    public function __construct(private readonly Client $client, private readonly string $name, private readonly array $operations)
    {
    }

    /** @param array{0?: array<string, mixed>} $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        $operation = $this->operations[$method] ?? throw new \BadMethodCallException(\sprintf('%s has no operation "%s". Known: %s.', $this->name, $method, implode(', ', array_keys($this->operations))));

        return $this->client->call($operation, $arguments[0] ?? []);
    }

    /** @return list<string> */
    public function operations(): array
    {
        return array_keys($this->operations);
    }
}
