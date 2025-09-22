<?php
if (!file_exists('config.inc.php')) {
	die("No configuration file (config.inc.php) found!");
}
require_once('config.inc.php');
require_once('helpers.php');
require_once('xml.php');
if (!file_exists($sslCert)) {
	$provisionHost = $provConfig['provision_host'];
	configFail("Couldn't find $sslCert<br>Run: <code>openssl req -x509 -newkey rsa:1024 -nodes -keyout /etc/ssl/private/provision.pem -out /etc/ssl/certs/provision.crt -sha1 -days 3650 -subj '/CN=$provisionHost'</code>");
}
if (strlen($requestDebugLog) > 0 && !file_exists($requestDebugLog)) {
	configFail("File $requestDebugLog does not exist<br>Run: <code>touch $requestDebugLog && chown www-data:www-data $requestDebugLog && chmod +x /var/log/apache2; sed -i 's/create 640 root adm/create 640 www-data adm/' /etc/logrotate.d/apache2</code>");
}

$mysqli = null;
try {
	$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
} catch (Exception $e) {
	$sqlCreate = "CREATE DATABASE $dbName;
USE $dbName;
CREATE TABLE `$macTable` (
  `mac` varchar(12) NOT NULL,
  `serial` varchar(48) DEFAULT NULL,
  `admin_pw` varchar(32) DEFAULT NULL,
  `http_credentials` varchar(64) DEFAULT NULL,
  `provsecret` varchar(64) DEFAULT NULL,
  `mfer` varchar(24) DEFAULT NULL,
  `model` varchar(24) DEFAULT NULL,
  `location` varchar(64) DEFAULT NULL,
  `tz` varchar(48) DEFAULT NULL,
  `zip` varchar(5) DEFAULT NULL,
  `ip` varchar(52) DEFAULT NULL,
  `added` datetime NOT NULL DEFAULT current_timestamp(),
  `last_resync` datetime DEFAULT NULL,
  PRIMARY KEY (`mac`),
  UNIQUE KEY `serial` (`serial`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `$provisionTable` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tn` bigint(20) NOT NULL,
  `mac` varchar(12) NOT NULL,
  `jack` int(11) DEFAULT NULL,
  `server` varchar(64) NOT NULL,
  `port` int(11) NOT NULL,
  `username` varchar(48) NOT NULL,
  `password` varchar(64) NOT NULL,
  `autodial` varchar(11) DEFAULT NULL,
  `encryption` tinyint(4) NOT NULL,
  `active` int(11) NOT NULL DEFAULT 1,
  `shared` tinyint(1) NOT NULL DEFAULT 0,
  `added` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `mac` (`mac`),
  CONSTRAINT `provision_ibfk_1` FOREIGN KEY (`mac`) REFERENCES `$macTable` (`mac`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
GRANT ALL PRIVILEGES on $dbName.* TO $dbUser@'$ourIP' IDENTIFIED BY '$dbPass';
FLUSH PRIVILEGES";
	configFail("Failed to connect to database $dbUser@$dbHost: " . $e->getMessage() . "<br>In the database, run: <pre>" . $sqlCreate . "</pre>");
}
$charset = 'utf8mb4';
$mysqli->set_charset($charset);

function configFail($msg) {
	global $outputErrors;
	http_response_code(500);
	if ($outputErrors) {
		die($msg); /* Output to webpage for easier debugging while still configuring */
	} else {
		error_log($msg, 0);
		die();
	}
}

function provLog($msg) {
	error_log($msg, 0); /* Default: just log to the web server error log */
}
function provFail($msg, $httpResponseCode = 404) {
	provLog($msg);
	http_response_code($httpResponseCode);
	die();
}
function provErrDump($msg) {
	/* Instead of dumping $_SERVER in its entirety, just dump the fields we care about */
	$sDump = array();
	$sDump['HTTPS'] = $_SERVER['HTTPS'];
	$sDump['SSL_TLS_SNI'] = $_SERVER['SSL_TLS_SNI'];
	$sDump['HTTP_HOST'] = $_SERVER['HTTP_HOST'];
	$sDump['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'];
	$sDump['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'];
	$sDump['REQUEST_SCHEME'] = $_SERVER['REQUEST_SCHEME'];
	$sDump['SERVER_PROTOCOL'] = $_SERVER['SERVER_PROTOCOL'];
	$sDump['REQUEST_URI'] = $_SERVER['REQUEST_URI'];
	foreach ($_SERVER as $k => $v) {
		if (str_starts_with($k, "PHP_") || (str_starts_with($k, "SSL_") && !str_starts_with($k, "SSL_CLIENT_CERT"))) {
			$sDump[$k] = $v;
		}
	}
	provLog($msg . ": " . print_r($sDump, true));
}

function getVendorPort(String $vendor) {
	global $vendorPortMapping;
	foreach ($vendorPortMapping as $port => $v) {
		if ($vendor === $v) {
			return $port;
		}
	}
	return null;
}

/* There are three different objects here.
 * ProvisionInterface is the outer interface, which defines functions that must be implemented for each provisioning vendor.
 * ProvisionClass is another interface, just for variables, since interfaces can't have properties.
 * Then, the vendor specific file which gets included via require_once includes the implementation for the appropriate vendor. */
interface ProvisionInterface {
	public function acceptRequest() : bool; /* Whether to accept request */
	public function mtls() : bool; /* MTLS handshake checks */
	public function configurationOK() : bool; /* If configuration for this implementation is okay */
	public function nonProvision(array $deviceInfo) : bool; /* Request handled as non-provisioning request */
	public function preProvision(array $provConfig); /* Bootstrap provisioning on port 80 */
	public function provision(array $deviceInfo, array $lines, array $provConfig); /* Actually provision the device */
};

abstract class ProvisionClass implements ProvisionInterface { /* Interfaces can't have properties so use a class instead */
	/* HTTP request properties */
	public $scheme; /* Scheme, e.g. https */
	public $uri; /* Request URI */
	public $agent; /* User agent of the request made by the device */
	public $clientTLS; /* TLS protocol used */
	public $clientVerify; /* MTLS result */

	/* Device information */
	public $mac; /* MAC address of the device */
	public $macAgent; /* MAC address from the request URI */
	public $macRequest; /* MAC address from the request's user agent */
};

function extractMACAddressFromURI(String $uri) {
	$filename = pathinfo($uri, PATHINFO_FILENAME); /* First, extract the filename portion. */
	if (str_contains($filename, "-")) {
		/* e.g. /112233445566-mb.cfg for microbrowser config. Chop off the -mb part. */
		$filename = explode('-', $filename)[0];
	}
	return preg_replace('/[^0-9A-Fa-f]/', '', substr($filename, -12));
}

$preProvisionFiles = array(
	'/cfg.xml' => 'grandstream',
	'/000000000000.cfg' => 'polycom',
	'/polycom.cfg' => 'polycom',
);

$port = (int) $_SERVER['SERVER_PORT'];
$vendor = null;
if ($port === 80) {
	if (!isset($preProvisionFiles[$_SERVER['REQUEST_URI']])) {
		provFail("No such bootstrap file: " . $_SERVER['REQUEST_URI']);
	}
	$vendor = $preProvisionFiles[$_SERVER['REQUEST_URI']];
} else {
	if (!in_array($port, array_keys($vendorPortMapping))) {
		provFail("No provisioning service on port $port");
	}
	$vendor = $vendorPortMapping[$port];
}
$vendorFile = "vendors/" . $vendor . '.php';
if (!file_exists($vendorFile)) {
	provFail("No provisioning script found for vendor '$vendor'");
}
$vendorConfigFile = "vendors/" . $vendor . '.inc.php';
if (!file_exists($vendorConfigFile)) {
	/* Not an error, it just means no customizations have been made, which is probably not what is desired */
	provLog("WARNING: No provisioning config found for vendor '$vendor'");
}
require_once($vendorFile); /* Include the implementation of the provisioning functions for this vendor. */
$prov = new Provision();
if (!$prov->configurationOK()) {
	die();
}
$prov->scheme = $_SERVER['REQUEST_SCHEME']; /* e.g. https */
$prov->port = $port;
$prov->uri = $_SERVER['REQUEST_URI']; /* e.g. /cfg000a123a45aa.xml */
$prov->agent = $_SERVER['HTTP_USER_AGENT'];
$prov->macAgent = substr(preg_replace('/[^0-9A-Fa-f]/', '', $prov->agent), -12); /* MAC from user agent */
$prov->macRequest = extractMACAddressFromURI($prov->uri); /* MAC in the request URI */
$prov->mac = strtoupper($prov->macRequest); /* Normalize the MAC address to all caps. Prefer the one in the request URI so bypass works. */
$prov->clientTLS = isset($_SERVER['SSL_PROTOCOL']) ? $_SERVER['SSL_PROTOCOL'] : ''; /* e.g. TLSv1.2, TLSv1 */
$prov->clientVerify = isset($_SERVER['SSL_CLIENT_VERIFY']) ? $_SERVER['SSL_CLIENT_VERIFY'] : ''; /* e.g. GENEROUS, SUCCESS */
$clientIP = $_SERVER['REMOTE_ADDR'];
$prov->bypass = false;
if ($prov->port === 80) {
	/* Pre-provisioning (bootstrap) process */
	$prov->preProvision($provConfig);
	die();
}
if (!$prov->acceptRequest()) {
	provFail("Ignoring request for " . $prov->uri);
}
if (!$prov->mtls()) {
	/* MTLS failed. Check if we're authorized to bypass, otherwise, deny provisioning request */
	if (!in_array($clientIP, $bypassIPs)) {
		provFail("MTLS failed for " . $prov->mac);
	}
	provLog("Bypassing MTLS (debug request from " . $clientIP . ")");
	$prov->bypass = true; /* This a bypass request for debugging, not a real provisioning request from the device */
}
/* MAC address is correct by this point (in case it was fixed inside mtls()) */
if (strlen($prov->mac) !== 12) {
	provFail("LOGIC BUG: Invalid MAC address: " . $prov->mac, 500);
}

/* Okay, now we can start talking to the database. */
$deviceInfo = null;
$allowedIPs = array();
$basicAuth = "";

/* A device may not have any lines provisioned, and we still provision those with a base config */
$sql = "
SELECT mac, ip, tz, zip, admin_pw, http_credentials, model
FROM $macTable
WHERE $macTable.mac = ?
";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('s', $prov->mac);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
	$deviceInfo = $row;
	$allowedIPs[] = $row['ip'];
	if (isset($row['http_credentials'])) {
		$basicAuth = $row['http_credentials']; /* Only one per device, so can replace if it's repeated */
	}
}
if ($deviceInfo === null) {
	provFail("No configuration for unknown device " . $prov->mac);
}

$rows = array();
$sql = "
SELECT tn, mac.mac, jack, server, port, username, password, autodial, shared, encryption
FROM $macTable
INNER JOIN $provisionTable ON $macTable.mac = $provisionTable.mac
WHERE $macTable.mac = ?
AND $provisionTable.active = 1
ORDER BY jack ASC
";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('s', $prov->mac);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
	$rows[] = $row;
}
if (count($rows) < 1) {
	provLog("No lines provisioned for " . $prov->mac); /* Not fatal */
}
/* Order the lines to provision.
 *
 * "Jack" here refers to either the analog port number or the line appearance on the IP phone,
 * not really the most accurate term, and analog-centric, but other terms like "line" or "appearance" are more ambiguous.
 *
 * Ordering will be explicit jacks in ascending order, then those with an undefined (NULL) jack number. */
$lines = $rows;
foreach ($lines as &$line) {
	if (!(isset($line['jack']) && $line['jack'] !== null && strlen($line['jack']) > 0)) {
		/* No jack number defined yet.
		 * Assign it the lowest available one thus far. */
		$jackNum = 1;
		$jackNums = array_column($lines, 'jack');
		while (in_array($jackNum, $jackNums)) {
			$jackNum++;
		}
		/* Modify the jack number in place */
		$line['jack'] = $jackNum;
	}
}
if (count($lines) > 0) {
	/* Analyze the jack numbers. If there are gaps, some devices (e.g. Polycom IP phones) will not register the ones after the gap.
	 * It's possible there are gaps in the explicitly assigned jack numbers, and that is fine if they are filled in
	 * by the implicitly assigned ones in the previous step. If there are still gaps, that could be an issue. */
	$assignedJackNumbers = array_filter(array_column($lines, 'jack'));
	if (count($assignedJackNumbers) !== count(array_unique($assignedJackNumbers))) {
		provLog("Duplicate jack numbers assigned for " . $prov->mac);
	}
	if (min($assignedJackNumbers) !== 1) {
		provLog("No line assigned for jack 1 for " . $prov->mac);
	}
	/* If there are no warnings so far, we know all the jack numbers are unique and we start at 1.
	 * Thus, the max should be the same as the number of assignments. */
	if (max($assignedJackNumbers) !== count($assignedJackNumbers)) {
		provLog("Gaps detected in jack numbering for " . $prov->mac);
	}
}
/* Enforce IP restrictions */
if (!in_array('', $allowedIPs)) {
	/* All lines for this device are IP-restricted in some way, so check if we meet one */
	if (!isInIPRanges($clientIP, $allowedIPs) && !in_array($clientIP, $bypassIPs)) {
		$allowedIPRanges = print_r($allowedIPs, true);
		provFail("IP restriction test failed for " . $prov->mac . " at $clientIP (not in $allowedIPRanges)");
	}
}
/* Handle non-provisioning requests here */
if ($prov->nonProvision($deviceInfo)) {
	die();
}
/* If HTTP Basic Authentication required, enforce it */
if (!$prov->bypass && strlen($basicAuth) > 0) {
	$user = isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : '';
	$pass = isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';
	if ((!isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) || !in_array($_SERVER['PHP_AUTH_USER'] . ':' . $_SERVER['PHP_AUTH_PW'], $allowedBasicAuth))) {
		header("WWW-Authenticate: Basic realm=\"Provisioning\"");
		header("HTTP/1.0 401 Unauthorized");
		provLog("HTTP Basic Auth test failed for $mac at $clientIP, using $user/$pass (accepting ($basicAuth)");
		die(); /* Don't use provFail, since we've already sent HTTP response code */
	}
}
/* At this point, if a device has gotten this far, it is considered a successful provisioning request. */
if (!$prov->bypass && strlen($requestDebugLog) > 0) {
	error_log(print_r($_SERVER, true), 3, $requestDebugLog); /* Dump $_SERVER */
}
if (!$prov->bypass) {
	/* Keep track of the last successful resync time per device */
	$sql = "UPDATE $macTable SET last_resync = NOW() WHERE mac = ?";
	$stmt = $mysqli->prepare($sql);
	$stmt->bind_param('s', $prov->mac);
	$stmt->execute();
	$stmt->close();
}
/* Now, all the provisioning entries have a specific jack number assigned,
 * and we can proceed to provision the device. */
$prov->provision($deviceInfo, $lines, $provConfig);
?>