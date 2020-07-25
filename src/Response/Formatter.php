<?php

namespace TableDog\Response;

class Formatter {
    private static function stringifyType(int $type): string {
        switch ($type) {
        case Response::TYPE_GENERAL:
            return "GENERAL";

        case Response::TYPE_QUERY:
            return "QUERY";

        case Response::TYPE_SET:
            return "SET";

        case Response::TYPE_DELETE:
            return "DELETE";

        default:
            throw new \Exception("Invalid response type!");
        }
    }

    private static function stringifyErrorReason(string $reason): string {
        switch ($reason) {
        case ErrorResponse::REASON_IO:
            return "IO";

        case ErrorResponse::REASON_REQUEST:
            return "REQ";

        case ErrorResponse::REASON_UNKNOWN:
            return "UKN";

        default:
            throw new \Exception("Invalid error reason!");
        }
    }

    private $response;

    public function __construct() {
        $this->response = NULL;
    }

    private function expectResponseSet(): void {
        if ($this->response === NULL)
            throw new \Exception("Response not set!");
    }

    private function expectResponseNotSet(): void {
        if ($this->response !== NULL)
            throw new \Exception("Response already set!");
    }

    public function setResponse(Response $response): void {
        $this->expectResponseNotSet();
        $this->response = $response;
    }

    public function format(): string {
        $this->expectResponseSet();

        if ($this->response instanceof ErrorResponse) {
            $type = Formatter::stringifyType($this->response->getType());
            $reason = Formatter::stringifyErrorReason(
                $this->response->getReason());

            $message = $this->response->getMessage();

            return sprintf(
                "%s-FAILED\r\nE%s\r\n%s\r\n",
                $type,
                $reason,
                $message);
        }

        if ($this->response instanceof QueryOkResponse) {
            $type = Formatter::stringifyType($this->response->getType());
            $entries = $this->response->getEntries();

            $result = sprintf("%s-OK\r\nCOUNT %d\r\n", $type, count($entries));

            foreach ($entries as $entry) {
                $result .= sprintf(
                    "ENTRY %s %s\r\n",
                    $entry->getOriginalAddress(),
                    $entry->getReplacementAddres());
            }
            
            return $result;
        }

        # NOTE: The OkResponse needs to be checked after its subclasses,
        #       otherwise those will not be processed correctly.

        if ($this->response instanceof OkResponse) {
            $type = Formatter::stringifyType($this->response->getType());

            return sprintf("%s-OK\r\n", $type);
        }

        throw new \Exception("Unsupported response!");
    }
}
