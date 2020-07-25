<?php

namespace TableDog\Response;

class QueryOkResponse extends OkResponse {
    private $entries;

    public function __construct(array $entries) {
        parent::__construct(Response::TYPE_QUERY);
        $this->entries = $entries;
    }

    public function getEntries(): array {
        return $this->entries;
    }
}
