<?php

namespace NoriaLabs\Pay\Resources;

class Refunds extends Resource
{
    /**
     * Omit amount_minor to refund what settled.
     *
     * @param  array<string, mixed>  $refund
     * @return array<string, mixed>
     */
    public function create(array $refund, string $idempotencyKey): array
    {
        return $this->pay->request('POST', '/v1/refunds', $refund, $this->idempotent($idempotencyKey));
    }
}
