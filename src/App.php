<?php

namespace TableDog;

use \Garden\Cli\Cli;

use \TableDog\Command\DeleteCommand;
use \TableDog\Command\ICommand;
use \TableDog\Command\Parser;
use \TableDog\Command\ParserException;
use \TableDog\Command\QueryCommand;
use \TableDog\Command\SetCommand;
use \TableDog\IOException;
use \TableDog\Response\ErrorResponse;
use \TableDog\Response\Formatter;
use \TableDog\Response\OkResponse;
use \TableDog\Response\QueryOkResponse;
use \TableDog\Response\Response;
use \TableDog\Server\Connection;
use \TableDog\Server\Server;
use \TableDog\Table\Entry;
use \TableDog\Table\InvalidTableException;
use \TableDog\Table\LockException;
use \TableDog\Table\Table;
use \TableDog\Util\IPRange;

# The autoload script registers an autoloader that automatically includes the
# required files when we're referencing a class.

require_once(__DIR__."/../vendor/autoload.php");

/**
 * The class that contains the main method and the main loop.
 */
class App {
    /**
     * The singleton instance of the App class.
     */
    private static $instance;

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

        self::$instance = new App(
            $pathPreroutingTable,
            $pathPostroutingTable,
            $host,
            $port,
            $backlog,
            $dirtyTimeout * 1000);

        # Setting up the SIGINT handler. (SIGINT will terminate the
        # application.)
        #
        # NOTE: The pcntl extension is only available on unixoid systems.

        $windows = FALSE;

        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            \pcntl_signal(SIGINT, function (int $signo) {
                global $app;
                App::$instance->stop();
            }, FALSE);
        } else {
            sapi_windows_set_ctrl_handler(function (int $event) {
                global $app;

                if ($event !== PHP_WINDOWS_EVENT_CTRL_C) {
                    return;
                }

                App::$instance->stop();
            });

            $windows = TRUE;
        }

        # If we're on Windows, we always enable the dirty flag:
        #
        #  - The dirty flag sets a defined timeout for the
        #    socket_select-operation.
        #
        #  - On Windows, Ctrl+C will not cause a SIGINT and the registered
        #    Ctrl+C handler is prevented from execution until the
        #    socket_select-operation returns.
        #
        #  => We need to ensure that socket_select returns periodically on
        #     Windows, otherwise terminating the process will depend on the
        #     return of socket_select.

        if ($windows) {
            App::$instance->setAlwaysDirty(TRUE);
        }

        App::$instance->run();
        App::$instance->cleanup();

        return 0;
    }

    private static function formatResponse(Response $res) {
        $fmt = new Formatter();
        $fmt->setResponse($res);
        return $fmt->format();
    }

    private static function parseCommand(string $cmdline) {
        $parser = new Parser();
        $parser->fill($cmdline);
        return $parser->getCommand();
    }

    /**
     * The flag that indicates whether further loop iterations are necessary
     * (true) or if the process shall terminate (false).
     */
    private $running;

    /**
     * The table instance that contains the PREROUTING mapping rules.
     */
    private $preroutingTable;

    /**
     * The table instance that contains the POSTROUTING mapping rules.
     */
    private $postroutingTable;

    /**
     * The server instance that receives incoming connections and manages
     * sending/receiving messages from connected clients.
     */
    private $server;

    /**
     * The flag that indicates whether the socket_select operation should
     * always be called with a timeout.
     */
    private $alwaysDirty;

    /**
     * Creates a new instance.
     */
    public function __construct(
        string $preroutingTablePath,
        string $postroutingTablePath,
        string $host,
        int $port,
        int $backlog,
        int $dirtyTimeout) {
        # Singleton-specific code

        if (self::$instance !== NULL)
            throw new \Exception("Singleton already instantiated!");

        # Actual initialization of members

        $this->running = TRUE;
        $this->alwaysDirty = FALSE;

        $this->preroutingTable = new Table($preroutingTablePath);
        $this->postroutingTable = new Table($postroutingTablePath);

        $this->server = new Server($dirtyTimeout);
        $this->server->bind($host, $port, $backlog);
    }

    private function handleInput(Connection $cnt): bool {
        $inBuf = $cnt->getBufferedInput();

        # Since each command needs to be terminated with a CRLF sequence, we
        # check for that.

        $crlfIdx = \strpos($inBuf, "\r\n");

        if ($crlfIdx === FALSE) {
            # The command has not been terminated yet. If the input buffer
            # limit has not been reached yet, we just continue reading bytes
            # from the peer, otherwise we empty the input buffer and send an
            # error response.

            if ($cnt->getRemainingReadCapacity() > 0) {
                return FALSE;
            }

            $cnt->setBufferedInput("");
            $cnt->setBufferedOutput(self::formatResponse(new ErrorResponse(
                Response::TYPE_GENERAL,
                ErrorResponse::REASON_REQUEST,
                "Invalid command!")));

            return FALSE;
        }

        # Parsing and handling the command

        $cmd = NULL;

        try {
            $cmd = self::parseCommand(substr($inBuf, 0, $crlfIdx));
        } catch (ParserException $ex) {
            $cnt->setBufferedInput("");
            $cnt->setBufferedOutput(self::formatResponse(new ErrorResponse(
                Response::TYPE_GENERAL,
                ErrorResponse::REASON_REQUEST,
                "Unknown command!")));

            return FALSE;
        }

        $response = NULL;

        try {
            if ($cmd instanceof DeleteCommand) {
                $response = $this->handleDelete($cmd);
            }

            if ($cmd instanceof QueryCommand) {
                $response = $this->handleQuery($cmd);
            }

            if ($cmd instanceof SetCommand) {
                $response = $this->handleSet($cmd);
            }

            $cnt->setBufferedInput("");
        } catch (LockException $ex) {
            return TRUE;
        }

        $cnt->setBufferedOutput(self::formatResponse($response));

        return FALSE;
    }

    private function handleDelete(DeleteCommand $delete): Response {
        $table = $this->getTable($delete->getTable());
        $originalAddr = $delete->getOriginalAddress();

        if ($table === NULL) {
            return new ErrorResponse(
                Response::TYPE_SET,
                ErrorResponse::REASON_UNKNOWN,
                "Unknown table identifier!");
        }

        try {
            $table->set(
                new Entry($originalAddr, new IPRange("0.0.0.0", 0)),
                TRUE);
        } catch (\Exception $ex) {
            var_dump($ex);

            return new ErrorResponse(
                Response::TYPE_DELETE,
                ErrorResponse::REASON_IO,
                "Internal exception.");
        }

        return new OkResponse(Response::TYPE_DELETE);
    }

    private function handleQuery(QueryCommand $query): Response {
        $table = $this->getTable($query->getTable());
        $originalAddr = $query->getOriginalAddress();

        if ($table === NULL) {
            return new ErrorResponse(
                Response::TYPE_QUERY,
                ErrorResponse::REASON_UNKNOWN,
                "Unknown table identifier!");
        }

        $result = NULL;

        try {
            $result = $table->query($originalAddr);
        } catch (\Exception $ex) {
            var_dump($ex);

            return new ErrorResponse(
                Response::TYPE_QUERY,
                ErrorResponse::REASON_IO,
                "Internal exception.");
        }

        return new QueryOkResponse($result);
    }

    private function handleSet(SetCommand $set): Response {
        $table = $this->getTable($set->getTable());
        $originalAddr = $set->getOriginalAddress();
        $replacementAddr = $set->getReplacementAddress();

        if ($table === NULL) {
            return new ErrorResponse(
                Response::TYPE_SET,
                ErrorResponse::REASON_UNKNOWN,
                "Unknown table identifier!");
        }

        try {
            $table->set(new Entry($originalAddr, $replacementAddr));
        } catch (\Exception $ex) {
            var_dump($ex);

            return new ErrorResponse(
                Response::TYPE_SET,
                ErrorResponse::REASON_IO,
                "Internal exception.");
        }

        return new OkResponse(Response::TYPE_SET);
    }

    private function getTable(int $id): ?Table {
        switch ($id) {
        case ICommand::TABLE_PREROUTING:
            return $this->preroutingTable;

        case ICommand::TABLE_POSTROUTING:
            return $this->postroutingTable;

        default:
            return NULL;
        }
    }

    public function run(): void {
        # The dirty flag indicates whether a table modification was not
        # synchronized with the UniNAT instances because the lock for the
        # affected file could not get acquired without blocking the execution
        # thread.

        $dirty = FALSE;

        while ($this->running) {
            $connections = $this->server->proceed(
                $dirty || $this->alwaysDirty);

            # Handling the received commands of each connection separately.

            foreach ($connections as $cnt) {
                $dirty = $this->handleInput($cnt) || $dirty;
            }

            # Attempting to write dirty table files

            $tableUpdated = FALSE;

            if ($this->preroutingTable->isDirty()) {
                try {
                    $this->preroutingTable->write(FALSE);
                    $tableUpdated = TRUE;
                } catch (LockException $ex) {
                    $dirty = TRUE;
                }
            }

            if ($this->postroutingTable->isDirty()) {
                try {
                    $this->postroutingTable->write(FALSE);
                    $tableUpdated = TRUE;
                } catch (LockException $ex) {
                    $dirty = TRUE;
                }
            }

            # If any table file has been touched, we need to notify all UniNAT
            # processes

            if ($tableUpdated) {
                exec("killall -SIGUSR1 UniNAT");
            }
        }
    }

    public function cleanup(): void {
        $this->server->cleanup();

        $this->preroutingTable->cleanup();
        $this->postroutingTable->cleanup();
    }

    public function isRunning(): bool {
        return $this->running;
    }

    public function isAlwaysDirty(): bool {
        return $this->alwaysDirty;
    }

    public function stop(): void {
        $this->running = FALSE;
    }

    public function setAlwaysDirty(bool $alwaysDirty): void {
        $this->alwaysDirty = $alwaysDirty;
    }
}

# Since this whole application follows the OOP pattern, we just invoke the
# App::main method to switch into an OOP context.

exit(App::main($argv));
