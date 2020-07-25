<?php

namespace TableDog\Response;

class ErrorResponse extends Response {
    public const REASON_IO      = 0x00;
    public const REASON_REQUEST = 0x01;
    public const REASON_UNKNOWN = 0x02;

    private $reason;
    private $message;

    public function __construct(int $type, int $reason, string $message) {
        parent::__construct($type);

        if ($reason !== self::REASON_IO &&
            $reason !== self::REASON_REQUEST &&
            $reason !== self::REASON_UNKNOWN) {
            throw new \Exception("Invalid error reason!");
        }

        # TODO: Further validation of $message is required (only ASCII
        #       characters allowed)

        if (strpos($message, "\r\n") !== FALSE)
            throw new \Exception("Message contains illegal CRLF sequence!");

        $this->reason = $reason;
        $this->message = $message;
    }

    public function getReason(): int {
        return $this->reason;
    }

    public function getMessage(): string {
        return $this->message;
    }
}
