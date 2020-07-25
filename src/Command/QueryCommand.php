<?php

namespace TableDog\Command;

use \TableDog\Util\IPRange;

class QueryCommand implements ICommand {
    private $table;
    private $originalAddress;

    public function __construct(int $table, IPRange $originalAddress) {
        if ($table !== ICommand::TABLE_PREROUTING &&
            $table !== ICommand::TABLE_POSTROUTING) {
            throw new \Exception("Unknown table!");
        }

        $this->table = $table;
        $this->originalAddress = $originalAddress;
    }

    public function getTable(): int {
        return $this->table;
    }

    public function getOriginalAddress(): IPRange {
        return $this->originalAddress;
    }
}
