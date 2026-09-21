<?php

namespace NoriaLabs\Pay\Resources;

class Charges extends Resource
{
    /**
     * @param  array<string, mixed>  $charge
     * @return array<string, mixed>
     */
    public function create(array $charge, string $idempotencyKey): array
    {
        return $this->pay->request('POST', '/v1/charges', $charge, $this->idempotent($idempotencyKey));
    }

    /**
     * Money that arrived off-rail: cash, a bank transfer, a receipt read off a statement.
     *
     * @param  array<string, mixed>  $charge
     * @return array<string, mixed>
     */
    public function record(array $charge, string $idempotencyKey): array
    {
        return $this->pay->request('POST', '/v1/charges/record', $charge, $this->idempotent($idempotencyKey));
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(string $id): array
    {
        return $this->pay->request('POST', '/v1/charges/'.rawurlencode($id).'/cancel');
    }
}
