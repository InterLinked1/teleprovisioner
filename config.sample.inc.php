<?php
/* Configuration file for provisioning system.
 * You can change any of the settings here, although some of them already have reasonable defaults you can use. */

/* Output config errors to the webpage instead of the system log.
 * ENABLING THIS IS A SECURITY RISK! Disable once application is configured and working! */
$outputErrors = true;

/* Common provisioning config settings */
$provConfig = array(
	'provision_host' => "provision.example.com",
	'syslog_host' => 'syslog.example.com',
	'syslog_tls' => false, /* Note that not all devices support TLS for syslog very well, e.g. Polycom */
);

/* Database configuration */
$dbHost = "db.example.com";
$dbUser = 'provisioner';
$dbPass = 'P@ssw0rd!';
$dbName = 'provisioning';
$macTable = "mac";
$provisionTable = "provision";
$ourIP = "10.1.1.2"; /* Our IP address, from the perspective of the DB server. Only used for the SQL GRANT PRIVILEGES command suggestion. */

/* A separate port is used for each ATA/IP phone vendor,
 * since older ATAs are notorious for not supporting
 * SNI, modern TLS protocols, etc. */
$vendorPortMapping = array(
	/* Bootstrap provisioning for all vendors occurs on HTTP port 80.
	 * The bootstrap config directs them to the appropriate vendor-specific port. */
	2443 => 'grandstream', /* cfg$MAC.xml */
	2445 => 'polycom', /* $MAC-user.cfg */
);

/* IP addresses that can bypass MTLS checks, for testing/debugging provisioning.
 * At most, it makes sense to add the provisioning admin's desktop here, and that's probably about it. */
$bypassIPs = array(
	/* '127.0.0.1', */
);

/* TLS certificates */
$sslCert = "/etc/ssl/certs/provision.crt";
$sslKey = "/etc/ssl/private/provision.key";

/* If non-empty, log file to log each provisioning request in full. */
$requestDebugLog = "/var/log/apache2/provision_debug.log";

/* Weather application, used for the microbrowser applet on supporting Polycom phones */
$weatherCacheDir = "/tmp"; /* Directory in which cached API data is stored */
$weatherCacheTime = 3600; /* Duration for which to cache API responses to avoid unnecessary requests. You can increase this too to conserve bandwidth if you don't mind forecasts that are possibly slightly stale. */
$openWeatherMapAPIKey = ""; /* See https://openweathermap.org/appid to obtain an API key - you'll need one to make this work */
?>
