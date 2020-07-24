<?php

namespace TableDog;

use \TableDog\Server\Connection;
use \TableDog\Server\IOException;
use \TableDog\Server\Server;

# The autoload script registers an autoloader that automatically includes the
# required files when we're referencing a class.

require_once(__DIR__."/../vendor/autoload.php");

class App {
    private static $RUNNING;

    public static function main(array $args): int {
        $host = "localhost";
        $port = 8080;

        App::$RUNNING = TRUE;

        # Setting up the SIGINT handler. (SIGINT will terminate the
        # application.)
        #
        # NOTE: The pcntl extension is only available on unixoid systems.

        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            \pcntl_signal(SIGINT, function (int $signo) {
                App::$RUNNING = FALSE;
            }, FALSE);
        }

        # Launching the server

        $server = new Server(50000);
        $server->bind($host, $port, 24);

        $dirty = FALSE;

        while (App::$RUNNING) {
            $connections = $server->proceed($dirty);

            foreach ($connections as $cnt) {
                $cnt->setBufferedOutput($cnt->getBufferedInput());
                $cnt->setBufferedInput("");
            }
        }

        $server->cleanup();
        return 0;
    }
}

# Since this whole application follows the OOP pattern, we just invoke the
# App::main method to switch into an OOP context.

exit(App::main($argv));
