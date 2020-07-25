<?php

namespace TableDog\Command;

/**
 * Indicates that parsing a command failed.
 */
class ParserException extends \Exception {
    public function __construct($msg) {
        parent::__construct($msg);
    }
}
