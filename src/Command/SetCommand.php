<?php

namespace TableDog\Command;

use \TableDog\Util\IPRange;

class SetCommand implements ICommand {
    private $table;
    private $originalAddress;
    private $replacementAddress;

    public function __construct(
        int $table,
        IPRange $originalAddress,
        IPRange $replacementAddress) {
        if ($table !== ICommand::TABLE_PREROUTING &&
            $table !== ICommand::TABLE_POSTROUTING) {
            throw new \Exception("Unknown table!");
        }

        $this->table = $table;
        $this->originalAddress = $originalAddress;
        $this->replacementAddress = $replacementAddress;
    }

    public function getTable(): int {
        return $this->table;
    }

    public function getOriginalAddress(): IPRange {
        return $this->originalAddress;
    }

    public function getReplacementAddress(): IPRange {
        return $this->replacementAddress;
    }
}
