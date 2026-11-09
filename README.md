# helpfalcon/helpfalcon-php

HelpFalcon API client for PHP 8.3+. Every REST API operation is a method, generated from the
OpenAPI document; no dependencies (PHP streams; plug in your own `HelpFalcon\Transport`).

```php
$client = new HelpFalcon\Client('https://acme.example.com', getenv('HELPFALCON_TOKEN'));
$ticket = $client->tickets->get(['id' => '0190...']);
$client->tickets->update(['id' => $ticket['id'], 'body' => ['status' => 'solved']]);
foreach ($client->paginate($client->tickets->list(...), ['status' => 'open']) as $t) {
    echo $t['subject'], PHP_EOL;
}
$client->request('GET', '/zd/api/v2/tickets.json'); // Zendesk-compatible API
```

Errors throw `HelpFalcon\HelpFalconException` (`status`, `body`). `429` and `503` are retried after
`Retry-After`. Docs: https://helpfalcon.com/developers/client-libraries
