<?php

declare(strict_types=1);

namespace HelpFalcon;

/**
 * HelpFalcon API client (WEB-015). Resources and their operations come from the OpenAPI document
 * (Operations.php, generated); this class is the hand-written core: auth, JSON, retries on
 * 429/503 with Retry-After, errors and pagination.
 *
 *     $client = new HelpFalcon\Client('https://acme.example.com', getenv('HELPFALCON_TOKEN'));
 *     $ticket = $client->tickets->get(['id' => $id]);
 *     foreach ($client->paginate($client->tickets->list(...), ['status' => 'open']) as $ticket) { ... }
 *     $client->request('GET', '/zd/api/v2/tickets.json');   // the Zendesk-compatible API
 */
final class Client
{
    private readonly string $baseUrl;
    /** @var array<string, Resource> */
    private array $resources = [];

    public function __construct(
        string $baseUrl,
        private readonly string $token,
        private readonly Transport $transport = new StreamTransport(),
        private readonly int $maxRetries = 2,
        private readonly string $userAgent = 'helpfalcon-php/'.Operations::API_VERSION,
    ) {
        if ('' === $token) {
            throw new \InvalidArgumentException('An API token is required.');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function __get(string $name): Resource
    {
        if (!isset(Operations::ALL[$name])) {
            throw new \OutOfRangeException(\sprintf('Unknown resource "%s".', $name));
        }

        return $this->resources[$name] ??= new Resource($this, $name, Operations::ALL[$name]);
    }

    /**
     * @param array{method: string, path: string, pathParams: list<string>, query: list<string>, body: bool, contentType: string|null, summary: string} $operation
     * @param array<string, mixed>                                                                                                                          $params
     */
    public function call(array $operation, array $params = []): mixed
    {
        $path = $operation['path'];
        $query = [];
        foreach ($params as $key => $value) {
            if ('body' === $key) {
                continue;
            }
            if (\in_array($key, $operation['pathParams'], true)) {
                $path = str_replace('{'.$key.'}', rawurlencode((string) $value), $path);
            } elseif (null !== $value) {
                $query[$key] = $value;
            }
        }
        if (1 === preg_match('/\{([^}]+)\}/', $path, $missing)) {
            throw new \InvalidArgumentException(\sprintf('Missing path parameter "%s" for %s %s.', $missing[1], $operation['method'], $operation['path']));
        }

        return $this->request($operation['method'], $path, $query, $params['body'] ?? null, $operation['contentType']);
    }

    /** @param array<string, mixed> $query */
    public function request(string $method, string $path, array $query = [], mixed $body = null, ?string $contentType = null): mixed
    {
        $url = $this->baseUrl.$path;
        $pairs = [];
        foreach ($query as $key => $value) {
            foreach (\is_array($value) ? $value : [$value] as $v) {
                $name = \is_array($value) && !str_ends_with((string) $key, '[]') ? $key.'[]' : (string) $key;
                $pairs[] = rawurlencode($name).'='.rawurlencode(\is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
            }
        }
        if ([] !== $pairs) {
            $url .= (str_contains($url, '?') ? '&' : '?').implode('&', $pairs);
        }
        $headers = ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$this->token, 'User-Agent' => $this->userAgent];
        $payload = null;
        if (null !== $body) {
            $headers['Content-Type'] = $contentType ?? ('PATCH' === $method ? 'application/merge-patch+json' : 'application/json');
            $payload = json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        }
        for ($attempt = 0; ; ++$attempt) {
            $response = $this->transport->send($method, $url, $headers, $payload);
            if (\in_array($response['status'], [429, 503], true) && $attempt < $this->maxRetries) {
                $wait = $response['headers']['retry-after'] ?? null;
                usleep((int) (1_000_000 * (is_numeric($wait) ? min((float) $wait, 60.0) : 2 ** $attempt)));
                continue;
            }
            $data = $response['body'];
            if ('' !== $data && str_contains($response['headers']['content-type'] ?? '', 'json')) {
                try {
                    $data = json_decode($data, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    // keep the text
                }
            }
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new HelpFalconException($response['status'], $data, $method, $path);
            }

            return '' === $data ? null : $data;
        }
    }

    /**
     * Every item of a collection: page-based lists (`page`, `itemsPerPage`) and cursor lists
     * (`links.next`) alike.
     *
     * @param callable(array<string, mixed>): mixed $list e.g. $client->tickets->list(...)
     * @param array<string, mixed>                  $params
     *
     * @return \Generator<int, mixed>
     */
    public function paginate(callable $list, array $params = []): \Generator
    {
        $size = (int) ($params['itemsPerPage'] ?? 100);
        for ($page = (int) ($params['page'] ?? 1); ; ++$page) {
            $result = $list(['page' => $page, 'itemsPerPage' => $size] + $params);
            $items = self::items($result);
            yield from $items;
            $next = \is_array($result) ? ($result['links']['next'] ?? null) : null;
            if (\is_string($next)) {
                while (\is_string($next)) {
                    $parts = parse_url($next);
                    $more = $this->request('GET', ($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : ''));
                    yield from self::items($more);
                    $next = \is_array($more) ? ($more['links']['next'] ?? null) : null;
                }

                return;
            }
            if (\count($items) < $size) {
                return;
            }
        }
    }

    /** @return list<mixed> */
    private static function items(mixed $result): array
    {
        if (!\is_array($result)) {
            return [];
        }
        if (array_is_list($result)) {
            return $result;
        }
        $items = $result['member'] ?? $result['hydra:member'] ?? $result['data'] ?? [];

        return \is_array($items) ? array_values($items) : [];
    }
}
