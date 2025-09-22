<?php
/* https://stackoverflow.com/q/64814006 */
function isInIPRanges(string $ip, array $ipRanges): bool {
    foreach ($ipRanges as $ipRange) {
        if (ip_in_range($ip, $ipRange)) {
            return true;
        }
    }
    return false;
}

/* https://gist.github.com/tott/7684443 */
/**
 * Check if a given ip is in a network
 * @param  string $ip    IP to check in IPV4 format eg. 127.0.0.1
 * @param  string $range IP/CIDR netmask eg. 127.0.0.0/24, also 127.0.0.1 is accepted and /32 assumed
 * @return boolean true if the ip is in this range / false if not.
 */
function ip_in_range($ip, $range) {
	if (strpos( $range, '/') == false) {
		$range .= '/32';
	}
	/* $range is in IP/CIDR format eg 127.0.0.1/24 */
	list($range, $netmask) = explode('/', $range, 2);
	$range_decimal = ip2long($range);
	$ip_decimal = ip2long($ip);
	$wildcard_decimal = pow(2, (32 - $netmask)) - 1;
	$netmask_decimal = ~ $wildcard_decimal;
	return (($ip_decimal & $netmask_decimal) == ($range_decimal & $netmask_decimal));
}
?>