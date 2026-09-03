<?php
/* If polycom-custom.php exists, custom provisioning logic can be added here beyond the configurability provided by polycom.inc.php.
 * For example, below is some custom code to add a few BLFs/speed dial buttons to phones with sidecars:
$i = 1;
if ($supportsSidecars) {
	$this->polycomSet($array, "feature.presence.enabled", 1);
	$this->polycomSet($array, "feature.enhancedCallHandling", 1);
	$this->polycomSet($array, "voIpProt.SIP.serverFeatureControl", 1);
	$this->polycomSet($array, "voIpProt.SIP.serverFeatureControl.subscribe", 1);
	$this->polycomSet($array, "attendant.reg", 1);

	$this->setBLF($array, $i++, "101", "Alice", "normal");
	$this->setBLF($array, $i++, "102", "Bob", "normal");
}
*/