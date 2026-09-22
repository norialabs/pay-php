# `norialabs/pay`

Laravel client for **Noria Pay**. Collect, pay out and refund across M-PESA, SasaPay and
Paystack through one internal service instead of wiring a provider into every product.

```bash
composer require norialabs/pay
php artisan vendor:publish --tag="noria-pay-config"
```

```dotenv
PAY_API_KEY=pay_live_…
PAY_URL=https://pay.noria.co.ke
PAY_WEBHOOK_SECRET=whsec_…
```

## Collecting

An idempotency key is a required argument, not an option you can forget. Use something your
own system already owns — the invoice id plus the attempt — not a fresh uuid per call, or the
key protects nothing.

```php
use NoriaLabs\Pay\Facades\Pay;

$charge = Pay::charges()->create([
    'amount_minor' => 150_000,          // KES 1,500.00
    'channel'      => 'mpesa',
    'reference'    => $invoice->number, // yours; the service never interprets it
    'description'  => 'April rent',
    'payer_phone'  => $customer->phone,
    'metadata'     => ['invoice_id' => (string) $invoice->id],
], "invoice:{$invoice->id}:{$attempt}");

// $charge['next_action']['type'] is 'await_payer' (the STK prompt is already sent)
// or 'redirect' (send them to ['url']).
```

## The one error that is not like the others

A `504` carrying `outcome_unknown` means the provider never answered. The payer **may already
have been debited**. The client never retries it, and neither should you.

```php
use NoriaLabs\Pay\Exceptions\PayException;

try {
    Pay::charges()->create($charge, $key);
} catch (PayException $e) {
    if ($e->isOutcomeUnknown()) {
        $settled = Pay::waitForSettlement($e->transactionId());
        // resolve against $settled['status'], never by charging again
    }
    throw $e;
}
```

## Receiving webhooks

Verify against the **raw body**. `$request->all()` has already decoded it, and re-encoding
reorders keys, so the signature will not match.

```php
use NoriaLabs\Pay\WebhookVerifier;

Route::post('/hooks/pay', function (Request $request, WebhookVerifier $verifier) {
    $event = $verifier->verify($request->getContent(), $request->header('pay-signature', ''));

    if ($event['type'] === 'succeeded') {
        // settled_minor is what actually moved, which is not always amount_minor: a payer can
        // underpay an STK prompt, and Paystack deducts its fee before settlement.
        Payment::settle($event['data']['reference'], $event['data']['settled_minor'], $event['data']['fee_minor']);
    }

    return response()->noContent();
})->withoutMiddleware(VerifyCsrfToken::class);
```

It throws on a bad signature, a wrong secret, or a timestamp outside the five-minute
tolerance, so a replay cannot post twice.

## Amounts

Always minor units. The service rejects a KES amount that is not a whole number of shillings
rather than rounding it, because the rails settle whole shillings and a silent round only
surfaces in reconciliation.

## Surfaces

`Pay::charges()` · `payouts()` · `refunds()` · `transactions()` · `paymentMethods()` ·
`paymentLinks()` · `webhooks()`

## This is not `norialabs/payments`

That package wraps the providers directly and this service uses it internally. This one talks
to Noria Pay, which owns the persistence, the callbacks and the reconciliation that
`norialabs/payments` deliberately leaves to you.

Events are not delivered in order. Delivery runs in parallel and retries, so a later event can
arrive first; order on `created_at`, which is when the event happened rather than when the
attempt went out. Every payload also carries the transaction's status as it stood at delivery,
so acting on that is safe whatever order they arrive in.
