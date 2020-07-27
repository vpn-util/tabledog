<?php

namespace TableDog\Table;

use \TableDog\IOException;
use \TableDog\Util\IPRange;

/**
 * Represents a single table file.
 */
class Table {
    private static function expectResource($res): void {
        if (!\is_resource($res))
            throw new IOException("Not a resource!");
    }

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
     * A flag that indicates whether the read table has been validated since
     * the last read operation or not.
     */
    private $validated;

    public function __construct() {
        $this->entries = array();
        $this->dirty = FALSE;
        $this->validated = TRUE;
    }

    private function expectValidated(): void {
        if (!$this->validated)
            throw new \Exception("Table was not validated!");
    }

    public function read($fd): void {
        self::expectResource($fd);

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

            if ($originalAddr === NULL || $replacementAddr === NULL)
                throw new IOException(
                    "Cannot parse IPv4 address with CIDR suffix!");

            # Adding the entry

            array_push(
                $this->entries,
                new Entry($originalAddr, $replacementAddr));
        }

        # Resetting the internal flags

        $this->dirty = FALSE;
        $this->validated = FALSE;
    }

    public function write($fd): void {
        self::expectResource($fd);
        $this->expectValidated();

        # Just dumping the entries to the file descriptor

        foreach ($this->entries as $entry) {
            \fprintf(
                $fd,
                "%s %s\n",
                $entry->getOriginalAddress(),
                $entry->getReplacementAddress());
        }

        # Updating the dirty flag

        $this->dirty = FALSE;
    }

    public function validate(): bool {
        # Verifying that there is no entry whose original address collides with
        # the one of another other entry

        foreach ($this->entries as $entry) {
            $entryAddr = $entry->getOriginalAddress();

            foreach ($this->entries as $comparedEntry) {
                $comparedAddr = $comparedEntry->getOriginalAddress();

                if ($entryAddr->compare($comparedAddr) ===
                    IPRange::RANGE_INDEPENDENT)
                    continue;

                return FALSE;
            }
        }
        
        return ($this->validated = TRUE);
    }

    public function isValidated(): bool {
        return $this->validated;
    }

    public function isDirty(): bool {
        return $this->dirty;
    }

    public function query(IPRange $originalAddress): array {
        $this->expectValidated();
        $result = array();

        foreach ($this->entries as $entry) {
            $comparedAddr = $comparedEntry->getOriginalAddress();

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
        $this->expectValidated();

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

                if (count($affectedEntries) > 0)
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
                
                if (count($affectedEntries) > 0)
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
}
