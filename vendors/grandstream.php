<?php
/* Grandstream ATAs */
class Provision extends ProvisionClass {

	/* Grandstream-specific configuration
	 * DO NOT MODIFY OR CUSTOMIZE HERE!
	 * Apply customizations in vendors/grandstream.inc.php. */
	private $baseConfig = "vendors/grandstream.xml"; /* Filename of base Grandstream provisioning template to use */

	/* Firmware Upgrade Settings
	 * It is recommended that $initialUpgradeFirmware = true and $autoUpgradeFirmware = false.
	 * This is because new Grandstream firmware is often buggy, so unless you want to be a guinea pig,
	 * you may prefer to avoid unwanted surprises. */
	private bool $initialUpgradeFirmware = true; /* Upgrade firmware on first provision */
	private bool $autoUpgradeFirmware = false; /* Upgrade firmware on subsequent provisions */

	private bool $localNTP = true; /* Use DHCP to determine the NTP server, as opposed to using a hardcoded Internet DHCP server */
	private $ntpServer = ""; /* NTP server override */

	private $caCert = ""; /* Trusted CA Root Certificates - Profile 1 */

	/* TODO Implement this */
	/* GDMS management capability at https://www.gdms.cloud/
	 * You should most likely leave this enabled, unless you have a good reason for disabling it,
	 * e.g. ATA is deployed in a disconnected environment and you need to conserve bandwidth. */
	private bool $enableGDMS = true;

	/* Automatically configure ATAs for Basic Authentication as an additional security layer
	 * 0 = disabled
	 * 1 = only for ATAs without an existing password
	 * 2 = enabled (always). This rotates the password on each resync. */
	private $autoBasicAuth = 0;

	private $forceWeak = false; /* Force use of TLS 1.0 instead of TLS 1.2 */
	private $textConfig = false; /* Output text config instead of XML */
	private $gsLogfile = "/var/log/apache2/provision_grandstream.log"; /* Debug logfile containing actual provisioning files sent to devices */

	public function __construct() {
		if (file_exists('vendors/grandstream.inc.php')) {
			include_once('vendors/grandstream.inc.php'); /* include/require does not work at class top-level */
		}
		/* Do nothing, just return */
	}

	public function acceptRequest() : bool {
		$path = $this->uri;
		/* Expect cfgMAC.xml (7 + 12 = 19 characters) */
		if (strlen($path) < 19) {
			/* Ignore requests for files like cfght802.xml, etc. */
			return false;
		}
		return true;
	}

	public function mtls() : bool {
		if (!isset($_SERVER['SSL_CLIENT_S_DN_O'])) {
			provLog("Not an ATA: " . $this->agent);
			return false; /* If bypass from this IP is allowed, we might still proceed */
		}
		if ($this->macRequest !== $this->macAgent || strlen($this->mac) !== 12) {
			provErrDump("MAC address mismatch (" . $this->uri . ": " . $this->macRequest . " != " . $this->macAgent . ")");
			return false;
		}
		if (!isset($_SERVER['SSL_CLIENT_S_DN_CN']) || $_SERVER['SSL_CLIENT_S_DN_CN'] !== $this->mac
			&& !(isset($_SERVER['SSL_CLIENT_I_DN_CN']) && $_SERVER['SSL_CLIENT_I_DN_CN'] === "VPN" && $this->clientTLS = 'TLSv1')) {
			/* Older Grandstreams' DN_CN don't have the MAC, so fallback to VPN for those. All Cisco ATAs do.
			 * Here, we make the assumption that the old ATAs that don't have the MAC as the S_DN_CN are the same old ATAs that can only do TLS 1.0.
			 * If we see a TLS 1.2 request, don't allow this common identity. */
			provErrDump("Grandstream client identification mismatch (" . $this->mac . ")");
			return false;
		}
		if ($this->clientVerify !== "SUCCESS") {
			provErrDump("Grandstream MTLS failed (" . $this->mac . ")");
			return false;
		}
		$clientSubjectOrg = isset($_SERVER['SSL_CLIENT_S_DN_O']) ? $_SERVER['SSL_CLIENT_S_DN_O'] : ''; /* e.g. Grandstream Networks */
		if (strpos($clientSubjectOrg, 'Grandstream Networks') === false) {
			provErrDump("Grandstream client subject failed (" . $this->mac . ")");
			return false;
		}
		return true; /* All checks passed */
	}

	private static function setPValue(&$array, $pval, $value) {
		$array['gs_provision']['config']['P' . $pval] = $value;
	}

	private function setGSConfig(&$array, $jack, $pVal1, $pVal2, $value) {
		switch ($jack) {
			case 1:
				$array['gs_provision']['config']['P' . $pVal1] = $value;
				break;
			case 2:
				$array['gs_provision']['config']['P' . $pVal2] = $value;
				break;
			default:
				$array['gs_provision']['config']['P' . $pVal1] = $value;
				$array['gs_provision']['config']['P' . $pVal2] = $value;
		}
	}

	private function setGSConfigFull(&$array, $jackProfileID, $pVal1, $pVal2, $pVal3, $pVal4, $value) {
		if ($value === null) {
			provLog("Value for $pVal1 +, was null");
			return;
		}
		switch ($jackProfileID) {
			case 1:
				$array['gs_provision']['config']['P' . $pVal1] = $value;
				break;
			case 2:
				$array['gs_provision']['config']['P' . $pVal2] = $value;
				break;
			case 3:
				$array['gs_provision']['config']['P' . $pVal3] = $value;
				break;
			case 4:
				$array['gs_provision']['config']['P' . $pVal4] = $value;
				break;
			default:
				provLog("$this->setGSConfig called for profile #$jackProfileID?");
			case 0:
				$array['gs_provision']['config']['P' . $pVal1] = $value;
				$array['gs_provision']['config']['P' . $pVal2] = $value;
				$array['gs_provision']['config']['P' . $pVal3] = $value;
				$array['gs_provision']['config']['P' . $pVal4] = $value;
				break;
		}
	}

	private function categorizeDevice(array $deviceInfo) {
		/* Determine how many lines this device can support.
		 * If we have a device model on file, use that,
		 * otherwise fall back to the model in the user agent. */
		$agentArray = explode(' ', $this->agent);
		$model = (isset($deviceInfo['model']) && strlen($deviceInfo['model']) > 0) ? $deviceInfo['model'] : $agentArray[3];
		$v2 = (strstr($this->agent, "V2 V") ? true : false); /* Is it a V2 version of an HT-8xx ATA? */

		/* Exclude V2 from model checks */
		$model = str_replace('V2', '', $model);

		$capacityGrade = -1;
		$maxProvs = 100;
		$maxProfileIDs = 2;
		if (str_starts_with($model, "GXW")) {
			switch (substr($model, 3)) {
				case "4224":
					$capacityGrade = 3;
					$maxProvs = 24;
					$maxProfileIDs = 4;
					break;
				default:
					http_response_code(404);
					error_log("Model not currently supported: $model\n", 0);
					die();
			}
		} else if (substr($model, -1) === '1') {
			$capacityGrade = 0; /* HT 701, 801, etc. */
			$maxProvs = 1;
		} else if (substr($model, -1) === '2') {
			$capacityGrade = 0; /* HT 702, 802, etc. */
			$maxProvs = 2;
		} else if (substr($model, -1) === '4') {
			$capacityGrade = 1; /* 704, etc. */
			$maxProvs = 4;
		} else if (substr($model, -1) === '8') {
			$capacityGrade = 2; /* 818, etc. */
			$maxProvs = 8;
		}
		if (substr($model, -3) === "812") { /* 812 is ~ the 704 */
			$capacityGrade = 1;
			$maxProvs = 4;
		}
		if ($capacityGrade == -1) {
			provLog("Unknown capacity grade for " . $this->mac . ": " . $this->agent);
			$capacityGrade = 0; /* Sane default */
		}
		return array($capacityGrade, $maxProvs, $maxProfileIDs, $v2);
	}

	public function configurationOK() : bool {
		if (strlen($this->baseConfig) > 0 && !file_exists($this->baseConfig)) {
			provFail("Missing base configuration template " . $this->baseConfig);
			return false; /* Won't actually get here */
		}
		if (strlen($this->gsLogfile) > 0 && !is_writable($this->gsLogfile)) {
			$logFile = $this->gsLogfile;
			provFail("Log file not writable - run touch $logFile && chown www-data:www-data $logFile");
			return false;
		}
		return true;
	}

	public function nonProvision(array $deviceInfo) : bool {
		return false; /* All requests for Grandstream devices are provisioning requests */
	}

	private function emptyXMLConfig() : array {
		$array = array(
			'gs_provision' => array(
				'@attributes' => array(
					'version' => 1,
				),
				'config' => array(
					'@attributes' => array(
						'version' => 1,
					),
				),
			),
		);
		return $array;
	}

	public function preProvision(array $provConfig) {
		/* Start with an empty config, and only add critical items for bootstrap provisioning to HTTPS */
		$array = $this->emptyXMLConfig();
		$this->setPValue($array, 145, 0); /* Allow DHCP Option 66 to override server. 0 - No, 1 - Yes. */
		$this->setPValue($array, 192, $this->initialUpgradeFirmware ? "firmware.grandstream.com" : ""); /* Firmware Server Path (firmware.grandstream.com is right, fm.grandstream.com/gs is GAPS redirection) */
		$this->setPValue($array, 238, $this->initialUpgradeFirmware ? 0 : 2); /* Firmware upgrade frequency. 0 = Always Check, 2 = Never Check */
		$this->setPValue($array, 193, 1); /* Automatic Upgrade Every XXX minutes - we want to bootstrap immediately */
		$this->setPValue($array, 194, 3); /* Automatic Upgrade (0 = No, 3 = Every XXX min, 1 = Daily, 2 = Weekly */
		$this->setPValue($array, 22296, 1); /* Automatic Upgrade... newer HTs: 0 = No, 1 = Every XXX min, 2 = Daily, 3 = Weekly */
		$this->setPValue($array, 207, $provConfig['syslog_host']); /* SYSLOG Server */
		$this->setPValue($array, 8402, 0); /* Syslog Protocol (0 = UDP, 1 = SSL/TLS) */
		$this->setPValue($array, 208, 4); /* SYSLOG Level (0 = NONE, 1 = DEBUG, 2= INFO, 3 = WARNING, 4 = ERROR, 5 = EXTRA DEBUG) */
		$this->setPValue($array, 212, 2); /* Firmware Upgrade ( 0 = TFTP Upgrade, 1 = HTTP Upgrade, 2 = https) */
		$this->setPValue($array, 237, $provConfig['provision_host'] . ":" . getVendorPort("grandstream"));
		$this->setPValue($array, 30, strlen($this->ntpServer) > 0 ? $this->ntpServer : "us.pool.ntp.org"); /* NTP Server */
		if ($this->caCert !== "") {
			$this->setPValue($array, 2386, $this->caCert); /* Trusted CA Root Certificates - Profile 1 */
		}
		dumpAsXML($array, ""); /* array to XML, echo to client, also dump generated XML into a debug log */
	}

	public function provision(array $deviceInfo, array $lines, array $provConfig) {
		$array = null;
		if (strlen($this->baseConfig) > 0) {
			$array = array_from_xml_file($this->baseConfig); /* Load the basic XML template as a starting point */
		} else {
			$array = $this->emptyXMLConfig();
		}
		if (!$array) {
			provFail("Failed to load base XML file " . $this->baseConfig);
		} else if (!isset($array['gs_provision'])) {
			provFail("Grandstream XML template corrupted");
		}

		/* Add the MAC address before config. Supporting ATAs will reject MACs that aren't their own. */
		$array['gs_provision'] = insertBefore($array['gs_provision'], 'config', 'mac', strtolower($this->mac));

		/* Syslog */
		if (strlen($provConfig['syslog_host']) > 0) {
			$this->setPValue($array, 207, $provConfig['syslog_host']);
			$this->setPValue($array, 8402, $provConfig['syslog_tls'] ? 1 : 0); /* UDP=0, TLS=1 */
		}

		/* All Grandstreams support HTTP Basic Auth, so at least do it for them, add a rand username and password.
		 * This ensures that if the device is factory reset, the account won't re-provision without
		 * clearing this key in the database to allow a resync. */
		if (!$this->bypass && $this->autoBasicAuth > 0) {
			$randUser = bin2hex(random_bytes(10)); /* 20 chars */
			$randPass = bin2hex(random_bytes(10)); /* 20 chars */
			$randUserPass = $randUser . ':' . $randPass;
			$sql = "UPDATE $macTable SET http_credentials = ? WHERE mac = ?";
			if ($this->autoBasicAuth === 1) {
				/* If we don't want to auto-rotate, then we just apply Basic Auth if it was missing */
				$sql .= " AND http_pass IS NULL";
			}
			$stmt = $mysqli->prepare($sql);
			$stmt->bind_param('ss', $randUserPass, $this->mac);
			$stmt->execute();
			$this->setPValue($array, 1360, $randUser); /* HTTP username */
			$this->setPValue($array, 1361, $randPass); /* HTTP password */
		}

		/* Automatic Firmware Upgrade, if enabled */
		$this->setPValue($array, 192, $this->autoUpgradeFirmware ? "firmware.grandstream.com" : ""); /* Clear firmware upgrade path */
		$this->setPValue($array, 238, $this->autoUpgradeFirmware ? 0 : 2); /* Firmware upgrade frequency. 0 = Always Check, 2 = Never Check */

		/* NTP */
		$this->setPValue($array, 30, strlen($this->ntpServer) > 0 ? $this->ntpServer : "us.pool.ntp.org"); /* NTP Server */

		if ($this->caCert !== "") {
			$this->setPValue($array, 2386, $this->caCert); /* Trusted CA Root Certificates - Profile 1 */
		}

		/* TLS configuration
		 * 0 Enable Weak TLS Ciphers Suites
		 * 1 Disable Symmetric Encryption RC4/DES/3DES
		 * 2 Disable Symmetric Encryption SEED
		 * 3 Disable All Of The Above Weak Symmetric Encryption
		 * 4 Disable Symmetric Authentication MD5
		 * 5 Disable All Of The Above Weak Symmetric Authentication
		 * 6 Disable Protocol Version SSLv2/SSLv3
		 * 7 Disable All Of The Above Weak Protocol Version
		 * 8 Disable All Of The Above Weak TLS Ciphers Suites */
		if (!$this->forceWeak && ($this->clientTLS === "TLSv1.2" || $this->clientTLS === "TLSv1.3")) {
			/* Force TLS 1.2, since the ATA supports it */
			$this->setPValue($array, 22293, 12); /* Minimum TLS Version (99 = Unlimited, 10 = TLS 1.0, 11 = TLS 1.1, 12 = TLS 1.2 */
			$this->setPValue($array, 22294, 12);
			$this->setPValue($array, 8536, 8); /* Disable Weak TLS Cipher Suites (8 = Disable All Weak TLS Cipher Suites, 6 = Disable Protocol Version SSLv2/SSLv3, 0 = Enable Weak) */
		} else {
			$this->setPValue($array, 22293, 10);
			$this->setPValue($array, 22294, 10);
			$this->setPValue($array, 8536, 0);
		}

		$anyAutodial =  count($lines) > 0 && max(array_map('strlen', array_column($lines, 'autodial'))) > 0; /* Do any lines have a non-empty autodial configured? */
		if ($anyAutodial) {
			/* Confirmation Tone
			 * Get rid of the stupid "double beep" tone when going off-hook on lines configured for hotline/PLAR.
			 * We do this by setting the tone to 1 Hz, so it can't be heard. */
			$this->setPValue($array, 4004, "f1=1@-11,f2=1@-11,c=1/1-1/1-1/1;");
		}

		/* Admin Password */
		if (isset($deviceInfo['admin_pw']) && strlen($deviceInfo['admin_pw'])) {
			$pw = $deviceInfo['admin_pw'];
			/* Must contain 8-20 characters, at least one number, one uppercase and lowercase letter (for newer HT) */
			$complex = strlen($pw) >= 8 && strlen($pw) <= 20 && preg_match('~[0-9]+~', $pw) && preg_match('/[A-Z]/', $pw) && preg_match('/[a-z]/', $pw);
			if (!$complex) {
				/* If a complex password is required by the firmware, this setting will be silently ignored */
				provLog("Admin password for device " . $this->mac . " is not complex");
			}
			$this->setPValue($array, 2, $pw);
		}

		/* Disable Voice Prompt */
		$this->setPValue($array, 253, 1);

		/* Time Zone */
		if (isset($deviceInfo['tz'])) {
			/* At least one timezone was specified, use it */
			$tz = $deviceInfo['tz'];
			/* TODO: This mapping is incomplete */
			$tzMappings = array(
				'US/Eastern' => 'EST5EDT',
				'US/Central' => 'CST6CDT',
				'US/Mountain' => 'MST7MDT',
				'US/Pacific' => 'PST8PDT',
				/* TODO: This is incomplete! */
			);
			if (isset($tzMappings[$tz])) {
				$provtz = $tzMappings[$tz];
				$this->setPValue($array, 64, $provtz);
			} else {
				provLog("No Grandstream-specific timezone mapping defined for $tz");
			}
		}

		/* Categorize the device, based on the user agent
		 * Capacity grades are as follows:
		 * 0 = Single-line or double-line devices, e.g. HT801/HT802
		 * 1 = Quad-line devices, e.g. HT704, HT812
		 * 2 = Octal-line devices, e.g. HT818
		 * 3 = Denser devices, e.g. GXW4224 */
		list($capacityGrade, $maxProvs, $maxProfileIDs, $v2) = $this->categorizeDevice($deviceInfo);
		$oldATA = (!($this->clientTLS === 'TLSv1.2' || $this->clientTLS === 'TLSv1.3' || $capacityGrade === 3));

		/* GXW4224 polarity reversal fixes */
		if ($capacityGrade === 3) {
			$this->setPValue($array, 28834, 1);
			$this->setPValue($array, 28835, 1);
			$this->setPValue($array, 28836, 1);
			$this->setPValue($array, 28837, 1);
		}

		/* SLIC Setting */
		if ($capacityGrade === 3) {
			/* 0 = US SLIC setting, 3 = Finland, so don't use that. */
			$this->setPValue($array, 854, 0);
			$this->setPValue($array, 864, 0);
			$this->setPValue($array, 564, 0);
			$this->setPValue($array, 4335, 0);
		} else {
			/* SLIC Setting (0 = USA 1: Bellcore 600 ohms, 3 = USA 2: Bellcore 600 ohms + 2.16uF, 1 = Standard 900 ohms */
			$this->setPValue($array, 8402, 0);
		}

		/* Ring Timeout: 10-300 on older models, 0-300 (0 to disable) on newer ones,
		 * so progressively use 300 by default, and override with 0 if possible. */
		if ($oldATA) {
			$this->setGSConfigFull($array, 0, 185, 816, 510, 4384, '300');
		} else {
			$this->setGSConfigFull($array, 0, 185, 816, 510, 4384, '0');
		}

		/* Bug workaround for GXW */
		if ($capacityGrade === 3) {
			/* newer HTs use P22296 for "upgrade every XXX min", and P194 screws up P193... */
			$this->setPValue($array, 194, '');
		}

		/* Disable SIP NOTIFY Authentication (so ATA doesn't ask Asterisk for authentication for resync NOTIFY) */
		$this->setPValue($array, 4428, 1); /* Same for HT802 and GXW4224 (system-wide setting) */

		/* Finally, provision the lines themselves */
		$profileID = 0;
		$provCount = 0;
		$sipServers = array();
		foreach ($lines as $line) {
			if ($provCount > $maxProvs) {
				$total = count($lines);
				provLog("More lines provisioned for " . $this->mac . " than it supports ($total > $maxProvs)");
				break;
			}
			$jack = (int) $line['jack'];
			$incr = $jack - 1; /* 0-indexed for doing math with P-values */
			$sipServer = $line['server'] . ':' . $line['port'];
			if (!in_array($sipServer, $sipServers)) {
				/* New SIP server. Each unique SIP server (across all lines) requires a separate profile.
				 * Since there are a limited number of profiles supported on the higher-density devices,
				 * reuse the same profile across multiple lines when possible. */
				$sipServers[$profileID] = $sipServer;
				$profileID++; /* Only used for high-density ATAs (e.g. GXW), low-density ones have individual profiles per FXS port */
			}
			if ($profileID > $maxProfileIDs) {
				provLog("More than $maxProfileIDs servers need provisioning for $mac");
				$profileID = 2; /* Don't hand out an invalid config... but this won't be a correct config. */
			}
			/* Profile Settings (both low and high-capacity ATAs) */
			/* Get index of SIP server (adding 1 to make it 1-indexed).
			 * This allows higher-density FXS ATAs to alternate between servers on adjacent jacks without issue. */
			$correctProfileID = array_search($sipServer, $sipServers) + 1;
			$jackProfileID = ($capacityGrade === 0 ? $jack : $correctProfileID); /* On low-density ATAs, each jack has its own profile */

			/* SLIC Setting, cont. */
			if ($capacityGrade !== 3) {
				/* *SAME* P-Value but *DIFFERENT* values depending on device?
				 * What were you smoking Grandstream??? */
				$this->setGSConfig($array, $jackProfileID, 854, 864, '3');
			}

			/* SIP Settings */
			$this->setGSConfigFull($array, $jackProfileID, 47, 747, 547, 602, $sipServer); /* Primary SIP Server (FQDN/IP + port) */
			$this->setGSConfigFull($array, $jackProfileID, 48, 748, 548, 603, ''); /* Outbound Proxy: clear if set! */
			$this->setGSConfigFull($array, $jackProfileID, 271, 401, 501, 601, 1); /* Profile Active (0 = No, 1 = Yes) */
			$this->setGSConfigFull($array, $jackProfileID, 2346, 2446, 2546, 2646, 0); /* Authenticate incoming INVITE (0 = No, 1 = Yes) */
			$this->setGSConfigFull($array, $jackProfileID, 74, 774, 574, 4575, 1); /* Send Hook Flash Event */
			$this->setGSConfigFull($array, $jackProfileID, 130, 830, 530, 4648, $line['encryption'] ? 2 : 0); /* SIP Transport: use UDP (0) if not encrypted, TLS (1) if encrypted */
			$this->setGSConfigFull($array, $jackProfileID, 183, 443, 543, 643, $line['encryption'] ? 2 : 0); /* SRTP Mode (0 = Disabled, 1 = Enabled not forced, 2 = Forced) */

			/* Preferred Vocoder
			 * Limiting should reduce the size of the INVITE. 0 = PCMU */
			$this->setGSConfigFull($array, $jackProfileID, 57, 757, 557, 651, 0);
			$this->setGSConfigFull($array, $jackProfileID, 58, 758, 558, 652, 0);
			$this->setGSConfigFull($array, $jackProfileID, 59, 759, 559, 653, 0);
			$this->setGSConfigFull($array, $jackProfileID, 60, 760, 560, 654, 0);
			$this->setGSConfigFull($array, $jackProfileID, 61, 761, 561, 655, 0);
			$this->setGSConfigFull($array, $jackProfileID, 62, 762, 562, 656, 0);
			$this->setGSConfigFull($array, $jackProfileID, 46, 814, 563, 657, 0);
			$this->setGSConfigFull($array, $jackProfileID, 98, 815, 564, 658, 0);

			if ($capacityGrade === 0) {
				/* 1 and 2-line Grandstreams
				 * P-Values for profile 1 + 700 for profile 2 (each FXS port has a separate profile) */
				$this->setGSConfig($array, $jack, 35, 735, $line['username']); /* User ID */
				$this->setGSConfig($array, $jack, 36, 736, $line['username']); /* Auth ID */
				$this->setGSConfig($array, $jack, 34, 734, $line['password']); /* Auth Password */
				$this->setGSConfig($array, $jack, 3, 703, $line['tn']); /* Name */
				$this->setGSConfig($array, $jackProfileID, 71, 771, $line['autodial']); /* Off Hook Auto-Dial Number */
				$this->setGSConfig($array, $jackProfileID, 29, 729, (strlen($line['autodial']) > 0 ? 0 : 1)); /* Early Dial (eliminates need for digit-map, if not getting dial tone from switch) */
			} else {
				/* 4, 8, and higher port ATAs (multiple FXS ports share a profile)
				 * 4-port ATAs: FXO ports page. P-values for ports > 1 is P-value for 1 + (port - 1) */
				$lineProfileID = $jackProfileID - 1; /* Have to subtract 1 */
				$this->setPValue($array, 4150 + $incr, $lineProfileID); /* Profile ID (0 = 1, 1 = 2) */
				$this->setPValue($array, 4060 + $incr, $line['username']); /* User ID */
				$this->setPValue($array, 4090 + $incr, $line['username']); /* Auth ID */
				$this->setPValue($array, 4120 + $incr, $line['password']); /* Auth Password */
				$this->setPValue($array, 4180 + $incr, $line['tn']); /* Name */
				$this->setPValue($array, 4210 + $incr, $line['autodial']); /* Offhook Auto Dial Number */
				$this->setPValue($array, 4045 + ($jackProfileID - 1), (strlen($line['autodial']) > 0 ? 0 : 60)); /* Off Hook Auto Dial Delay: 0 = immediate */
				$this->setPValue($array, 4300 + $incr, 0); /* Hunting group (0 = None) */
				$this->setPValue($array, 4669 + $incr, ''); /* Request URI Routing */
				$this->setPValue($array, 4595 + $incr, 1); /* Enable Line (0 = No, 1 = Yes) */
				$this->setPValue($array, ($lineProfileID == 1 ? 24060 : 24260), $line['encryption']); /* SRTP Enable */
				$this->setPValue($array, ($lineProfileID == 1 ? 24001 : 24201), $line['encryption']); /* Enable SRTP */
			}

			/* This appears to be an HT-only setting (not in GXW config) */
			$this->setGSConfigFull($array, $jackProfileID, 4433, 4434, 4434, 4434, 1); /* Generate Continuous RFC2833 Events */
			$this->setGSConfigFull($array, $jackProfileID, 72, 772, 572, 692, 0); /* Make ## or other codes beginning with # work (without PLAR) */
			$this->setGSConfigFull($array, $jackProfileID, 28147, 28148, 28149, 28150, 1); /* Disable # as Redial Key */
			$provCount++;
		}

		/* Output the final config */
		if ($this->textConfig) {
			/* Dump the legacy text format, instead of the XML.
			 * Mainly useful for super old clients (e.g. GXW 4208)
			 * that only accept the legacy text format (not XML),
			 * and only TFTP and HTTP (no HTTPS).
			 * This can be used to run the text file through GAPSLITE and produce
			 * a binary for the ATA, e.g.:
			 *
			 * ./encode.sh 000b82000000 config cfg000b82000000
			 * openssl enc -e -aes-256-cbc -md md5 -k XXXXXXXXXXXXXXXXXXXX -in {$tmpfile} -out {$encrypted_tmpfile}
			 */
			header("Content-Type: text/plain");
			foreach($array['gs_provision']['config'] as $k => $v) {
				if ($k === "@attributes") {
					continue;
				}
				if (is_array($v)) {
					continue; /* Don't print "Array" */
				}
				echo $k . "=" . $v . "\n";
			}
		} else {
			dumpAsXML($array, $this->gsLogfile); /* array to XML, echo to client, also dump generated XML into a debug log */
		}
	}
}
