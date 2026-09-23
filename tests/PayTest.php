<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use NoriaLabs\Pay\Exceptions\PayException;
use NoriaLabs\Pay\Facades\Pay;
use NoriaLabs\Pay\WebhookVerifier;

const CHARGE = [
    'amount_minor' => 150000,
    'channel' => 'mpesa',
    'reference' => 'INV-42',
    'description' => 'April rent',
    'payer_phone' => '0712345678',
];

function transaction(array $overrides = []): array
{
    return array_merge([
        'id' => '01a0c5cd-0000-7000-8000-0000000000aa',
        'object' => 'transaction',
        'direction' => 'collection',
        'status' => 'processing',
        'currency' => 'KES',
        'amount_minor' => 150000,
        'settled_minor' => null,
        'reference' => 'INV-42',
        'next_action' => ['type' => 'await_payer', 'provider_ref' => 'ws_CO_1', 'payer_message' => 'Enter your PIN'],
    ], $overrides);
}

it('sends the api key and the idempotency key', function () {
    Http::fake([
        'pay.noria.test/v1/charges' => Http::response(transaction(), 201),
    ]);

    Pay::charges()->create(CHARGE, 'invoice:42:1');

    Http::assertSent(function (Request $request): bool {
        expect($request->url())->toBe('https://pay.noria.test/v1/charges')
            ->and($request->header('Authorization')[0])->toBe('Bearer pay_test_abcdefghijklmnopqrstuvwx')
            ->and($request->header('Idempotency-Key')[0])->toBe('invoice:42:1')
            ->and($request['amount_minor'])->toBe(150000);

        return true;
    });
});

it('records money that arrived off-rail', function () {
    Http::fake([
        'pay.noria.test/v1/charges/record' => Http::response(transaction(['status' => 'succeeded', 'settled_minor' => 250000]), 201),
    ]);

    $recorded = Pay::charges()->record([
        'amount_minor' => 250000,
        'channel' => 'cash',
        'reference' => 'INV-7',
        'description' => 'Counter payment',
    ], 'cash:7');

    expect($recorded['status'])->toBe('succeeded')
        ->and($recorded['settled_minor'])->toBe(250000);
});

it('creates a payout and a refund', function () {
    Http::fake([
        'pay.noria.test/v1/payouts' => Http::response(transaction(['direction' => 'payout']), 201),
        'pay.noria.test/v1/refunds' => Http::response(transaction(['direction' => 'refund']), 201),
    ]);

    expect(Pay::payouts()->create([
        'amount_minor' => 500000,
        'channel' => 'mpesa',
        'reference' => 'PO-1',
        'description' => 'Supplier',
        'recipient_name' => 'Acme',
        'recipient_phone' => '0712345678',
    ], 'po:1')['direction'])->toBe('payout');

    expect(Pay::refunds()->create([
        'transaction_id' => '01a0c5cd-0000-7000-8000-0000000000aa',
        'reason' => 'Duplicate',
    ], 'rf:1')['direction'])->toBe('refund');
});

it('builds a query string and drops what is absent', function () {
    Http::fake(['*' => Http::response(['object' => 'list', 'data' => [], 'next_cursor' => null])]);

    Pay::transactions()->list(['direction' => 'collection', 'status' => null, 'limit' => 50]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pay.noria.test/v1/transactions?direction=collection&limit=50');
});

it('returns an empty array for a 204 rather than failing to decode it', function () {
    Http::fake(['*' => Http::response(null, 204)]);

    expect(fn () => Pay::paymentMethods()->remove('daraja'))->not->toThrow(PayException::class);
});

it('carries the service error code, status and request id', function () {
    Http::fake([
        '*' => Http::response([
            'error' => ['code' => 'idempotency_mismatch', 'message' => 'Already used', 'request_id' => 'req_1'],
        ], 409),
    ]);

    try {
        Pay::charges()->create(CHARGE, 'k-1');
        $this->fail('expected the request to be refused');
    } catch (PayException $exception) {
        expect($exception->errorCode)->toBe('idempotency_mismatch')
            ->and($exception->status)->toBe(409)
            ->and($exception->requestId)->toBe('req_1');
    }
});

// 504 is normally retryable. Here it means the charge may already have taken the payer's
// money, so the client surfaces it once instead of sending it again.
it('never retries an unknown outcome and names the transaction to poll', function () {
    Http::fake([
        '*' => Http::response([
            'error' => [
                'code' => 'outcome_unknown',
                'message' => 'The provider did not answer in time',
                'details' => ['transaction_id' => '01a0c5cd-0000-7000-8000-0000000000aa'],
            ],
        ], 504),
    ]);

    try {
        Pay::charges()->create(CHARGE, 'k-2');
        $this->fail('expected the request to be refused');
    } catch (PayException $exception) {
        expect($exception->isOutcomeUnknown())->toBeTrue()
            ->and($exception->isRetryable())->toBeFalse()
            ->and($exception->transactionId())->toBe('01a0c5cd-0000-7000-8000-0000000000aa');
    }

    Http::assertSentCount(1);
});

it('does not retry a provider rejection, which is definite', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'provider_rejected', 'message' => 'Bad amount']], 502)]);

    expect(fn () => Pay::charges()->create(CHARGE, 'k-3'))->toThrow(PayException::class);
    Http::assertSentCount(1);
});

it('retries a 503 and returns the eventual success', function () {
    Http::fakeSequence()
        ->push(['error' => ['code' => 'internal_error', 'message' => 'down']], 503)
        ->push(transaction(), 201);

    expect(Pay::charges()->create(CHARGE, 'k-4')['status'])->toBe('processing');
    Http::assertSentCount(2);
});

it('does not repeat a write with no idempotency key on a 5xx, which may already have acted', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'internal_error', 'message' => 'down']], 503)]);

    expect(fn () => Pay::paymentLinks()->create(['title' => 'Rent', 'amount_minor' => 1000]))->toThrow(PayException::class, 'down');
    Http::assertSentCount(1);

    expect(fn () => Pay::webhooks()->remove('01a0c5cd-0000-7000-8000-0000000000dd'))->toThrow(PayException::class, 'down');
    Http::assertSentCount(2);

    expect(fn () => Pay::transactions()->get('01a0c5cd-0000-7000-8000-0000000000aa'))->toThrow(PayException::class, 'down');
    Http::assertSentCount(5);
});

it('waits out a keyed request still in flight, and nothing else that is a conflict', function () {
    Http::fakeSequence()
        ->push(['error' => ['code' => 'conflict', 'message' => 'still processing', 'details' => ['transaction_id' => '01a0c5cd-0000-7000-8000-0000000000aa']]], 409)
        ->push(transaction(), 201)
        ->push(['error' => ['code' => 'conflict', 'message' => 'A succeeded charge cannot be cancelled']], 409);

    expect(Pay::charges()->create(CHARGE, 'k-in-flight')['status'])->toBe('processing');
    Http::assertSentCount(2);

    expect(fn () => Pay::charges()->cancel('01a0c5cd-0000-7000-8000-0000000000aa'))->toThrow(PayException::class, 'cannot be cancelled');
    Http::assertSentCount(3);
});

it('never retries a code that must not be repeated, whatever its status', function () {
    foreach (['outcome_unknown', 'idempotency_mismatch', 'provider_not_configured', 'insufficient_balance'] as $code) {
        $exception = PayException::fromResponse(503, ['error' => ['code' => $code, 'message' => $code, 'details' => ['transaction_id' => 'tx']]]);
        expect($exception->isRetryable())->toBeFalse();
    }
});

it('reports a 2xx that is not JSON as an error rather than an empty success', function () {
    Http::fake(['*' => Http::response('<html>ok?</html>', 200, ['content-type' => 'text/html'])]);

    try {
        Pay::transactions()->get('01a0c5cd-0000-7000-8000-0000000000aa');
        $this->fail('expected the response to be refused');
    } catch (PayException $exception) {
        expect($exception->errorCode)->toBe('internal_error')
            ->and($exception->getMessage())->toBe('The response was not JSON');
    }
});

it('sends empty metadata as a JSON object, which is what the contract takes', function () {
    Http::fake(['*' => Http::response(transaction(), 201)]);

    Pay::charges()->create([...CHARGE, 'metadata' => []], 'k-meta');
    Pay::paymentLinks()->update('01a0c5cd-0000-7000-8000-0000000000cc', []);

    $bodies = [];
    Http::assertSent(function (Request $request) use (&$bodies): bool {
        $bodies[] = $request->body();

        return true;
    });

    expect($bodies[0])->toContain('"metadata":{}')
        ->and($bodies[1])->toBe('{}');
});

it('leaves a webhook description off the body rather than sending null', function () {
    Http::fake(['*' => Http::response(['object' => 'webhook_endpoint'])]);

    Pay::webhooks()->create('https://hooks.test/pay');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true) === ['url' => 'https://hooks.test/pay', 'event_types' => []]);
});

it('keeps polling through an unknown outcome until it resolves', function () {
    Http::fakeSequence()
        ->push(transaction(['status' => 'unknown']), 200)
        ->push(transaction(['status' => 'unknown']), 200)
        ->push(transaction(['status' => 'succeeded']), 200);

    expect(Pay::waitForSettlement('01a0c5cd-0000-7000-8000-0000000000aa', 30, 0)['status'])->toBe('succeeded');
    Http::assertSentCount(3);
});

it('waits for settlement and stops as soon as it is terminal', function () {
    Http::fakeSequence()
        ->push(transaction(), 200)
        ->push(transaction(['status' => 'succeeded', 'settled_minor' => 150000]), 200);

    $settled = Pay::waitForSettlement('01a0c5cd-0000-7000-8000-0000000000aa', 30, 0);

    expect($settled['status'])->toBe('succeeded');
    Http::assertSentCount(2);
});

it('refuses to construct without an api key', function () {
    expect(fn () => new NoriaLabs\Pay\Pay(app(Factory::class), ''))
        ->toThrow(PayException::class);
});

it('reaches every transactions surface', function () {
    Http::fake(['*' => Http::response(['object' => 'list', 'data' => [], 'next_cursor' => null])]);

    Pay::transactions()->get('01a0c5cd-0000-7000-8000-0000000000aa');
    Pay::transactions()->events('01a0c5cd-0000-7000-8000-0000000000aa', ['limit' => 10]);

    $urls = [];
    Http::assertSent(function (Request $request) use (&$urls): bool {
        $urls[] = $request->url();

        return true;
    });

    expect($urls[0])->toBe('https://pay.noria.test/v1/transactions/01a0c5cd-0000-7000-8000-0000000000aa')
        ->and($urls[1])->toBe('https://pay.noria.test/v1/transactions/01a0c5cd-0000-7000-8000-0000000000aa/events?limit=10');
});

it('cancels a charge', function () {
    Http::fake(['*' => Http::response(transaction(['status' => 'cancelled']))]);

    expect(Pay::charges()->cancel('01a0c5cd-0000-7000-8000-0000000000aa')['status'])->toBe('cancelled');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v1/charges/01a0c5cd-0000-7000-8000-0000000000aa/cancel'));
});

it('reaches every payment method surface without ever reading a secret back', function () {
    Http::fake(['*' => Http::response([
        'id' => '01a0c5cd-0000-7000-8000-0000000000bb',
        'object' => 'payment_method',
        'provider' => 'daraja',
        'configured' => true,
        'config_hint' => ['consumer_secret' => '…alue'],
        'callback_url' => 'https://pay.noria.test/webhooks/daraja/slug',
    ])]);

    Pay::paymentMethods()->list();
    $set = Pay::paymentMethods()->set('daraja', ['label' => 'Paybill', 'channels' => ['mpesa'], 'config' => ['provider' => 'daraja']]);
    Pay::paymentMethods()->verify('daraja');
    Pay::paymentMethods()->remove('daraja');

    expect($set['config_hint']['consumer_secret'])->toBe('…alue');
    Http::assertSentCount(4);
});

it('reaches every payment link surface', function () {
    Http::fake(['*' => Http::response(['id' => '01a0c5cd-0000-7000-8000-0000000000cc', 'object' => 'payment_link', 'token' => 'abc', 'url' => 'https://pay.noria.test/p/abc'])]);

    $created = Pay::paymentLinks()->create(['title' => 'April rent', 'amount_minor' => 150000]);
    Pay::paymentLinks()->get($created['id']);
    Pay::paymentLinks()->list(['limit' => 5]);
    Pay::paymentLinks()->update($created['id'], ['title' => 'May rent']);
    Pay::paymentLinks()->close($created['id']);

    expect($created['url'])->toBe('https://pay.noria.test/p/abc');
    Http::assertSentCount(5);

    Http::assertSent(fn (Request $request): bool => $request->method() !== 'PATCH' || $request['title'] === 'May rent');
});

it('reaches every webhook endpoint surface', function () {
    Http::fake(['*' => Http::response(['id' => '01a0c5cd-0000-7000-8000-0000000000dd', 'object' => 'webhook_endpoint', 'secret' => 'whsec_shown_once'])]);

    $created = Pay::webhooks()->create('https://hooks.test/pay', ['succeeded'], 'ledger');
    Pay::webhooks()->list();
    Pay::webhooks()->update($created['id'], ['enabled' => false]);
    Pay::webhooks()->remove($created['id']);

    expect($created['secret'])->toBe('whsec_shown_once');
    Http::assertSentCount(4);

    Http::assertSent(fn (Request $request): bool => $request->method() !== 'POST'
        || ($request['event_types'] === ['succeeded'] && $request['description'] === 'ledger'));
});

it('returns the row as it stands when waiting runs out of time', function () {
    Http::fake(['*' => Http::response(transaction())]);

    $settled = Pay::waitForSettlement('01a0c5cd-0000-7000-8000-0000000000aa', 0, 0);

    expect($settled['status'])->toBe('processing');
});

it('reaches every statement surface', function () {
    Http::fake(['*' => Http::response(['object' => 'statement_import', 'settled' => 1, 'unmatched' => 0, 'replayed' => false], 201)]);

    $imported = Pay::statements()->import([
        'source' => 'mpesa',
        'label' => 'March',
        'rows' => [['receipt' => 'SLJ7', 'amount_minor' => 150000, 'paid_at' => '2026-09-22T00:00:00.000Z']],
    ]);
    Pay::statements()->get('01a0c5cd-0000-7000-8000-0000000000ff');
    Pay::statements()->list(['limit' => 5]);

    expect($imported['settled'])->toBe(1);
    Http::assertSentCount(3);
});

describe('webhook verification', function () {
    $body = json_encode([
        'id' => '01a0c5cd-0000-7000-8000-0000000000cc',
        'type' => 'succeeded',
        'data' => ['reference' => 'INV-42', 'settled_minor' => 150000, 'provider_receipt' => 'SLJ7AB99XY'],
    ]);

    $sign = fn (string $payload, int $at): string => 't='.$at.',v1='.hash_hmac('sha256', $at.'.'.$payload, 'whsec_testsecret');

    it('accepts a correctly signed payload', function () use ($body, $sign) {
        $verifier = app(WebhookVerifier::class);
        $event = $verifier->verify($body, $sign($body, 1790000000), 1790000000);

        expect($event['type'])->toBe('succeeded')
            ->and($event['data']['provider_receipt'])->toBe('SLJ7AB99XY');
    });

    it('rejects a body altered after signing', function () use ($body, $sign) {
        $verifier = app(WebhookVerifier::class);
        $tampered = str_replace('150000', '1', $body);

        expect(fn () => $verifier->verify($tampered, $sign($body, 1790000000), 1790000000))
            ->toThrow(PayException::class, 'Invalid webhook signature');
    });

    it('rejects a replay outside the tolerance', function () use ($body, $sign) {
        $verifier = app(WebhookVerifier::class);

        expect(fn () => $verifier->verify($body, $sign($body, 1789990000), 1790000000))
            ->toThrow(PayException::class, 'Signature timestamp is outside the tolerance window');
    });

    it('rejects a malformed header', function () use ($body) {
        expect(fn () => app(WebhookVerifier::class)->verify($body, 'nonsense'))
            ->toThrow(PayException::class, 'Malformed pay-signature header');
    });

    it('answers every crafted header with a signature error, never a crash', function () use ($body) {
        foreach (['t=²,v1=abc', 't=1790000000,v1=é', 't=1e9,v1=ab', 't=,v1=ab', '=,=', 't=1790000000', 't='.str_repeat('9', 400).',v1=ab'] as $header) {
            expect(fn () => app(WebhookVerifier::class)->verify($body, $header, 1790000000))
                ->toThrow(PayException::class, 'Malformed pay-signature header');
        }
    });

    it('refuses to verify against an empty or blank secret', function () {
        expect(fn () => new WebhookVerifier(''))->toThrow(PayException::class, 'A webhook secret is required')
            ->and(fn () => new WebhookVerifier('   '))->toThrow(PayException::class, 'A webhook secret is required');

        config()->set('noria-pay.webhook_secret', null);
        app()->forgetInstance(WebhookVerifier::class);

        expect(fn () => app(WebhookVerifier::class))->toThrow(PayException::class, 'A webhook secret is required');
    });
});
