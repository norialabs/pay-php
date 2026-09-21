<?php

namespace NoriaLabs\Pay\Resources;

class Transactions extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->pay->request('GET', '/v1/transactions/'.rawurlencode($id));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(array $filters = []): array
    {
        return $this->pay->request('GET', '/v1/transactions'.$this->pay->query($filters));
    }

    /**
     * The full audit trail, including every raw provider exchange.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function events(string $id, array $filters = []): array
    {
        return $this->pay->request('GET', '/v1/transactions/'.rawurlencode($id).'/events'.$this->pay->query($filters));
    }
}
