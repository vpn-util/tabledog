<?php

namespace TableDog\Table;

use TableDog\Util\IPRange;

class Entry {
    private $originalAddr;
    private $replacementAddr;

    public function __construct(
        IPRange $originalAddr,
        IPRange $replacementAddr) {
        $this->originalAddr = $originalAddr;
        $this->replacementAddr = $replacementAddr;
    }

    public function getOriginalAddress(): IPRange {
        return $this->originalAddr;
    }

    public function getReplacementAddress(): IPRange {
        return $this->replacementAddr;
    }
}
