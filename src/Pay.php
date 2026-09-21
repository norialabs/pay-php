<?php

namespace NoriaLabs\Pay;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use NoriaLabs\Pay\Exceptions\PayException;
use NoriaLabs\Pay\Resources\Charges;
use NoriaLabs\Pay\Resources\PaymentLinks;
use NoriaLabs\Pay\Resources\PaymentMethods;
use NoriaLabs\Pay\Resources\Payouts;
use NoriaLabs\Pay\Resources\Refunds;
use NoriaLabs\Pay\Resources\Statements;
use NoriaLabs\Pay\Resources\Transactions;
use NoriaLabs\Pay\Resources\Webhooks;

class Pay
{
    public const DEFAULT_BASE_URL = 'https://pay.noria.co.ke';

    public function __construct(
        protected readonly Factory $http,
        protected readonly string $apiKey,
        protected readonly string $baseUrl = self::DEFAULT_BASE_URL,
        protected readonly int $timeout = 30,
        protected readonly int $retries = 2,
    ) {
        if ($apiKey === '') {
            throw new PayException('validation_error', 0, 'A Noria Pay API key is required');
        }
    }

    public function charges(): Charges
    {
        return new Charges($this);
    }

    public function payouts(): Payouts
    {
        return new Payouts($this);
    }

    public function refunds(): Refunds
    {
        return new Refunds($this);
    }

    public function transactions(): Transactions
    {
        return new Transactions($this);
    }

    public function paymentMethods(): PaymentMethods
    {
        return new PaymentMethods($this);
    }

    public function paymentLinks(): PaymentLinks
    {
        return new PaymentLinks($this);
    }

    public function statements(): Statements
    {
        return new Statements($this);
    }

    public function webhooks(): Webhooks
    {
        return new Webhooks($this);
    }

    /**
     * Poll a charge the payer is still answering. Returns as soon as it is terminal, or the
     * row as it stands once the deadline passes.
     *
     * @return array<string, mixed>
     */
    public function waitForSettlement(string $id, int $timeoutSeconds = 120, int $intervalSeconds = 3): array
    {
        $deadline = time() + $timeoutSeconds;

        while (true) {
            $transaction = $this->transactions()->get($id);
            $status = is_string($transaction['status'] ?? null) ? $transaction['status'] : '';

            if ($status !== 'pending' && $status !== 'processing') {
                return $transaction;
            }

            if (time() + $intervalSeconds >= $deadline) {
                return $transaction;
            }

            sleep($intervalSeconds);
        }
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $attempt = 0;
        $last = null;

        while ($attempt <= $this->retries) {
            if ($attempt > 0) {
                usleep(min(2_000_000, 200_000 * (2 ** ($attempt - 1))));
            }

            $attempt++;

            try {
                $response = $this->pending($headers, $body !== null)
                    ->send($method, $this->url($path), $body === null ? [] : ['json' => $body]);
            } catch (ConnectionException $exception) {
                $last = PayException::network($exception->getMessage(), $exception);

                // A write carrying an Idempotency-Key replays on the service side, so repeating
                // it is safe. Without one, a dropped connection may already have moved money.
                if (! isset($headers['Idempotency-Key']) && $method !== 'GET') {
                    throw $last;
                }

                continue;
            }

            if ($response->status() === 204) {
                return [];
            }

            /** @var array<string, mixed> $decoded */
            $decoded = $response->json() ?? [];

            if ($response->successful()) {
                return $decoded;
            }

            $last = PayException::fromResponse($response->status(), $decoded);

            if (! $last->isRetryable()) {
                throw $last;
            }
        }

        throw $last ?? PayException::network('Request failed');
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function query(array $parameters): string
    {
        $filtered = array_filter($parameters, static fn (mixed $value): bool => $value !== null && $value !== '');

        return $filtered === [] ? '' : '?'.http_build_query($filtered);
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function pending(array $headers, bool $hasBody): PendingRequest
    {
        $request = $this->http
            ->withToken($this->apiKey)
            ->acceptJson()
            ->withHeaders($headers)
            ->timeout($this->timeout);

        return $hasBody ? $request->asJson() : $request;
    }

    protected function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
