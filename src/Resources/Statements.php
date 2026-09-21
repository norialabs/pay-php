<?php

namespace NoriaLabs\Pay\Resources;

class Statements extends Resource
{
    /**
     * Settles what it matches and reports the rest. It never invents a transaction: a
     * statement carries bank charges and internal transfers as well as payments. The same
     * rows uploaded twice replay the first result.
     *
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>
     */
    public function import(array $statement): array
    {
        return $this->pay->request('POST', '/v1/statements', $statement);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->pay->request('GET', '/v1/statements/'.rawurlencode($id));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(array $filters = []): array
    {
        return $this->pay->request('GET', '/v1/statements'.$this->pay->query($filters));
    }
}
