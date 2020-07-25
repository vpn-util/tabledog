<?php

namespace TableDog;

use \Garden\Cli\Cli;

use \TableDog\Response\ErrorResponse;
use \TableDog\Response\Formatter;
use \TableDog\Response\Response;
use \TableDog\Server\Connection;
use \TableDog\Server\IOException;
use \TableDog\Server\Server;

# The autoload script registers an autoloader that automatically includes the
# required files when we're referencing a class.

require_once(__DIR__."/../vendor/autoload.php");

class App {
    private static $RUNNING;

    public static function main(array $args): int {
        # Parameter parsing

        $cli = new Cli();

        $cli->description("Launches the TableDog server.")
            ->opt("host:h", "The host to bind to.", true)
            ->opt("port:p", "The port to bind to.", true, "integer")
            ->opt(
                "backlog:b",
                "The backlog size. (Defaults to 24)",
                false,
                "integer")
            ->opt(
                "prerouting-table:r",
                "The path to the prerouting table file.",
                true)
            ->opt(
                "postrouting-table:o",
                "The path to the postrouting table file.",
                true)
            ->opt(
                "dirty-timeout:t",
                "The maximum timeout in milliseconds after an unsuccessful ".
                    "flock attempt. (Defaults to 50)",
                false,
                "integer");

        $options = $cli->parse($args);

        # Copying the single options into variables

        $host = $options->getOpt("host");
        $port = $options->getOpt("port");
        $backlog = $options->getOpt("backlog", 24);
        $pathPreroutingTable = $options->getOpt("prerouting-table");
        $pathPostroutingTable = $options->getOpt("postrouting-table");
        $dirtyTimeout = $options->getOpt("dirty-timeout", 50);

        # Setting up the application starts here.

        App::$RUNNING = TRUE;

        # Setting up the SIGINT handler. (SIGINT will terminate the
        # application.)
        #
        # NOTE: The pcntl extension is only available on unixoid systems.

        $windows = FALSE;

        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            \pcntl_signal(SIGINT, function (int $signo) {
                App::$RUNNING = FALSE;
            }, FALSE);
        } else {
            sapi_windows_set_ctrl_handler(function (int $event) {
                if ($event !== PHP_WINDOWS_EVENT_CTRL_C) {
                    return;
                }

                App::$RUNNING = FALSE;
            });

            $windows = TRUE;
        }

        # Launching the server
        #
        # NOTE: We need to multiply the dirtyTimeout by 1000 because the server
        #       expects that the value's unit is microseconds (µs).

        $server = new Server($dirtyTimeout * 1000);
        $server->bind($host, $port, $backlog);

        $dirty = FALSE;

        while (App::$RUNNING) {
            # If we're on Windows, we always enable the dirty flag:
            #  - The dirty flag sets a defined timeout for the
            #    socket_select-operation.
            #  - On Windows, Ctrl+C will not cause a SIGINT and the registered
            #    Ctrl+C handler is prevented from execution until the
            #    socket_select-operation returns.
            #  => We need to ensure that socket_select returns periodically on
            #     Windows, otherwise terminating the process will depend on the
            #     return of socket_select.

            $connections = $server->proceed($dirty || $windows);

            # Handling the client requests

            foreach ($connections as $cnt) {
                # TODO: This is optimizable (we may collect all table
                #       modifications first and perform them in a single
                #       synchronized bulk operation).

                $dirty = self::handleConnection($cnt) || $dirty;
            }
        }

        $server->cleanup();
        return 0;
    }

    private static function handleConnection(Connection $cnt): bool {
        # If there is no CRLF (ASCII: 0x0D, 0x0A), we:
        #  - continue reading, if the input buffer's maximum capacity has not
        #    been reached yet.
        #  - write an error message and clear the input buffer, if the input
        #    buffer's maximum capacity has been reached.

        $input = $cnt->getBufferedInput();
        $idxCRLF = \strpos($input, "\r\n");

        if ($idxCRLF === FALSE) {
            if ($cnt->getRemainingReadCapacity() > 0) {
                return TRUE;
            }

            $formatter = new Formatter();
            $formatter->setResponse(new ErrorResponse(
                Response::TYPE_GENERAL,
                ErrorResponse::REASON_REQUEST,
                "Invalid request!"));


            $cnt->setBufferedInput("");
            $cnt->setBufferedOutput($formatter->format());

            return TRUE;
        }

        $formatter = new Formatter();
            $formatter->setResponse(new ErrorResponse(
                Response::TYPE_GENERAL,
                ErrorResponse::REASON_UNKNOWN,
                "Not Implemented."));


        $cnt->setBufferedInput("");
        $cnt->setBufferedOutput($formatter->format());

        return TRUE;
    }
}

# Since this whole application follows the OOP pattern, we just invoke the
# App::main method to switch into an OOP context.

exit(App::main($argv));
