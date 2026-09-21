<?php

namespace NoriaLabs\Pay\Resources;

class Payouts extends Resource
{
    /**
     * @param  array<string, mixed>  $payout
     * @return array<string, mixed>
     */
    public function create(array $payout, string $idempotencyKey): array
    {
        return $this->pay->request('POST', '/v1/payouts', $payout, $this->idempotent($idempotencyKey));
    }
}
