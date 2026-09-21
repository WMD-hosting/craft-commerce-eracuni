<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\core;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\ClientInterface as GuzzleInterface;
use GuzzleHttp\Exception\GuzzleException;

/** Port of mojwmd EracuniRestClient (REST API-CLI, Basic auth, form POST with JSON-encoded objects). */
final class Client implements ClientInterface
{
    private string $baseUrl;
    private GuzzleInterface $http;
    /** @var callable(int):void */
    private $sleep;

    public function __construct(string $baseUrl, private string $username, private string $authToken, ?GuzzleInterface $http = null, ?callable $sleep = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->http = $http ?? new Guzzle();
        $this->sleep = $sleep ?? static fn(int $s) => sleep($s);
    }

    public function call(string $method, array $data = [], int $maxRetries = 3, int $timeoutSeconds = 30): array
    {
        $parts = [];
        foreach ($data as $k => $v) {
            $parts[] = $k . '=' . urlencode(is_array($v) || is_object($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v);
        }
        return $this->request('POST', $method, [
            'body' => implode('&', $parts),
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'timeout' => max(5, min(120, $timeoutSeconds)),
        ], $maxRetries);
    }

    public function get(string $method, array $params = [], int $maxRetries = 3): array
    {
        return $this->request('GET', $method, ['query' => $params, 'timeout' => 30], $maxRetries);
    }

    private function request(string $verb, string $method, array $options, int $maxRetries): array
    {
        $options['auth'] = [$this->username, $this->authToken];
        $options['http_errors'] = false;
        // e-računi answers with a non-standard 'Content-Encoding: 8-bit'; libcurl's automatic
        // decoding rejects it (CURLE_BAD_CONTENT_ENCODING). Bodies are never compressed, so do not decode.
        $options['decode_content'] = false;
        $attempt = 0;
        while (true) {
            try {
                $res = $this->http->request($verb, $this->baseUrl . '/' . $method, $options);
            } catch (GuzzleException $e) {
                throw EracuniException::transport("Eracuni REST {$verb} {$method}: " . $e->getMessage());
            }
            $status = $res->getStatusCode();
            $body = (string) $res->getBody();

            if ($status === 429) {
                if ($method === 'GetDocumentSendingStatus') {
                    throw EracuniException::transport('Eracuni REST HTTP 429 on GetDocumentSendingStatus (not retried).', 429);
                }
                $attempt++;
                if ($attempt >= $maxRetries) {
                    throw EracuniException::transport("Eracuni REST HTTP 429 after {$maxRetries} attempts. {$body}", 429);
                }
                ($this->sleep)(min(5 * $attempt, 15));
                continue;
            }
            if ($status >= 500 && (str_contains($body, 'Another web request is currently being processed') || str_contains($body, 'Concurrent usage of atomic API operations'))) {
                $attempt++;
                if ($attempt >= $maxRetries) {
                    throw EracuniException::transport("Eracuni REST session lock persisted after {$maxRetries} attempts. {$body}", $status);
                }
                ($this->sleep)(15 * $attempt);
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw EracuniException::transport("Eracuni REST HTTP {$status} on {$method}: " . mb_substr($body, 0, 500), $status);
            }
            $decoded = json_decode($body, true);
            return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : ['raw' => $body];
        }
    }
}
