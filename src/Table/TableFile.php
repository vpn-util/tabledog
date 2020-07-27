<?php

namespace TableDog\Table;

use \TableDog\IOException;

/**
 * Access to a phyiscal table file.
 */
class TableFile {
    /**
     * The path to the phyiscal table file.
     */
    private $path;

    /**
     * File descriptor of the table file.
     * 
     * The file descriptor may be <code>null</code>, if there is no open file
     * descriptor. A file descriptor must exist, if the
     * <code>$lockCounter</code> is greater than zero.
     */
    private $fd;

    /**
     * Increments with each lock request, decrements with each unlock request.
     */
    private $lockCounter;

    /**
     * Creates a new instance.
     * 
     * @param   string  $path   The path to the physical file. It is required
     *                          that this path exists already.
     */
    public function __construct(string $path) {
        if (!\file_exists($path))
            throw new IOException("Table file does not exist!");

        $this->path = $path;
        $this->fd = NULL;
        $this->lockCounter = 0;
    }

    /**
     * Returns the time of the physical file's last modification as Unix
     * timestamp.
     * 
     * @return  int The time of the last modification
     */
    public function getModificationTime(): int {
        return \filemtime($this->path);
    }

    /**
     * Attempts to acquire the exclusive lock for the physical file.
     * 
     * @param   bool    $blocking   Either <code>TRUE</code>, if acquiring the
     *                              lock is allowed to block the current
     *                              execution thread, otherwise
     *                              <code>FALSE</code>.
     * 
     * @throws  LockException   If the file lock could not get acquired (e.g.
     *                          because <code>$blocking</code> was disabled,
     *                          but another process locked the file already).
     * 
     * @throws  IOException     If accessing the phyiscal file failed.
     * 
     * @return  resource    The file descriptor (mode: r+) to the physical file
     *                      that has been exclusively locked.
     */
    public function lock(bool $blocking = TRUE) {
        if ($this->lockCounter > 0) {
            $this->lockCounter++;
            return $this->fd;
        }

        if ($this->fd === NULL) {
            $this->fd = fopen($this->path, "r+");

            if ($this->fd === FALSE)
                throw new IOException("Failed opening the file!");
        }

        $op = LOCK_EX;

        if ($blocking)
            $op |= LOCK_NB;

        if (flock($this->fd, $op, $wouldBlock) === FALSE)
            throw new LockException();

        $this->lockCounter++;
        return $this->fd;
    }

    /**
     * Unlocks the physical file.
     * 
     * @param   bool    $force  If enabled, the file lock will always be
     *                          released, otherwise it will only be released,
     *                          if the lock counter hits zero.
     * 
     * @throws  LockException   If the file lock cannot be released.
     * 
     * @throws  Exception       If the unlock-method has been called more often
     *                          than the lock-method.
     */
    public function unlock(bool $force = FALSE): void {
        $this->lockCounter--;

        if ($this->lockCounter > 0)
            return;

        if ($this->lockCounter < 0)
            throw new \Exception("More unlocks than locks!");

        if (flock($this->fd, LOCK_UN) === FALSE)
            throw new LockException();

        fclose($this->fd);
        $this->fd = NULL;
    }

    /**
     * Returns whether this process has acquired an exclusive lock for the
     * physical file.
     * 
     * @return  bool    Whether this process owns the exclusive lock for the
     *                  physical file.
     */
    public function isLocked(): bool {
        return $this->lockCounter > 0;
    }

    /**
     * Returns the locked file descriptor for the physical file, if there is
     * any.
     * 
     * @return  resource    The locked file descriptor.
     * 
     * @throws  Exception   If there is no file descriptor.
     */
    public function getFileDescriptor() {
        if ($this->fd === NULL || !$this->isLocked())
            throw new \Exception("No locked file descriptor!");

        return $this->fd;
    }
}
