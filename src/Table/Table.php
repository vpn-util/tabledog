<?php

namespace TableDog\Table;

use \TableDog\IOException;
use \TableDog\Util\IPRange;

/**
 * Represents a single table file.
 */
class Table {
    /**
     * The entries of the table.
     */
    private $entries;

    /**
     * A flag that indicates whether the $entries contains unsaved
     * modifications or not.
     */
    private $dirty;

    /**
     * The modification time of the physical file that has been used for
     * reading the table entries.
     */
    private $modificationTime;

    /**
     * The table file that is being used for accessing the physical file.
     */
    private $file;

    /**
     * Creates a new instance.
     * 
     * @param   string  $path   The path to the table's physical file. It is
     *                          required that this file exists.
     */
    public function __construct(string $path) {
        $this->entries = array();
        $this->dirty = FALSE;
        $this->modificationTime = 0;
        $this->file = new TableFile($path);
    }

    private function isOutdated(): bool {
        return $this->file->getModificationTime() > $this->modificationTime;
    }

    /**
     * Attempts to reload the table from the physical file.
     * 
     * @param bool  $blocking   If enabled, this operation may block because
     *                          the table file lock cannot get acquired.
     * 
     * @throws LockException            If the lock cannot get acquired.
     * 
     * @throws IOException              If either a file operation fails or if
     *                                  the file contains invalid data.
     * 
     * @throws Exception                If refreshing the table would discard
     *                                  pending changes.
     * 
     * @throws InvalidTableException    If the physical file contains an
     *                                  invalid table with colliding entries.
     */
    public function refresh(bool $blocking = TRUE): void {
        if ($this->dirty)
            throw new \Exception("Pending changes would be discarded!");

        $fd = $this->file->lock($blocking);

        # Removing anything from the internal array

        array_splice($this->entries, 0);

        # Reading the single table entries and adding them to the table file.

        while ($line = fgets($fd)) {
            $segments = \sscanf($line, " %u.%u.%u.%u/%u %u.%u.%u.%u/%u ");

            if ($segments === -1)
                break;

            # Rebuilding the two addresses from their segments and parsing them
            #
            # TODO: Improve this code. (Double parsing should not be required.)

            $originalAddr = IPRange::parseIPv4WithCIDR(sprintf(
                "%u.%u.%u.%u/%u",
                $segments[0],
                $segments[1],
                $segments[2],
                $segments[3],
                $segments[4]));

            $replacementAddr = IPRange::parseIPv4WithCIDR(sprintf(
                "%u.%u.%u.%u/%u",
                $segments[5],
                $segments[6],
                $segments[7],
                $segments[8],
                $segments[9]));

            if ($originalAddr === NULL || $replacementAddr === NULL) {
                $this->file->unlock();

                throw new IOException(
                    "Cannot parse IPv4 address with CIDR suffix!");
            }

            # Adding the entry

            array_push(
                $this->entries,
                new Entry($originalAddr, $replacementAddr));
        }

        $this->modificationTime = $this->file->getModificationTime();
        $this->file->unlock();

        # Verifying that there is no entry whose original address collides with
        # the one of another other entry

        foreach ($this->entries as $key => $entry) {
            $entryAddr = $entry->getOriginalAddress();

            foreach ($this->entries as $comparedKey => $comparedEntry) {
                # Skipping itself

                if ($key === $comparedKey)
                    continue;

                $comparedAddr = $comparedEntry->getOriginalAddress();

                if ($entryAddr->compare($comparedAddr) ===
                    IPRange::RANGE_INDEPENDENT)
                    continue;

                throw new InvalidTableException();
            }
        }

        # Resetting the internal flags

        $this->dirty = FALSE;
    }

    /**
     * Attempts to write the pending changes to the physical file.
     * 
     * @param   bool    $blocking   If enabled, the operation may block, if the
     *                              phyiscal file is locked by another process.
     * 
     * @throws  LockException   If locking the physical file failed.
     * 
     * @throws  IOException     If a file operation fails.
     * 
     * @throws  Exception       If the physical file should have been locked
     *                          due to a pending modification, but is not.
     */
    public function write(bool $blocking = TRUE): void {
        # We only lock the table file, if there are no pending modifications,
        # otherwise the modification should have locked the table file already.

        $fd = NULL;

        if (!$this->dirty)
            $fd = $this->file->lock($blocking);
        elseif ($this->dirty && $this->file->isLocked())
            $fd = $this->file->getFileDescriptor();
        else
            throw new \Exception("Pending modifications, but not locked!");

        \ftruncate($fd, 0);
        \fseek($fd, 0, SEEK_SET);

        # Just dumping the entries to the file descriptor

        foreach ($this->entries as $entry) {
            \fprintf(
                $fd,
                "%s %s\n",
                $entry->getOriginalAddress(),
                $entry->getReplacementAddress());
        }

        # Cleanup
        
        $this->file->unlock();
        $this->dirty = FALSE;
    }

    /**
     * Returns whether there are pending modifications or not.
     * 
     * @return  bool    <code>TRUE</code>, if there are pending modifications,
     *                  otherwise <code>FALSE</code>.
     */
    public function isDirty(): bool {
        return $this->dirty;
    }

    public function query(IPRange $originalAddress): array {
        if ($this->isOutdated())
            $this->refresh(FALSE);

        $result = array();

        foreach ($this->entries as $entry) {
            $comparedAddr = $entry->getOriginalAddress();

            if ($originalAddress->compare($comparedAddr) ===
                IPRange::RANGE_INDEPENDENT)
                continue;

            array_push($result, $entry);
        }

        return $result;
    }

    /**
     * Adds the specified <code>entry</code> to the table and modifies
     * colliding entries as needed.
     * 
     * @param   Entry   $entry  The entry that is added/adjusted. If the
     *                          <code>remove</code> flag is enabled, the entry
     *                          is not required to provide a valid replacement
     *                          address.
     * 
     * @param   bool    $remove If enabled, the specified <code>entry</code> is
     *                          going to be removed.
     */
    public function set(Entry $entry, bool $remove = FALSE): void {
        $this->file->lock(TRUE);

        # Retrieving all entries that we need to modify

        $affectedEntries = $this->query($entry->getOriginalAddress());

        # Adjusting the entries

        foreach ($affectedEntries as $affectedEntry) {
            # Adjusting the affected entry depending on the type of collision

            $collisionType = $entry->getOriginalAddress()->compare(
                $affectedEntry->getOriginalAddress());

            switch ($collisionType) {
            case IPRange::RANGE_IDENTICAL:
                # If the original addresses of both are identical IPv4 ranges,
                # the whole number of $affectedEntries is logically limited to
                # one (otherwise the table is invalid).

                if (count($affectedEntries) > 1)
                    throw new \Exception(
                        "RANGE_IDENTICAL collision, but more than one ".
                            "affected entry!");

                # NOTE: No "break" is intended because we just want to remove
                #       that affected entry as well (for the same reason as the
                #       IPRange::RANGE_INNER one).

            case IPRange::RANGE_INNER:
                # Just removing the affected entry (it becomes superseded by
                # the new entry)

                array_splice(
                    $this->entries,
                    array_search($affectedEntry, $this->entries),
                    1);
                break;

            case IPRange::RANGE_OUTER:
                # If the original address range of the $affectedEntry includes
                # the entry's original address range, the whole number of
                # $affectedEntries is logically limited to one entry only,
                # otherwise the table is invalid.
                #
                # Also, we need to split up the outer range into smaller ranges
                # to be able to insert the new entry without invalidating the
                # table file.
                
                if (count($affectedEntries) > 1)
                    throw new \Exception(
                        "RANGE_OUTER collision, but more than one affected ".
                            "entry!");

                # Removing the affected entry

                array_splice(
                    $this->entries,
                    array_search($affectedEntry, $this->entries),
                    1);

                # Subdividing the entry to an IPv4 range with a smaller subnet
                # mask.
                #
                # Also, we only add those entries back to the table that do not
                # collide with entry's original address.

                $subdividedAddrs =
                    $affectedEntry->getOriginalAddress()->subdivide(
                        $entry->getOriginalAddress()->getMask());

                foreach ($subdividedAddrs as $subdividedAddr) {
                    $subdividedCollision =
                        $entry->getOriginalAddress()->compare($subdividedAddr);

                    switch ($subdividedCollision) {
                    case IPRange::RANGE_IDENTICAL:
                        # That is the subdivded address that we want to remove
                        # because it collides with the specified $entry.
                        break;

                    case IPRange::RANGE_INDEPENDENT:
                        # Re-adding all non-colliding entries back to the table

                        array_push(
                            $this->entries,
                            new Entry(
                                $subdividedAddr,
                                $affectedEntry->getReplacementAddress()));
                        break;
                        
                    default:
                        throw new \Exception(
                            "Unexpected result of subdividition operation!");
                    }
                }

                break;

            case IPRange::RANGE_INDEPENDENT:
                throw new \Exception("Invalid collision!");

            default:
                throw new \Exception("Unknown collision!");
            }
        }

        # Finally, adding the entry

        if (!$remove)
            array_push($this->entries, $entry);

        $this->dirty = TRUE;
    }

    /**
     * Flushes the buffers, releases the file lock and closes open file
     * descriptors.
     */
    public function cleanup(): void {
        if ($this->dirty)
            $this->write();

        if ($this->file->isLocked())
            $this->file->unlock(TRUE);
    }
}
