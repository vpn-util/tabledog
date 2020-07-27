<?php

namespace TableDog\Table;

use \TableDog\IOException;

class TableFile {
    # TODO: Requires better encapsulation of the Table class

    private $path;
    private $fileTime;
    private $table;

    public function __construct(string $path) {
        if (!\file_exists($path))
            throw new IOException("Table file does not exist!");

        $this->path = $path;
        $this->fileTime = 0;
        $this->table = new Table();
    }

    public function getTable(): Table {
        return $this->table;
    }

    public function isOutdated(): bool {
        return \filemtime($this->path) > $this->fileTime;
    }

    public function reload(bool $blocking = TRUE): void {
        # Openening the physical table file for reading and attempting to
        # acquire an exclusive lock for it.

        $fd = \fopen($this->path, "r");

        if ($fd === FALSE)
            throw new IOException("Cannot open table file!");

        $lockMode = LOCK_EX;

        if (!$blocking)
            $lockMode |= LOCK_NB;

        $wouldBlock = 0;

        if (\flock($fd, $lockMode, $wouldBlock) === FALSE) {
            \fclose($fd);

            if (!$blocking && $wouldBlock === 1)
                throw new LockException("Would block!");

            throw new IOException("Cannot acquire file lock!");
        }

        # Reading the table

        $table->read($fd);

        # Unlocking the table file and closing the file descriptor

        if (\flock($fd, LOCK_UN) === FALSE)
            throw new LockException("Cannot unlock file descriptor!");

        fclose($fd);
    }

    public function synchronize(bool $blocking = TRUE): void {
        # Openening the physical table file for reading and attempting to
        # acquire an exclusive lock for it.

        $fd = \fopen($this->path, "w");

        if ($fd === FALSE)
            throw new IOException("Cannot open table file!");

        $lockMode = LOCK_EX;

        if (!$blocking)
            $lockMode |= LOCK_NB;

        $wouldBlock = 0;

        if (\flock($fd, $lockMode, $wouldBlock) === FALSE) {
            \fclose($fd);

            if (!$blocking && $wouldBlock === 1)
                throw new LockException("Would block!");

            throw new IOException("Cannot acquire file lock!");
        }

        # Reading the table

        $table->write($fd);

        # Unlocking the table file and closing the file descriptor

        if (\flock($fd, LOCK_UN) === FALSE)
            throw new LockException("Cannot unlock file descriptor!");

        fclose($fd);
    }
}
