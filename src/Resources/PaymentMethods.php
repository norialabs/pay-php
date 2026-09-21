<?php

namespace NoriaLabs\Pay\Resources;

class PaymentMethods extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function list(): array
    {
        return $this->pay->request('GET', '/v1/payment-methods');
    }

    /**
     * @param  array<string, mixed>  $method
     * @return array<string, mixed>
     */
    public function set(string $provider, array $method): array
    {
        return $this->pay->request('PUT', '/v1/payment-methods/'.rawurlencode($provider), $method);
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $provider): array
    {
        return $this->pay->request('POST', '/v1/payment-methods/'.rawurlencode($provider).'/verify');
    }

    public function remove(string $provider): void
    {
        $this->pay->request('DELETE', '/v1/payment-methods/'.rawurlencode($provider));
    }
}
