<?php

namespace TableDog\Server;

/**
 * The TCP/IPv4 server for the REPL.
 */
class Server {
    private static $NULL = NULL;

    private $fdServerSocket;
    private $dirtyTimeout;
    private $connections;

    public function __construct(int $dirtyTimeout) {
        $this->dirtyTimeout = $dirtyTimeout;
        $this->connections = array();

        # Creating and binding the TCP server socket

        $this->fdServerSocket = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($this->fdServerSocket === FALSE)
            throw new \IOException("Cannot create socket!");
    }

    public function bind(string $addr, int $port, int $backlog): void {
        if (\socket_bind($this->fdServerSocket, $addr, $port) === FALSE)
            throw new \IOException("Cannot bind socket to $addr:$port!");

        if (\socket_listen($this->fdServerSocket, $backlog) === FALSE)
            throw new \IOException(
                "Cannot listen for incoming connections!\n");
    }

    public function proceed(bool $dirty): array {
        $wReadFd = array();
        $wWriteFd = array();
        
        # We want to read from any socket whose input buffer has not been
        # filled completely yet and we want to write to the sockets of those
        # connections that have something to write.
        
        array_push($wReadFd, $this->fdServerSocket);
        
        foreach ($this->connections as $cnt) {
            $fd = $cnt->getSocketFd();
   
            if ($cnt->getRemainingReadCapacity() > 0)
                array_push($wReadFd, $fd);
   
            if ($cnt->hasBufferedOutput())
                array_push($wWriteFd, $fd);
        }
        
        # Now, we just wait until we can read or write something on any socket.
        #
        # If the last iteration was dirty (which indicates that the handler was
        # unable to complete its operation due to external circumstances), we
        # only wait for a couple of microseconds (instead of a potential
        # eternity).
        
        $wMicroseconds = 0;
        $wSeconds = NULL;
   
        if ($dirty) {
            $wMicroseconds = $this->dirtyTimeout;
            $wSeconds = 0;
        }

        $selectState = \socket_select(
            $wReadFd,
            $wWriteFd,
            Server::$NULL,
            $wSeconds,
            $wMicroseconds);

        echo("Select finished! $selectState, $wMicroseconds\n");

        if ($selectState === FALSE) {
            throw new \IOException("Select failed!");
        }
        
        # Handling the ready file descriptors
        
        foreach ($wReadFd as $readableFd) {
            # The server socket file descriptor needs special care: We need to
            # accept incoming connections instead of reading data from it.
            
            if ($readableFd == $this->fdServerSocket) {
                $connectionFd = \socket_accept($this->fdServerSocket);
                
                if ($connectionFd !== FALSE) {
                    $this->connections[(int) $connectionFd] =
                        new Connection($connectionFd);
                }
                
                continue;
            }
            
            $cnt = $this->connections[(int) $readableFd];
            
            if (!$cnt->read()) {
                # If reading from a connection fails, we just close it.
                
                $cnt->close();
                unset($this->connections[(int) $readableFd]);
            }
        }
        
        foreach ($wWriteFd as $writableFd) {
            $cnt = $this->connections[(int) $writableFd];
            
            if (!$cnt->write()) {
                # If writing to a connection fails, we just close it.
                
                $cnt->close();
                unset($this->connections[(int) $writableFd]);
            }
        }
        
        # Calling the input handler and passing all of those Connection
        # instances that have a non-empty input buffer.
        
        $readableConnections = array();
        
        foreach ($this->connections as $cnt) {
            if (!$cnt->hasBufferedInput())
                continue;
                
            array_push($readableConnections, $cnt);
        }

        return $readableConnections;
    }

    public function cleanup(): void {
        foreach ($this->connections as $cnt) {
            $cnt->close();
        }

        array_splice($this->connections, 0);
        \socket_close($this->fdServerSocket);
    }
}
