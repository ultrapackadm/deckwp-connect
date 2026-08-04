<?php

namespace DeckWP\Connect\Tests\Support;

use DeckWP\Connect\HTTP\ApiClient;

/**
 * An {@see ApiClient} with the socket replaced by a queue.
 *
 * Everything above the transport is the real thing — the schedulers
 * still build their own payloads and sign them — so a test can assert
 * on the exact bytes that would have gone over the wire.
 *
 * Responses are consumed in order; the last one repeats once the queue
 * runs dry, which keeps a "same answer every time" test to one entry.
 */
class FakeApiClient extends ApiClient
{
    /**
     * Requests this client was asked to make, in order.
     *
     * @var array<int, array{url: string, body: string, headers: array<string, string>}>
     */
    public $calls = [];

    /** @var array<int, array{status: int, body: array|null, error: string|null}> */
    private $responses;

    /**
     * @param array<int, array{status: int, body?: array|null, error?: string|null}> $responses
     */
    public function __construct(array $responses = [])
    {
        if ($responses === []) {
            $responses = [['status' => 202]];
        }

        $this->responses = array_map(
            static function (array $response): array {
                return [
                    'status' => (int) $response['status'],
                    'body'   => $response['body'] ?? null,
                    'error'  => $response['error'] ?? null,
                ];
            },
            array_values($responses)
        );
    }

    /**
     * @param array<string, string> $headers
     * @return array{ok: bool, status: int, body: array|null, raw: string, error: string|null}
     */
    public function postBody(string $url, string $body, array $headers = []): array
    {
        $index = count($this->calls);
        $this->calls[] = ['url' => $url, 'body' => $body, 'headers' => $headers];

        $response = $this->responses[min($index, count($this->responses) - 1)];

        $ok = $response['status'] >= 200 && $response['status'] < 300;
        $raw = $response['body'] === null ? '' : (string) json_encode($response['body']);

        return [
            'ok'     => $ok,
            'status' => $response['status'],
            'body'   => $response['body'],
            'raw'    => $raw,
            'error'  => $ok
                ? null
                : ($response['error'] ?? sprintf('Request failed with status %d.', $response['status'])),
        ];
    }

    /**
     * The last body this client was handed, decoded.
     *
     * @return array<string, mixed>
     */
    public function lastPayload(): array
    {
        if ($this->calls === []) {
            return [];
        }

        $decoded = json_decode($this->calls[count($this->calls) - 1]['body'], true);

        return is_array($decoded) ? $decoded : [];
    }
}
