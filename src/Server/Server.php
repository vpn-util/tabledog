<?php

namespace TableDog\Server;

class Server {
    private static $NULL = NULL;

    private $fdServerSocket;
    private $backlog;
    private $dirtyTimeout;
    private $connections;

    public function __construct(
        string $addr,
        int $port,
        int $backlog,
        int $dirtyTimeout) {

        $this->backlog = $backlog;
        $this->dirtyTimeout = $dirtyTimeout;
        $this->connections = array();

        # Creating and binding the TCP server socket

        $this->fdServerSocket = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($this->fdServerSocket === FALSE) {
            throw new \Error("Cannot create socket!");
        }

        if (\socket_bind($this->fdServerSocket, $addr, $port) === FALSE) {
            throw new \Error("Cannot bind socket to $addr:$port!");
        }
    }

    public function runLoop(callable $inputHandler): void {
        # Before we can accept incoming connections, we need to enable
        # listening.

        if (\socket_listen($this->fdServerSocket, $this->backlog) === FALSE) {
            throw new \Error("Cannot listen for incoming connections!\n");
        }

        # Now, we're just entering an endless socket_select-loop that we use
        # for handling incoming connections and responding to requests.

        $dirty = FALSE;

        while (TRUE) {
            $wReadFd = array();
            $wWriteFd = array();

            # We always want to read from any socket and we want to write to
            # the sockets of those connections that have something to write.

            array_push($wReadFd, $this->fdServerSocket);

            foreach ($this->connections as $cnt) {
                $fd = $cnt->getSocketFd();

                array_push($wReadFd, $fd);

                if ($cnt->hasBufferedOutput())
                    array_push($wWriteFd, $fd);
            }

            # Now, we just wait until we can read or write something on any
            # socket.
            #
            # If the last iteration was dirty (which indicates that the handler
            # was unable to complete its operation due to external
            # circumstances), we only wait for a couple of microseconds
            # (instead of a potential eternity).

            $wMicroseconds = 0;

            if ($dirty) {
                $wMicroseconds = $this->dirtyTimeout;
            }

            \socket_select(
                $wReadFd,
                $wWriteFd,
                Server::$NULL,
                0,
                $wMicroseconds);

            # Handling the ready file descriptors

            foreach ($wReadFd as $readableFd) {
                # The server socket file descriptor needs special care: We need
                # to accept incoming connections instead of reading data from
                # it.

                if ($readableFd == $this->fdServerSocket) {
                    $connectionFd = \socket_accept($this->fdServerSocket);

                    if ($connectionFd !== FALSE) {
                        $this->connections[(int) $connectionFd] =
                            new Connection($connectionFd);
                    }

                    continue;
                }

                $this->connections[(int) $readableFd]->read();
            }

            foreach ($wWriteFd as $writableFd) {
                $this->connections[(int) $writableFd]->write();
            }

            # Calling the input handler and passing all of those Connection
            # instances that have a non-empty input buffer.
            
            $readableConnections = array();

            foreach ($this->connections as $cnt) {
                if (!$cnt->hasBufferedInput()) {
                    continue;
                }

                array_push($readableConnections, $cnt);
            }

            $dirty = !$inputHandler($readableConnections);
        }
    }

    public function cleanup(): void {
        \socket_close($this->fdServerSocket);
    }
}
