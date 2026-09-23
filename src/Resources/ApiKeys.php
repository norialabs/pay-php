<?php

namespace NoriaLabs\Pay\Resources;

class ApiKeys extends Resource
{
    /**
     * The token is in this response and never again. A key cannot carry a scope, an environment
     * or a rate the key issuing it does not already hold.
     *
     * @param  list<string>  $scopes
     * @return array<string, mixed>
     */
    public function issue(string $name, array $scopes, string $environment = 'live', ?int $rateLimitPerSecond = null): array
    {
        return $this->pay->request('POST', '/v1/api-keys', [
            'name' => $name,
            'scopes' => $scopes,
            'environment' => $environment,
            ...($rateLimitPerSecond === null ? [] : ['rate_limit_per_second' => $rateLimitPerSecond]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(array $filters = []): array
    {
        return $this->pay->request('GET', '/v1/api-keys'.$this->pay->query($filters));
    }

    public function revoke(string $id): void
    {
        $this->pay->request('DELETE', '/v1/api-keys/'.rawurlencode($id));
    }
}
