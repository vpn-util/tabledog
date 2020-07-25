<?php

namespace TableDog\Command;

use \TableDog\Util\IPRange;

class Parser {
    private static function parseTable(string $table): int {
        $table = strtoupper($table);

        switch ($table) {
        case "PREROUTING":
            return ICommand::TABLE_PREROUTING;

        case "POSTROUTING":
            return ICommand::TABLE_POSTROUTING;

        default:
            throw new ParserException("Unknown table!");
        }
    }

    private static function parseIPv4WithCIDR(
        string $ipAddressWithCIDR): IPRange {
        $result = IPRange::parseIPv4WithCIDR($ipAddressWithCIDR);

        if ($result === NULL)
            throw new ParserException("Invalid IP with CIDR suffix!");

        return $result;
    }

    private $commandName;
    private $arguments;

    public function __construct() {
        $this->commandName = NULL;
        $this->arguments = NULL;
    }

    private function isFilled(): bool {
        return ($this->commandName !== NULL) && (is_array($this->arguments));
    }

    private function expectFilledParser(): void {
        if (!$this->isFilled())
            throw new ParserException("Parser not filled!");
    }

    private function expectEmptyParser(): void {
        if ($this->isFilled())
            throw new ParserException("Parser not empty!");
    }

    private function expectArgumentCount(int $count): void {
        $this->expectFilledParser();

        if (count($this->arguments) !== $count)
            throw new ParserException("Invalid number of arguments!");
    }

    public function fill(string $cmd): void {
        $this->expectEmptyParser();

        # Separating all parts of the command, the delimiters are space
        # (ASCII: 0x20) and tabulator (ASCII: 0x09)
        #
        # Also we want to trim all components (aka removing leading and
        # following space/tabulator characters).

        $components = array();
        $currentComponent = "";

        for ($i = 0; $i < strlen($cmd); $i++) {
            $ascii = ord($cmd[$i]);

            if ($ascii === 0x09 || $ascii === 0x20) {
                # If $currentComponent is empty, we just ignore this. Otherwise
                # we interpret this as delimiter.

                if (strlen($currentComponent) === 0)
                    continue;

                array_push($components, $currentComponent);
                $currentComponent = "";
                continue;
            }

            # We do not allow non-ASCII characters

            if ($i > 0x7F)
                throw new ParserException("Non-ASCII character in command!");

            # Since everything is interpreted case-independently, we also want
            # to put everything to upper case.

            $currentComponent .= strtoupper($cmd[$i]);
        }

        if (strlen($currentComponent) > 0)
            array_push($components, $currentComponent);

        # Validating that there is at least one single component

        if (count($components) === 0)
            throw new ParserException("Empty command!");
            
        # Separating the command name and the command arguments

        $this->commandName = $components[0];
        $this->arguments = array_slice($components, 1);
    }

    public function getCommand(): ICommand {
        $this->expectFilledParser();
            
        switch ($this->commandName) {
        case "QUERY":
            $this->expectArgumentCount(2);

            $table = Parser::parseTable($this->arguments[0]);
            $originalAddr = Parser::parseIPv4WithCIDR($this->arguments[1]);
            
            return new QueryCommand($table, $originalAddr);

        case "SET":
            $this->expectArgumentCount(3);

            $table = Parser::parseTable($this->arguments[0]);
            $originalAddr = Parser::parseIPv4WithCIDR($this->arguments[1]);
            $replacementAddr = Parser::parseIPv4WithCIDR($this->arguments[2]);
            
            return new SetCommand($table, $originalAddr, $replacementAddr);

        case "DELETE":
            $this->expectArgumentCount(2);

            $table = Parser::parseTable($this->arguments[0]);
            $originalAddr = Parser::parseIPv4WithCIDR($this->arguments[1]);
            
            return new DeleteCommand($table, $originalAddr);

        default:
            throw new ParserException("Unknown command!");
        }
    }
}
