<?php

namespace TableDog\Util;

/**
 * Represents an IPv4 address, including it's CIDR suffix.
 */
class IPRange {
    # TODO: Rewrite/Sanitize this class.

    public const RANGE_INDEPENDENT  = 0x00;
    public const RANGE_INNER        = 0x01;
    public const RANGE_OUTER        = 0x02;
    public const RANGE_IDENTICAL    = 0x03;

    private static function getCIDRSuffix($mask) {
        $cidr = 0;

        # TODO: The following for loop may also convert invalid masks into
        #       invalid CIDR suffixes. Improvement required.

        for ($i = 0; $i < 32; $i++) {
            $cidr += (int) (($mask & (1 << $i)) > 0);
        }

        return $cidr;
    }

    private static function convertToMask($cidrSuffix) {
        return 0xFFFFFFFF & (0xFFFFFFFF << (32 - $cidrSuffix));
    }

    public static function parseIPv4WithCIDR(
        string $ipAddressWithCIDR): ?IPRange {
        $components = explode("/", $ipAddressWithCIDR);

        if (count($components) != 2)
            return NULL;

        # Validation of the components

        $ipAddr = $components[0];
        $cidrSuffix = $components[1];

        if (!\filter_var(
            $ipAddr,
            FILTER_VALIDATE_IP,
            [ "flags" => FILTER_FLAG_IPV4 ])) {
            return NULL;
        }

        if (!\is_numeric($cidrSuffix))
            return NULL;

        $cidrSuffix = intval($cidrSuffix);

        if ($cidrSuffix < 0 || $cidrSuffix > 32)
            return NULL;

        return new IPRange($components[0], $components[1]);
    }

    private $ip;
    private $mask;

    public function __construct(string $ipAddress, int $cidrSuffix) {
        $this->ip = ip2long($ipAddress);
        $this->mask = self::convertToMask($cidrSuffix);

        # Normalization of the IP address

        $this->ip &= $this->mask;
    }

    public function getIP(): int {
        return $this->ip;
    }

    public function getMask(): int {
        return $this->mask;
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

        if ($this->mask < $addr->mask &&
            $this->ip == ($addr->ip & $this->mask)) {
            return IPRange::RANGE_INNER;
        }

        if ($this->mask > $addr->mask &&
            ($this->ip & $addr->mask) == $addr->ip) {
            return IPRange::RANGE_OUTER;
        }

        return IPRange::RANGE_INDEPENDENT;
    }

    public function subdivide(int $targetMask): array {
        if ($targetMask < $this->mask)
            throw new \Error("Cannot subdivide to smaller mask!");

        if ($targetMask === $this->mask)
            return [ $this ];

        # Converting the current mask and the target mask to the CIDR suffix
        # representation

        $cidr = self::getCIDRSuffix($this->mask);
        $targetCidr = self::getCIDRSuffix($targetMask);

        # Building the result array

        $result = array();
        $ranges = 2 ** ($targetCidr - $cidr);

        for ($i = 0; $i < $ranges; $i++) {
            # Cloning is dirty but rewriting the constructor for the PoC needs
            # too much effort at the moment.
            #
            # TODO: Remove cloning

            $newRange = clone $this;
            $newRange->ip = $this->ip | ($i << (32 - $targetCidr));
            $newRange->mask = $targetMask;

            array_push($result, $newRange);
        }

        return $result;
    }

    public function __toString() {
        return sprintf(
            "%s/%d",
            long2ip($this->ip),
            self::getCIDRSuffix($this->mask));
    }
}
