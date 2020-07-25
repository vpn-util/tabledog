<?php

namespace TableDog\Response;

abstract class Response {
    public const TYPE_GENERAL   = 0x00;
    public const TYPE_QUERY     = 0x01;
    public const TYPE_SET       = 0x02;
    public const TYPE_DELETE    = 0x03;

    private $type;

    protected function __construct(int $type) {
        if ($type !== Response::TYPE_GENERAL &&
            $type !== Response::TYPE_QUERY &&
            $type !== Response::TYPE_SET &&
            $type !== Response::TYPE_DELETE) {
            throw new \Exception("Invalid response type!");
        }

        $this->type = $type;
    }

    public function getType(): int {
        return $this->type;
    }
}
