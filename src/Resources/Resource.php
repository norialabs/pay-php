<?php

namespace NoriaLabs\Pay\Resources;

use NoriaLabs\Pay\Pay;

abstract class Resource
{
    public function __construct(protected readonly Pay $pay) {}

    /**
     * @return array<string, string>
     */
    protected function idempotent(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }
}
