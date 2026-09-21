<?php

namespace NoriaLabs\Pay\Facades;

use Illuminate\Support\Facades\Facade;
use NoriaLabs\Pay\Pay as Client;
use NoriaLabs\Pay\Resources\Charges;
use NoriaLabs\Pay\Resources\PaymentLinks;
use NoriaLabs\Pay\Resources\PaymentMethods;
use NoriaLabs\Pay\Resources\Payouts;
use NoriaLabs\Pay\Resources\Refunds;
use NoriaLabs\Pay\Resources\Transactions;
use NoriaLabs\Pay\Resources\Webhooks;

/**
 * @method static Charges charges()
 * @method static Payouts payouts()
 * @method static Refunds refunds()
 * @method static Transactions transactions()
 * @method static PaymentMethods paymentMethods()
 * @method static PaymentLinks paymentLinks()
 * @method static Webhooks webhooks()
 * @method static array<string, mixed> waitForSettlement(string $id, int $timeoutSeconds = 120, int $intervalSeconds = 3)
 *
 * @see Client
 */
class Pay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
