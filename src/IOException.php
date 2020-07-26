<?php

namespace TableDog;

/**
 * Indicates that an input/output-operation failed.
 */
class IOException extends \Exception {
    public function __construct(string $msg) {
        parent::__construct($msg);
    }
}
