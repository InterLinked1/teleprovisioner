<?php
/* Polycom IP phones */

/*
 * Certificate validation for phones with expired certificates also requires the polycerts directory.
 * For security, it is recommended that this be moved OUTSIDE the web root, since this directory
 * will contain copies of your phone's certificates in a temporary subdirectory (unless $deleteTemporaryCertificates = true).
 * Update $polyCertDir with the location of this directory (accessible by the www-data user)
 *
 * The boilerplate jpg images are not distributed here by default, but you may include them in the root directory.
 * Otherwise, a 404 will be returned for all image requests.
 *
 * NOTE: Apache MUST currently be compiled from source to support MTLS with Polycom SoundPoint phones,
 * as the current releases/packages do not incorporate a needed bugfix for this.
 * The fix is merged into httpd/trunk, but it will probably be some time before that's reflected in packages.
 */

class Provision extends ProvisionClass {

	/* Polycom-specific configuration
	 * DO NOT MODIFY OR CUSTOMIZE HERE!
	 * Apply customizations in vendors/polycom.inc.php. */
	private $pushUsername = "polycom";
	private $pushPassword = "PushPass456";
	private bool $deleteTemporaryCertificates = false; /* Delete temporary certificate files sent from devices */
	/* Polycoms are very picky about TLS certs, CAs like Let's Encrypt are not natively understood,
	 * and need to be explicitly loaded into the phones, particularly pre-VVX phones.
	 *
	 * Only one CA cert is needed per CA (e.g. Let's Encrypt, internal CA)
	 * Use the contents of the cert for the value. */
	private $caCert1 = ""; /* If a CA is needed for provisioning, assign it to CA 1. (This is the same as SSLCertificateFile for this virtualhost.) */
	private $caCert2 = "";
	private $sipCA = 2; /* Use CA 2 (1 or 2) */
	private $syslogCA = 2; /* Use CA 2 (1 or 2) */
	private bool $localNTP = true; /* Use DHCP to determine the NTP server, as opposed to using a hardcoded Internet DHCP server */

	private $digitMap = ""; /* Custom digit map */
	private $voicemailExten = "*98"; /* Voicemail extension */
	private $polycomLogfile = "/var/log/apache2/provision_polycom.log"; /* Debug logfile containing actual provisioning files sent to devices */

	private $polyCertDir = "/home/polycerts";

	/* Some Polycoms do not have a factory cert signed by the Polycom CAs, they just have a self-signed one :(
	 * In that case, you will get a Polycom MTLS hardfail error during provisioning.
	 * Include such ATAs here to allow provisioning without a unique cert. */
	private $exemptedPolycoms = array();

	/* Do not modify */
	private bool $microbrowser;

	public function __construct() {
		if (file_exists('vendors/polycom.inc.php')) {
			include_once('vendors/polycom.inc.php'); /* include/require does not work at class top-level */
		}
		$baseURI = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
		$this->microbrowser = (substr($baseURI, -7) === "-mb.cfg");
	}

	/*
	 * We expect a sequence of requests something like this (once bootstrapped):
	 * "GET /0004a11a1a1a.cfg HTTP/1.1" 404 2735 "-" "FileTransport PolycomSoundPointIP-SPIP_550-UA/4.0.15.1047 (SN:0004a11a1a1a) Type/Application"
	 * "GET /000000000000.cfg HTTP/1.1" 200 9267 "-" "FileTransport PolycomSoundPointIP-SPIP_550-UA/4.0.15.1047 (SN:0004a11a1a1a) Type/Application"
	 * "GET /0004a11a1a1a-user.cfg HTTP/1.1" 200 5465 "-" "FileTransport PolycomSoundPointIP-SPIP_550-UA/4.0.15.1047 (SN:0004a11a1a1a) Type/Application"
	 * "GET /0004a11a1a1a-phone.cfg HTTP/1.1" 404 184 "-" "FileTransport PolycomSoundPointIP-SPIP_550-UA/4.0.15.1047 (SN:0004a11a1a1a) Type/Application"
	 * "GET /0004a11a1a1a-web.cfg HTTP/1.1" 404 2735 "-" "FileTransport PolycomSoundPointIP-SPIP_550-UA/4.0.15.1047 (SN:0004a11a1a1a) Type/Application"
	 * "GET /000000000000-license.cfg HTTP/1.1" 404 2735 "-" "FileTransport PolycomSoundPointIP-SPIP_550-UA/4.0.15.1047 (SN:0004a11a1a1a) Type/Application"
	 * "PUT /0004a11a1a1a-app.log HTTP/1.1" 404 8089 "-" "FileTransport PolycomSoundPointIP-SPIP_550-UA/4.0.15.1047 (SN:0004a11a1a1a) Type/Application"
	 * "GET /0001A11A1A1A-mb.cfg HTTP/1.1" 200 1878 "-" "Microbrowser/1.1 PolycomSoundPointIP-SPIP_550-UA/4.0.15.1047 Type/Application"
	 */

	public function acceptRequest() : bool {
		$path = $this->uri;
		$ext = pathinfo($path, PATHINFO_EXTENSION);
		/* Ignore requests from phone to upload any data */
		if ($_SERVER['REQUEST_METHOD'] === "PUT") {
			return false;
		}
		/* Any images are static files in the provisioning directory, we won't be serving any */
		if ($ext === "jpg") {
			return false;
		}
		/* We serve the firmware up on port 80, no need to do it AFTER a bootstrap... */
		if (substr($path, -7) === ".sip.ld") {
			return false;
		}
		/* No licensing available */
		if (substr($path, -11) === "license.cfg") {
			return false;
		}
		/* No language info available */
		if ($path === "/languages/Website_dictionary_language_en-us.xml") {
			return false;
		}
		/* Don't care about these generic configs either */
		if (substr($path, -10) === "-phone.cfg" || substr($path, -8) === "-web.cfg") {
			return false;
		}
		if ($path === "/000000000000.cfg") { /* 000000000000.cfg */
			/* Give out the file that tells it what provisioning files to use.
			 * We do this here instead of in provision() as this template is
			 * still generic, and if we get to provision(), we've already logged
			 * in the database that we resynced successfully, when we really haven't yet. */
			require_once('vendors/000000000000-full.php');
			die(); /* Don't return false and log an error, we're done here */
			return false;
		}
		/* This check is last, because it also matches /000000000000.cfg since the MAC is all 0's. */
		if ($path === "/" . $this->macRequest . ".cfg") {
			/* Let it ask for the generic 000000000000.cfg instead,
			 * at which time we'll run 000000000000-full.php. */
			return false;
		}
		return true;
	}

	public function mtls() : bool {
		if (isset($_SERVER['SSL_CLIENT_S_DN_CN'])) {
			/* For certain requests, e.g. XSI, MAC is in neither the agent nor the request. But it's in the MTLS request! */
			$this->mac = $_SERVER['SSL_CLIENT_S_DN_CN'];
		}
		if ($this->microbrowser) {
			/* Microbrowser requests don't send the client certificate, so we don't verify them at all. */
			return true;
		}
		if (!isset($_SERVER['SSL_CLIENT_S_DN_CN'])) {
			provLog("Not an IP phone: " . $this->agent);
			return false; /* If bypass from this IP is allowed, we might still proceed */
		}
		$clientCertFile = null;
		$expiration = "";
		if (isset($_SERVER['SSL_CLIENT_CERT'])) {
			$clientCert = $_SERVER['SSL_CLIENT_CERT'];
			$clientCertFile = $this->polyCertDir . "/tmp/polycom_" . $this->mac . ".crt";
			if (strlen($clientCert) > 0) {
				if (file_put_contents($clientCertFile, $clientCert) === false) {
					provLog("Failed to write to file $clientCertFile");
				} else if (!file_exists($clientCertFile)) {
					provLog("Failed to write file $clientCertFile");
				}
			} else {
				provErrDump("-- Polycom client cert for " . $this->mac . " [" . $this->uri . "] empty?");
			}
			$expiration = shell_exec("openssl x509 -enddate -noout -in $clientCertFile | tr -d '\n'");
			if (strlen($expiration) < 1) {
				provLog("shell_exec(openssl) failed");
			}
		}
		if (!str_starts_with($this->uri, "/com.broadsoft.xsi-actions") && $_SERVER['SSL_CLIENT_S_DN_CN'] !== $this->mac) {
			provErrDump("-- Polycom client identification MAC mismatch: " . $_SERVER['SSL_CLIENT_S_DN_CN'] . " != " . $this->mac);
			if ($clientCertFile !== null && $this->deleteTemporaryCertificates) {
				unlink($clientCertFile);
			}
			return false;
		}

		/* Variable shorthand for convenience */
		$mac = $this->mac;
		$clientVerify = $this->clientVerify;

		if (!isset($_SERVER['SSL_CLIENT_CERT'])) {
			provLog("SSL_CLIENT_CERT not available: add +ExportCertData to SSLOptions in Apache config");
		}

		if ($this->clientVerify !== "SUCCESS") {
			if ($this->clientVerify === "GENEROUS" && $clientCertFile !== null) {
				$validateScript = $this->polyCertDir . "/validate.sh";
				/* Some Polycom phones have expired certificates since Polycom gave them a short lifetime.
				 * Apache with patch https://github.com/apache/httpd/pull/509 will not reject these requests,
				 * but it will not bestow a SUCCESS on them, so we need to manually verify them. */
				if (!is_executable($validateScript)) {
					/* If we can't run the script, validation is going to fail, so this needs to be fixed! */
					provLog("Script poly/validate.sh is not executable: run chmod +x " . $validateScript);
				}
				$cmd = $validateScript . " $clientCertFile";
				$output = array();
				$res = exec($cmd, $output, $result);
				if ($res === false) {
					provLog("exec(validate.sh) failed");
				}
				if ($this->deleteTemporaryCertificates) {
					unlink($clientCertFile);
				}
				if ($result === 0) {
					/* We successfully validated the cert manually against the Polycom CA, but ignoring expiration. Allow */
					provLog("-- Polycom MTLS PASS-EXPIRED [$mac $expiration] ($clientVerify) - : " . (isset($_SERVER['SSL_CLIENT_S_DN_O']) ? $_SERVER['SSL_CLIENT_S_DN_O'] : ""));
					return true;
				} else {
					/* Not ideal, but for now, just whitelist those that can't do MTLS and accept anyways */
					if (in_array($mac, $this->exemptedPolycoms)) {
						provErrDump("-- Polycom MTLS PASS-SELFSIGN [$mac $expiration] ($clientVerify) res $result");
						return true;
					} else {
						provErrDump("Polycom MTLS hardfail [$mac $expiration] ($clientVerify) res $result");
						return false;
					}
				}
			} else {
				provErrDump("Polycom MTLS fail [$mac $expiration] ($clientVerify)");
				return false;
			}
		} else if (!isset($_SERVER['SSL_CLIENT_S_DN_O']) || $_SERVER['SSL_CLIENT_S_DN_O'] !== 'Polycom Inc.') {
			/* If the certificate on a phone has expired, it won't get here since they verified as GENEROUS above. */
			polycomErrDump("Polycom client identification issue mismatch");
			return false;
		} else {
			provLog("-- Polycom MTLS PASS [$expiration]: $mac: $clientVerify / " . (isset($_SERVER['SSL_CLIENT_S_DN_O']) ? $_SERVER['SSL_CLIENT_S_DN_O'] : ""), 0);
			return true;
		}
	}

	public function configurationOK() : bool {
		/* Semantically, we return false in most branches, even though provFail is fatal */
		if (!file_exists("vendors/000000000000-pre.php") || !file_exists("vendors/000000000000-full.php")) {
			provFail("Missing required 000000000000 configs");
			return false;
		}
		if (strlen($this->polycomLogfile) > 0 && !is_writable($this->polycomLogfile)) {
			$logFile = $this->polycomLogfile;
			provFail("Log file not writable - run touch $logFile && chown www-data:www-data $logFile");
			return false;
		}
		if (!file_exists($this->polyCertDir)) {
			provFail("Directory does not exist: " . $this->polyCertDir);
			return false;
		}
		$tmpDir = $this->polyCertDir . "/tmp";
		if (!file_exists($tmpDir)) {
			provFail("Directory does not exist: $tmpDir - run mkdir $tmpDir && chmod -R 777 $tmpDir");
			return false;
		}
		if (!is_writable($tmpDir)) {
			provLog("Can't write to directory $tmpDir - run chmod -R 777 $tmpDir");
			return false;
		}
		return true;
	}

	public function nonProvision(array $deviceInfo) : bool {
		if ($this->microbrowser) {
			if (isset($deviceInfo['zip'])) {
				$zip = $deviceInfo['zip'];
				$tz = isset($deviceInfo['zip']) ? $deviceInfo['tz'] : "Etc/UTC";
				require_once('polycom-weather.php'); /* If we have location, show a weather applet */
			} else {
				require_once('polycom-time.php'); /* If we don't have location info, show a filler/dummy page with the time */
			}
			return true;
		}
		if (substr($this->uri, -14) === "-directory.xml") {
			return true; /* Directory */
		}
		if (str_starts_with($this->uri, "/com.broadsoft.xsi-actions")) {
			/* TODO Implement */
			return true;
		}
		return false;
	}

	private function polycomSet(&$array, String $key, $value) {
		$array[$key] = $value;
	}

	private function polycomPrint(array $array) {
		$output = "";
		$output .= '<set device.set="1"' . PHP_EOL;
		foreach ($array as $key => $val) {
			$output .= "$key=\"$val\"" . PHP_EOL;
			if (substr($key, 0, 6) === "device") {
				$output .= "$key.set=\"1\"" . PHP_EOL;
			}
		}
		$output .= ">" . PHP_EOL;
		return $output;
	}

	public function preProvision(array $provConfig) {
		if ($this->uri === "/000000000000.cfg") {
			/* Static file, but we need to set the appropriate Content-Type header,
			 * which this script does. */
			require_once('vendors/000000000000-pre.php');
			return;
		}
		/* else, it's for /polycom.cfg */
		$array = array();
		$this->polycomSet($array, "device.dhcp.bootSrvUseOp", 2);
		$this->polycomSet($array, "device.syslog.prependMac", 1);
		$this->polycomSet($array, "device.syslog.renderLevel", 0);
		$this->polycomSet($array, "device.syslog.serverName", $provConfig['syslog_host']);
		$this->polycomSet($array, "device.syslog.transport", "UDP");
		$this->polycomSet($array, "device.prov.serverType", 3);

		$this->polycomSet($array, "device.prov.serverName", "https://" . $provConfig['provision_host'] . ":" . getVendorPort("polycom")); /* Provisioning */

		/* Firmware (default: http://downloads.polycom.com/voice/software/UC_Software_4_0_15_release_sig_split/) */
		$this->polycomSet($array, "device.prov.upgradeServer", "https://" . $provConfig['provision_host'] . ":" . getVendorPort("polycom"));

		$this->polycomSet($array, "device.sec.TLS.customCaCert1", $this->caCert1);
		$this->polycomSet($array, "device.prov.tagSerialNo", 1);
		$this->polycomSet($array, "httpd.cfg.enabled", 1);
		$this->polycomSet($array, "httpd.cfg.port", 80);
		$this->polycomSet($array, "httpd.cfg.secureTunnelEnabled", 1);
		$this->polycomSet($array, "httpd.cfg.secureTunnelPort", 443);
		$this->polycomSet($array, "httpd.cfg.secureTunnelRequired", 0);
		/* Generate config */
		header("Content-type: application/xml");
		echo $this->polycomPrint($array);
	}

	public function provision(array $deviceInfo, array $lines, array $provConfig) {
		$array = array();

		/* Categorize the device */
		$oldPolycom = (strstr($this->agent, "PolycomSoundPointIP") !== false);
		$bigSoundPoint = (strstr($this->agent, "PolycomSoundPointIP-SPIP_550") !== false || strstr($this->agent, "PolycomSoundPointIP-SPIP_650") !== false);
		$supportsSidecars = strstr($this->agent, "PolycomSoundPointIP-SPIP_650") !== false;
		$isVVX = strstr($this->agent, "VVX") !== false;
		if (strstr($this->agent, "UA/4.0.6.")) {
			provLog($this->mac . ": Please upgrade firmware to 4.0.15.1047 for TLS 1.2 support");
		}

		/* Firmware (default: http://downloads.polycom.com/voice/software/UC_Software_4_0_15_release_sig_split/) */
		$this->polycomSet($array, "device.prov.upgradeServer", "https://" . $provConfig['provision_host'] . ":" . getVendorPort("polycom"));

		/* Microbrowser */
		/* Phones like the SoundPoint 550/650 are good candidates.
		 * The smaller SoundPoints, e.g. 331 support this, but are too small to be very useful, so don't enable on those.
		 * VVX phones don't support the microbrowser at all. */
		$allowMB = ($bigSoundPoint);
		$this->polycomSet($array, "mb.ssawc.enabled", 1);
		$this->polycomSet($array, "mb.ssawc.call.mode", "active");
		$this->polycomSet($array, "mb.idleDisplay.home", $allowMB ? "https://" . $provConfig['provision_host'] . ":" . $this->port . "/" . $this->mac . "-mb.cfg" : "");
		$this->polycomSet($array, "mb.idleDisplay.refresh", $allowMB ? 120 : 0); /* Refresh every 2 minutes by default (min 5 seconds, 0 = default = disabled). */

		if ($bigSoundPoint) { /* The VVX 411 doesn't recognize this */
			$this->polycomSet($array, "font.1.name", "fntIP550_12_U0100_U01FF.fnt");
		}

		/* Push notifications are supported by most phones,
		 * enable for all the ones with good microbrowser support, as well as VVXs. */
		if ($allowMB || $isVVX) {
			$this->polycomSet($array, "httpd.enabled", 1);
			$this->polycomSet($array, "apps.push.alertSound", 1); /* enable audio for alerts */
			$this->polycomSet($array, "apps.push.secureTunnelRequired", ($isVVX ? 1 : 0)); /* so https isn't required for it. */
			$this->polycomSet($array, "apps.push.messageType", 5); /* 0 = None, 1 = Only normal, 2 = Only important, 3 = Only high, 4 = Only critical, 5 = all */
			$this->polycomSet($array, "apps.push.serverRootURL", "");
			$this->polycomSet($array, "apps.push.username", $this->pushUsername);
			$this->polycomSet($array, "apps.push.password", $this->pushPassword);
		}

		$this->polycomSet($array, "diags.telnetd.enabled", 1); /* Default user/pass: Polycom/456 */
		$this->polycomSet($array, "device.dhcp.bootSrvUseOpt", 2); /* Static */
		$this->polycomSet($array, "device.syslog.renderLevel", 4); /* 0 = Debug (Highest), 4 = Minor Errors (surprisingly, 4 seems to work better) */
		$this->polycomSet($array, "log.render.level", 4); /* ditto */
		if (strlen($provConfig['syslog_host']) > 0) {
			$this->polycomSet($array, "device.syslog.serverName", $provConfig['syslog_host']);
			$this->polycomSet($array, "device.syslog.transport", $provConfig['syslog_tls'] ? 3 : 1); /* 1 = UDP, 2 = TCP, 3 = TLS */
			$this->polycomSet($array, "device.syslog.facility", 16); /* 0-23, 16 is default (maps to local 0) */
			$this->polycomSet($array, "device.syslog.prependMac", 1);
		}

		/* Provisioning behavior */
		$this->polycomSet($array, "device.prov.serverType", 3); /* 2 = HTTP, 3 = HTTPS */
		$this->polycomSet($array, "device.prov.serverName", "https://" . $provConfig['provision_host'] . ":" . $this->port);
		$this->polycomSet($array, "device.prov.tagSerialNo", 1); /* Include MAC address in user agent */
		$this->polycomSet($array, "prov.polling.enabled", 1);
		$this->polycomSet($array, "prov.polling.mode", "rel"); /* relative */
		$this->polycomSet($array, "prov.polling.period", "3600"); /* 3600 is the min. 86400 is default. */

		/* TLS configuration */
		$this->polycomSet($array, "device.sec.TLS.customCaCert1", $this->caCert1);
		$this->polycomSet($array, "device.sec.TLS.customCaCert2", $this->caCert2);
		$this->polycomSet($array, "device.sec.TLS.profile.caCertList1", "Platform1");
		$this->polycomSet($array, "device.sec.TLS.profile.caCertList2", "Platform2");
		$this->polycomSet($array, "sec.TLS.profileSelection.SIP", "PlatformProfile" . $this->sipCA); /* Use CA for SIP server */
		$this->polycomSet($array, "sec.TLS.protocol.sip", "TLSv1_2");
		$this->polycomSet($array, "device.sec.TLS.protocol.prov", "TLSv1_2"); /* Also use 1.2 for provisioning */
		$this->polycomSet($array, "sec.TLS.protocol.browser", "TLSv1_2"); /* Ditto for microbrowser */
		$this->polycomSet($array, "device.sec.TLS.protocol.syslog", "TLSv1_2"); /* If using TLS for syslog, use TLS 1.2 */
		$this->polycomSet($array, "device.sec.TLS.profileSelection.syslog", "PlatformProfile" . $this->syslogCA);
		$this->polycomSet($array, "device.sec.TLS.prov.strictCertCommonNameValidation", 1);
		$this->polycomSet($array, "sec.TLS.SIP.strictCertCommonNameValidation", 1);

		/* Backlight: change based on time (below) */
		$this->polycomSet($array, "up.backlight.idleIntensity", 1);
		$this->polycomSet($array, "up.backlight.onIntensity", 3);
		$this->polycomSet($array, "up.backlight.timeout", 30); /* default = 40 */

		/* Time-related settings */
		if (isset($deviceInfo['tz'])) {
			$tz = $deviceInfo['tz'];
			$provtz = null;
			$tzMappings = array(
				'US/Eastern' => -5 * 3600,
				'US/Central' => -6 * 3600,
				'US/Mountain' => -7 * 3600,
				'US/Pacific' => -8 * 3600,
				/* TODO: This is incomplete! */
			);
			if (isset($tzMappings[$tz])) {
				$provtz = $tzMappings[$tz];
				$today = new DateTime("now", new DateTimeZone($tz));
				$hour = (int) $today->format('G');
				$activeHours = ($hour >= 4 && $hour < 22);
				if (strstr($this->agent, "PolycomVVX-VVX_300-UA") !== false) {
					/* The backlight on this phone is not that great. Just leave it off, all the time. */
					$activeHours = false;
				}

				/* Time Zone */
				if ($this->localNTP) {
					$this->polycomSet($array, "tcpIpApp.sntp.address.overrideDHCP", 0);
					$this->polycomSet($array, "device.sntp.serverName", "");
				} else {
					$this->polycomSet($array, "tcpIpApp.sntp.address.overrideDHCP", 1);
					$this->polycomSet($array, "tcpIpApp.sntp.address", "pool.ntp.org");
					$this->polycomSet($array, "device.sntp.serverName", "pool.ntp.org");
				}
				$this->polycomSet($array, "tcpIpApp.sntp.gmtOffset", $provtz);
				$this->polycomSet($array, "device.sntp.gmtOffset", $provtz);
				$this->polycomSet($array, "tcpIpApp.sntp.daylightSavings.enable", 0); /* For some reason, if Polycoms get time via network, DST needs to be disabled or it'll compound it and be an hour ahead. */

				/* Set ringer volume loud or soft, depending on time */
				$this->polycomSet($array, "np.normal.ringing.toneVolume.chassis", $activeHours ? -24 : -33); /* -24 is medium, -33 is pretty soft. */

				/* Update backlight based on current time */
				$this->polycomSet($array, "up.backlight.idleIntensity", $activeHours ? 1 : 0); /* no backlight needed at night, when idle... */
				$this->polycomSet($array, "up.backlight.onIntensity", $activeHours ? 3 : 2); /* not so bright at night */
				if ($allowMB) {
					$this->polycomSet($array, "mb.idleDisplay.refresh", $allowMB ? ($activeHours ? 180 : 900) : 0); /* Refresh every 3 minutes by default (min 5 seconds, 0 = default = disabled). If we know it's not active hours, do it every 15 mins instead. */
				}
				/* TODO: Add VVX powerSaving settings */
			}
		}

		/* Admin Password */
		if (isset($deviceInfo['admin_pw']) && strlen($deviceInfo['admin_pw'])) {
			$pw = $deviceInfo['admin_pw'];
			if (is_numeric($pw)) {
				$this->polycomSet($array, "device.auth.localAdminPassword", $pw);
			} else {
				provLog($this->mac . ": non-numeric Polycom admin password ($pw)");
			}
		}

		$this->polycomSet($array, "feature.urlDialing.enabled", 0); /* disable URL dialing */
		$this->polycomSet($array, "feature.presence.enabled", 1);
		$this->polycomSet($array, "feature.directory.enabled", 1);

		if ($isVVX) {
			/* On newer phones (e.g. VVX-401/411), default to showing lines, not recent calls
			 * (i.e. behave like the older SoundPoint models).
			 * XXX doesn't seem to work with VVX-300? */
			$this->polycomSet($array, "up.OffHookLineView.enabled", 1);
		}

		/* Server Side Features */
		$this->polycomSet($array, "voIpProt.SIP.serverFeatureControl.cf", 1);
		$this->polycomSet($array, "voIpProt.SIP.serverFeatureControl.dnd", 1);
		$this->polycomSet($array, "voIpProt.SIP.serverFeatureControl.missedCalls", 1);
		$this->polycomSet($array, "voIpProt.SIP.serverFeatureControl.localProcessing.cf", 0); /* Don't do local processing. */
		$this->polycomSet($array, "voIpProt.SIP.serverFeatureControl.localProcessing.dnd", 0);

		/* Voicemail Callback */
		$this->polycomSet($array, "msg.mwi.1.callBackMode", "contact");
		$this->polycomSet($array, "msg.mwi.1.callBack", $this->voicemailExten);

		/* Default is disconnect unanswered calls after 60 seconds. 0 = indefinite timeout.
		 * See: https://support.poly.com/support/s/article/knova-13321-outbound-call-disconnects-after-60-seconds */
		$this->polycomSet($array, "call.ringBackTimeOut", 0);

		/* Likewise, ring forever on incoming calls (default is stop and reject after 60 seconds) */
		/* XXX This works for VVX phones, but does not seem to work on SoundPoint phones (which may be a bug with SoundPoints?) */
		$this->polycomSet($array, "call.offeringTimeOut", 0); /* 0 = disable ring timeout and let the switch control it */

		/* Auto Answer */
		$this->polycomSet($array, "voIpProt.SIP.alertInfo.1.class", "ringAutoAnswer");
		$this->polycomSet($array, "voIpProt.SIP.alertInfo.1.value", "Auto Answer");
		$this->polycomSet($array, "se.rt.ringAutoAnswer.ringer", "ringer1"); /* silent */
		$this->polycomSet($array, "se.rt.ringAutoAnswer.timeout", "20"); /* 20 ms instead of default 2000 ms */

		/* XSI Settings */
		/* TODO: Add XSI settings */

		/* SIP Settings */
		$this->polycomSet($array, "voIpProt.SIP.enable", 1);
		foreach ($lines as $line) {
			$jack = (int) $line['jack'];
			$sipServer = $line['server'] . ':' . $line['port'];
			$prot = $line['encryption'] ? "TLS" : "UDPOnly";

			$displayNumber = substr($line['tn'], -7); /* display at most 7 digits. */
			if (strlen($displayNumber) > 4) {
				$displayNumber = substr($displayNumber, -7, -4) . '-' . substr($displayNumber, -4);
			}

			$this->polycomSet($array, "reg.$jack.displayName", $displayNumber);
			$this->polycomSet($array, "reg.$jack.label", $displayNumber);

			$this->polycomSet($array, "reg.$jack.address", $line['username']);

			$this->polycomSet($array, "reg.$jack.auth.useLoginCredentials", 0); /* yes, it won't work if this is enabled! */
			$this->polycomSet($array, "reg.$jack.auth.domain", $sipServer);
			$this->polycomSet($array, "reg.$jack.auth.userId", $line['username']);
			$this->polycomSet($array, "reg.$jack.auth.password", $line['password']);

			$this->polycomSet($array, "voIpProt.server.1.register", 1);

			$this->polycomSet($array, "reg.$jack.server.1.address", $line['server']);
			$this->polycomSet($array, "reg.$jack.server.1.port", $line['port']);
			$this->polycomSet($array, "reg.$jack.server.1.transport", $prot);
			$this->polycomSet($array, "reg.$jack.srtp.offer", $line['encryption'] ? 1 : 0);
			$this->polycomSet($array, "reg.$jack.server.1.expires", 300);

			$this->polycomSet($array, "reg.$jack.outboundProxy.address", $line['server']);
			$this->polycomSet($array, "reg.$jack.outboundProxy.port", $line['port']);
			$this->polycomSet($array, "reg.$jack.outboundProxy.transport", $prot);

			$this->polycomSet($array, "call.autoOffHook.$jack.enabled", ($line['autodial'] ? 1 : 0));
			$this->polycomSet($array, "call.autoOffHook.$jack.contact", "01");

			/* Digit Map settings */
			if ($line['autodial']) {
				$this->polycomSet($array, "dialplan.$jack.digitmap", $line['autodial'] . "S0");
			} else {
				$this->polycomSet($array, "dialplan.$jack.impossibleMatchHandling", 0); /* 0 = send to server immediately, 1 = reorder, 2 = keep collecting digits */
				$this->polycomSet($array, "dialplan.$jack.digitmap.timeOut", "3|3|3|3|3|3|3");
				if ($isVVX) {
					/* VVX only (doesn't work for SoundPoints):
					 * https://docs.poly.com/en-US/bundle/trio-prg-7-1-0/page/r-ucs-ag-per-registration-dial-plan-parameters.html */
					$this->polycomSet($array, "dialplan.$jack.conflictMatchHandling", 1); /* this must be set to 1 or if there are conflicts, the first match will complete, even if it's a prefix. */
					$this->polycomSet($array, "dialplan.$jack.removeEndOfDial", 0); /* remove '#' before sending to server */
				}
				if (strlen($this->digitMap)) {
					$this->polycomSet($array, "dialplan.$jack.digitmap", $this->digitMap);
				}
			}

			/* Server Side features */
			$this->polycomSet($array, "reg.$jack.serverFeatureControl.cf", 1);
			$this->polycomSet($array, "reg.$jack.serverFeatureControl.dnd", 1);

			/* Shared Line Appearances */
			if ($line['shared']) {
				$this->polycomSet($array, "voIpProt.SIP.specialEvent.lineSeize.nonStandard", 0); /* require line-seize */
				$this->polycomSet($array, "reg.$jack.lineKeys", 2); /* 2 line keys if possible */
				$this->polycomSet($array, "reg.$jack.callsPerLineKey", 1); /* Only 1 call per line key */
				$this->polycomSet($array, "reg.$jack.bargeInEnabled", 1); /* Enable Barge-In, otherwise pressing an active line key will just make a new pseudoappearance. */
			}
			$this->polycomSet($array, "reg.$jack.type", $line['shared'] ? "shared" : "private"); /* private or shared (BLA)? - use thirdPartyName with BLA. */
		}

		if (file_exists('vendors/polycom-custom.php')) {
			include_once('vendors/polycom-custom.php');
		}

		/* Generate config */
		$xml = $this->polycomPrint($array);
		if (strlen($this->polycomLogfile) > 0) {
			error_log($xml, 3, $this->polycomLogfile);
		}
		header("Content-type: application/xml");
		echo $xml;
	}

	private function setBLF(&$array, $index, $address, $label, $type) {
		$this->polycomSet($array, "attendant.resourceList.$index.address", $address);
		$this->polycomSet($array, "attendant.resourceList.$index.label", $label);
		$this->polycomSet($array, "attendant.resourceList.$index.type", $type);
	}
}
