<?php

namespace TableDog\Response;

class OkResponse extends Response {
    public function __construct(int $type) {
        parent::__construct($type);
    }
}
