<?php

namespace TableDog\Server;

/**
 * A connection between the {@link Server} and a client.
 */
class Connection {
    /**
     * The maximum capacity (in bytes) of the internal input buffer.
     */
    private const MAX_READ_LENGTH = 1024;

    /**
     * The file descriptor of the connection's TCP-socket.
     */
    private $fdSocket;

    /**
     * The input buffer.
     */
    private $bufIn;

    /**
     * The output buffer.
     */
    private $bufOut;

    public function __construct($fdSocket) {
        $this->fdSocket = $fdSocket;
        $this->bufIn = "";
        $this->bufOut = "";
    }

    /**
     * Returns whether the internal input buffer contains data or not.
     * 
     * @return  bool    Either <code>true</code>, if the internal input buffer
     *                  is not empty, otherwise <code>false</code>. 
     */
    public function hasBufferedInput(): bool {
        return strlen($this->bufIn) > 0;
    }

    /**
     * Returns whether the internal output buffer contains data or not.
     * 
     * @return  bool    Either <code>true</code>, if the internal output buffer
     *                  is not empty, otherwise <code>false</code>.
     */
    public function hasBufferedOutput(): bool {
        return strlen($this->bufOut) > 0;
    }

    /**
     * Returns the content of the internal input buffer.
     * 
     * @return  string  The content of the internal input buffer.
     */
    public function getBufferedInput(): string {
        return $this->bufIn;
    }

    /**
     * Returns the content of the internal output buffer.
     * 
     * @return  string  The content of the internal output buffer.
     */
    public function getBufferedOutput(): string {
        return $this->bufOut;
    }

    /**
     * Returns the file descriptor of the connection's socket.
     * 
     * @return  resource    The file descriptor of the connection's socket.
     */
    public function getSocketFd() {
        return $this->fdSocket;
    }

    public function getRemainingReadCapacity() {
        return Connection::MAX_READ_LENGTH - strlen($this->bufIn);
    }

    /**
     * Sets the content of the input buffer.
     * 
     * @param   string  $val    The content of the input buffer.
     */
    public function setBufferedInput(string $val): void {
        $this->bufIn = $val;
    }

    /**
     * Sets the content of the output buffer.
     * 
     * @param   string  $val    The content of the output buffer.
     */
    public function setBufferedOutput(string $val): void {
        $this->bufOut = $val;
    }

    /**
     * Receives data from the peer and stores it inside the internal input
     * buffer.
     * 
     * @return  bool    Either <code>true</code>, if the reading operation
     *                  succeded, otherwise <code>false</code>.
     */
    public function read(): bool {
        # Checking, if we are allowed to receive any further data

        if ($this->getRemainingReadCapacity() <= 0)
            throw new \Error("Attempted reading into a full buffer!");

        # Receiving data

        $buf = NULL;
        $length = \socket_recv(
            $this->fdSocket,
            $buf,
            Connection::MAX_READ_LENGTH,
            0);

        # Validation of the return code: If the it's FALSE or zero, the
        # connection has been closed or an error occurred. 

        if ($length === FALSE || $length === 0) {
            return false;
        }

        $this->bufIn .= $buf;
        return true;
    }

    /**
     * Sends data from the internal output buffer to the peer.
     * 
     * @return  bool    Either <code>true</code>, if the writing operation
     *                  succeded, otherwise <code>false</code>.
     */
    public function write(): bool {
        if (!$this->hasBufferedOutput())
            throw new \Error("Attempted writting an empty buffer!");

        $nBytes = socket_send(
            $this->fdSocket,
            $this->bufOut,
            strlen($this->bufOut));

        if ($nBytes === FALSE || $nBytes === 0) {
            return FALSE;
        }

        $this->bufOut = substr($this->bufOut, $nBytes);
        return TRUE;
    }

    /**
     * Closes the connection to the peer.
     */
    public function close(): void {
        socket_close($this->fdSocket);
    }
}
