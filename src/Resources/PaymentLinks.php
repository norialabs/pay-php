<?php

namespace NoriaLabs\Pay\Resources;

class PaymentLinks extends Resource
{
    /**
     * @param  array<string, mixed>  $link
     * @return array<string, mixed>
     */
    public function create(array $link): array
    {
        return $this->pay->request('POST', '/v1/payment-links', $link);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->pay->request('GET', '/v1/payment-links/'.rawurlencode($id));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(array $filters = []): array
    {
        return $this->pay->request('GET', '/v1/payment-links'.$this->pay->query($filters));
    }

    /**
     * @param  array<string, mixed>  $link
     * @return array<string, mixed>
     */
    public function update(string $id, array $link): array
    {
        return $this->pay->request('PATCH', '/v1/payment-links/'.rawurlencode($id), $link);
    }

    public function close(string $id): void
    {
        $this->pay->request('DELETE', '/v1/payment-links/'.rawurlencode($id));
    }
}
