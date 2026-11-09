<?php

declare(strict_types=1);

// Dependency-free tests of the PHP client (WEB-015): `php tests/run.php`.

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'HelpFalcon\\')) {
        require __DIR__.'/../src/'.substr($class, strlen('HelpFalcon\\')).'.php';
    }
});

use HelpFalcon\HelpFalconException;
use HelpFalcon\Client;
use HelpFalcon\Transport;

final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $sent = [];
    private int $throttled = 0;

    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $json = static fn (int $status, mixed $data, array $extra = []): array => ['status' => $status, 'headers' => ['content-type' => 'application/json'] + $extra, 'body' => json_encode($data)];

        return match (true) {
            '/api/tickets' === $parts['path'] && 'GET' === $method => $json(200, ['member' => array_slice([['number' => 1], ['number' => 2], ['number' => 3]], ((int) $query['page'] - 1) * (int) $query['itemsPerPage'], (int) $query['itemsPerPage'])]),
            '/api/tickets/abc' === $parts['path'] && 'PATCH' === $method => $json(200, ['id' => 'abc'] + json_decode((string) $body, true)),
            '/api/groups' === $parts['path'] && 0 === $this->throttled++ => $json(429, ['detail' => 'slow down'], ['retry-after' => '0']),
            '/api/groups' === $parts['path'] => $json(200, ['member' => []]),
            default => ['status' => 404, 'headers' => ['content-type' => 'application/problem+json'], 'body' => json_encode(['title' => 'Not Found', 'detail' => 'No route'])],
        };
    }
}

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ').$what.\PHP_EOL;
    $failures += $ok ? 0 : 1;
};

$transport = new FakeTransport();
$client = new Client('https://acme.example.com/', 'desk_test', $transport);

$updated = $client->tickets->update(['id' => 'abc', 'body' => ['status' => 'solved']]);
$last = end($transport->sent);
$check(['id' => 'abc', 'status' => 'solved'] === $updated, 'update sends the body and decodes the answer');
$check('Bearer desk_test' === $last['headers']['Authorization'] && 'application/merge-patch+json' === $last['headers']['Content-Type'], 'bearer auth and merge-patch content type');

$client->tickets->list(['status' => ['open', 'pending'], 'page' => 1, 'itemsPerPage' => 2]);
$check(str_contains(end($transport->sent)['url'], 'status%5B%5D=open&status%5B%5D=pending'), 'array query parameters');

$numbers = [];
foreach ($client->paginate($client->tickets->list(...), ['itemsPerPage' => 2]) as $ticket) {
    $numbers[] = $ticket['number'];
}
$check([1, 2, 3] === $numbers, 'paginate walks every page');

$check(['member' => []] === $client->groups->list(), '429 is retried after Retry-After');

try {
    $client->request('GET', '/zd/api/v2/nothing.json');
    $check(false, 'errors throw');
} catch (HelpFalconException $e) {
    $check(404 === $e->status && str_contains($e->getMessage(), 'No route'), 'errors carry status and problem detail');
}

try {
    $client->tickets->get([]);
    $check(false, 'missing path parameter throws');
} catch (InvalidArgumentException $e) {
    $check(str_contains($e->getMessage(), '"id"'), 'missing path parameter is named');
}

exit($failures > 0 ? 1 : 0);
