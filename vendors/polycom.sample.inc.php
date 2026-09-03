<?php
/* Polycom-specific configuration */
$this->pushUsername = "polycom";
$this->pushPassword = "PushPass456";
$this->deleteTemporaryCertificates = false; /* Delete temporary certificate files sent from devices */
/* Polycoms are very picky about TLS certs, CAs like Let's Encrypt are not natively understood,
 * and need to be explicitly loaded into the phones, particularly pre-VVX phones.
 *
 * Only one CA cert is needed per CA (e.g. Let's Encrypt, internal CA)
 * Use the contents of the cert for the value. */
$this->caCert1 = ""; /* If a CA is needed for provisioning, assign it to CA 1. (This is the same as SSLCertificateFile for this virtualhost.) */
$this->caCert2 = "";
$this->sipCA = 2; /* Use CA 2 (1 or 2) */
$this->syslogCA = 2; /* Use CA 2 (1 or 2) */
$this->localNTP = true; /* Use DHCP to determine the NTP server, as opposed to using a hardcoded Internet DHCP server */

$this->digitMap = ""; /* Custom digit map */
$this->voicemailExten = "*98"; /* Voicemail extension */
$this->polycomLogfile = "/var/log/apache2/provision_polycom.log"; /* Debug logfile containing actual provisioning files sent to devices */

$this->polyCertDir = "/home/polycerts";

/* Some Polycoms do not have a factory cert signed by the Polycom CAs, they just have a self-signed one :(
 * In that case, you will get a Polycom MTLS hardfail error during provisioning.
 * Include such IP phones here to allow provisioning without a unique cert. */
$this->exemptedPolycoms = array();
?>