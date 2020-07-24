<?php

namespace TableDog\Server;

class Connection {
    private const MAX_READ_LENGTH = 1024;

    private $fdSocket;
    private $bufIn;
    private $bufOut;

    public function __construct($fdSocket) {
        $this->fdSocket = $fdSocket;
        $this->bufIn = "";
        $this->bufOut = "";
    }

    public function hasBufferedInput(): bool {
        return strlen($this->bufIn) > 0;
    }

    public function hasBufferedOutput(): bool {
        return strlen($this->bufOut) > 0;
    }

    public function getBufferedInput(): string {
        return $this->bufIn;
    }

    public function getBufferedOutput(): string {
        return $this->bufOut;
    }

    public function getSocketFd() {
        return $this->fdSocket;
    }

    public function setBufferedInput(string $val): void {
        $this->bufIn = $val;
    }

    public function setBufferedOutput(string $val): void {
        $this->bufOut = $val;
    }

    public function read(): void {
        $buf = NULL;

        if (\socket_recv(
            $this->fdSocket,
            $buf,
            Connection::MAX_READ_LENGTH,
            0) === FALSE) {
            throw new \Error("Failed reading data!");
        }

        $this->bufIn .= $buf;
    }

    public function write(): void {
        $nBytes = socket_send(
            $this->fdSocket,
            $this->bufOut,
            strlen($this->bufOut));

        if ($nBytes === FALSE) {
            throw new \Error("Failed writing data!");
        }

        $this->bufOut = substr($this->bufOut, $nBytes);
    }

    public function close(): void {
        socket_close($this->fdSocket);
    }
}
