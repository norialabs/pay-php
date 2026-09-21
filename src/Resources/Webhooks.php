<?php

namespace NoriaLabs\Pay\Resources;

class Webhooks extends Resource
{
    /**
     * @param  list<string>  $eventTypes
     * @return array<string, mixed>
     */
    public function create(string $url, array $eventTypes = [], ?string $description = null): array
    {
        return $this->pay->request('POST', '/v1/webhook-endpoints', [
            'url' => $url,
            'event_types' => $eventTypes,
            'description' => $description,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function list(): array
    {
        return $this->pay->request('GET', '/v1/webhook-endpoints');
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function update(string $id, array $changes): array
    {
        return $this->pay->request('PATCH', '/v1/webhook-endpoints/'.rawurlencode($id), $changes);
    }

    public function remove(string $id): void
    {
        $this->pay->request('DELETE', '/v1/webhook-endpoints/'.rawurlencode($id));
    }
}
