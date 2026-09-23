<?php

namespace NoriaLabs\Pay\Resources;

class Account extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        return $this->pay->request('GET', '/v1/me');
    }
}
