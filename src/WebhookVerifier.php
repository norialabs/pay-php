<?php

namespace NoriaLabs\Pay;

use NoriaLabs\Pay\Exceptions\PayException;

class WebhookVerifier
{
    public function __construct(
        protected readonly string $secret,
        protected readonly int $toleranceSeconds = 300,
    ) {
        if (trim($secret) === '') {
            throw new PayException('validation_error', 0, 'A webhook secret is required: an empty one accepts a signature anyone can compute');
        }
    }

    /**
     * Verify against the raw request body. Laravel's $request->all() has already decoded it,
     * and re-encoding reorders keys, so pass $request->getContent().
     *
     * @return array<string, mixed>
     */
    public function verify(string $payload, string $signature, ?int $now = null): array
    {
        [$timestamp, $provided] = $this->parse($signature);

        if (abs(($now ?? time()) - $timestamp) > $this->toleranceSeconds) {
            throw new PayException('validation_error', 400, 'Signature timestamp is outside the tolerance window');
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        if (! hash_equals($expected, $provided)) {
            throw new PayException('unauthorized', 401, 'Invalid webhook signature');
        }

        $event = json_decode($payload, true);

        if (! is_array($event)) {
            throw new PayException('validation_error', 400, 'Webhook payload is not a JSON object');
        }

        /** @var array<string, mixed> $event */
        return $event;
    }

    /**
     * @return array{int, string}
     */
    protected function parse(string $signature): array
    {
        $parts = [];

        foreach (explode(',', $signature) as $pair) {
            $split = explode('=', trim($pair), 2);
            if (count($split) === 2) {
                $parts[$split[0]] = $split[1];
            }
        }

        $timestamp = $parts['t'] ?? '';
        $provided = $parts['v1'] ?? '';

        if (strlen($timestamp) > 15 || ! ctype_digit($timestamp) || ! ctype_xdigit($provided)) {
            throw new PayException('validation_error', 400, 'Malformed pay-signature header');
        }

        return [(int) $timestamp, $provided];
    }
}
