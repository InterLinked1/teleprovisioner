<?php
/* Grandstream-specific configuration */
$this->baseConfig = "vendors/grandstream.xml"; /* Filename of base Grandstream provisioning template to use */

/* Firmware Upgrade Settings
 * It is recommended that $initialUpgradeFirmware = true and $autoUpgradeFirmware = false.
 * This is because new Grandstream firmware is often buggy, so unless you want to be a guinea pig,
 * you may prefer to avoid unwanted surprises. */
$this->initialUpgradeFirmware = true; /* Upgrade firmware on first provision */
$this->autoUpgradeFirmware = false; /* Upgrade firmware on subsequent provisions */

$this->localNTP = true; /* Use DHCP to determine the NTP server, as opposed to using a hardcoded Internet DHCP server */
$this->ntpServer = ""; /* NTP server override */

$this->caCert = ""; /* Trusted CA Root Certificates - Profile 1 */

/* TODO Implement this */
/* GDMS management capability at https://www.gdms.cloud/
 * You should most likely leave this enabled, unless you have a good reason for disabling it,
 * e.g. ATA is deployed in a disconnected environment and you need to conserve bandwidth. */
$this->enableGDMS = true;

/* Automatically configure ATAs for Basic Authentication as an additional security layer
 * 0 = disabled
 * 1 = only for ATAs without an existing password
 * 2 = enabled (always). This rotates the password on each resync. */
$this->autoBasicAuth = 0;

$this->forceWeak = false; /* Force use of TLS 1.0 instead of TLS 1.2 */
$this->textConfig = false; /* Output text config instead of XML */
$this->gsLogfile = "/var/log/apache2/provision_grandstream.log"; /* Debug logfile containing actual provisioning files sent to devices */
?>