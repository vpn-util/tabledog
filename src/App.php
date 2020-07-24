<?php

namespace TableDog;

use \TableDog\Server\Connection;
use \TableDog\Server\Server;

# The autoload script registers an autoloader that automatically includes the
# required files when we're referencing a class.

require_once(__DIR__."/../vendor/autoload.php");

class App {
    public static function main(array $args): int {
        $server = new Server("localhost", 8080, 24, 50000);

        $server->runLoop(function ($cnts) {
            foreach ($cnts as $cnt) {
                echo("Read: ".($cnt->getBufferedInput())."\n");
                $cnt->setBufferedInput("");
            }

            return TRUE;
        });

        return 0;
    }
}

# Since this whole application follows the OOP pattern, we just invoke the
# App::main method to switch into an OOP context.

exit(App::main($argv));
