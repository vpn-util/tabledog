<?php

namespace TableDog\Table;

/**
 * Represents an IPv4 address, including it's CIDR suffix.
 */
class IPRange {
    public const RANGE_INDEPENDENT  = 0x00;
    public const RANGE_INNER        = 0x01;
    public const RANGE_OUTER        = 0x02;
    public const RANGE_IDENTICAL    = 0x03;

    private $ip;
    private $mask;

    public function __construct(string $ipAddress, int $cidrSuffix) {
        $this->ip = ip2long($ipAddress);
        $this->mask = 0xFFFFFFFF & (0xFFFFFFFF << $cidrSuffix);

        # Normalization of the IP address

        $this->ip &= $this->mask;
    }

    /**
     * Returns the relation between this instance and the specified
     * <code>$addr</code>.
     * 
     * @param   IPRange $addr   The other instance that is compared to this
     *                          instance.
     * 
     * @return  int Either <code>IPRange::RANGE_INDEPENDENT</code>, if both
     *              instances do not overlap;
     *              <code>IPRange::RANGE_INNER</code>, if the specified
     *              <code>$addr</code> is a part of the current instance;
     *              <code>IPRange::RANGE_OUTER</code>, if the current instance
     *              is a part of specified <code>$addr</code>;
     *              <code>IPRange::RANGE_IDENTICAL</code>, if both ranges are
     *              the same.
     */
    public function compare(IPRange $addr): int {
        if ($this->mask == $addr->mask && $this->ip == $addr->ip) {
            return IPRange::RANGE_IDENTICAL;
        }

        if ($this->mask > $addr->mask &&
            $this->ip == ($addr->ip & $this->mask)) {
            return IPRange::RANGE_INNER;
        }

        if ($this->mask < $addr->mask &&
            ($this->ip & $addr->mask) == $addr->ip) {
            return IPRange::RANGE_OUTER;
        }

        return IPRange::RANGE_INDEPENDENT;
    }
}
