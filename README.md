# teleprovisioner

A collection of PHP scripts (for use with the Apache web server) that can be used to dynamically provision analog telephone adapters (ATAs) and IP phones

## Background

This is designed to support dynamically provisioning of a variety of ATAs and IP phones from different vendors for heterogenous IP telephony environments. Little to no static configuration is required - no need to waste time building individual provisioning configs and securing them!

This provisioning server does *not* aim to provision every possible setting to devices. Rather, it is designed to take device and line information and generate the needed config for each vendor's equipment, optimized where needed. Much of this entails taking common settings (e.g. the SIP server) and spitting out the config for each type of device in the "language" they understand (each vendor has their own). It is designed so that ATAs and IP phones can be fully set up in a zero-touch or lite-touch manner.

Consequently, a setting you may be looking to configure may be missing. Some of the provisioning choices in the vendor scripts are also opinionated in certain ways. This will likely not change, though you are certainly free to modify or add to the code to suit your own needs.

The code here is adapted from a monolithic, multi-vendor provisioning system that I have been using for several years now. While comprehensive, much of its logic was specific to the systems for which it was designed, and it could not be dropped into a different environment to provision devices. It also grew organically over time into a 2,000+ line script that became hard to maintain, with many nuances and customizations. This code aims to allow for most of the same general-purpose provisioning logic, while maintaining a modular design to make it easier to understand and update. It also allows for provisioning in a variety of environments and for a variety of systems; while hardware and feature support is more limited initially, over time, the goal is to port more functionality over.

*TL;DR If you need a simple system to provision your devices to make it work, this will certainly do that. If you need something to work in a specific way or configure something esoteric, it likely won't do that out of the box, but could likely be modified to do so.*

**Supported Hardware**

- Most Grandstream ATAs (e.g. HT 7xx, HT 8xx)
- Most Polycom IP phones (e.g. SoundPoint, VVX)

Currently, hardware support is somewhat limited. Support for Cisco and Yealink devices, and more limited support for Obi and Audiocodes devices, exists in the source system, but these vendors have not yet been ported here. If you have a use for a specific non-ported vendor, we can possibly expedite its addition.

**Noteworthy Supported Features**

- Dynamic provisioning using HTTP/HTTPS, without existing static config files
- Mutual TLS, for secure and authenticated provisioning
- Support for older ATAs and IP phones
- Weather application for Polycom microbrowser
- Shared line appearances (Polycom)

## Theory of Operation

The provisioning server is designed with a two-phase architecture. Initially, devices from all vendors provision on HTTP port 80. Here, they receive a "bootstrap" config with the bare minimum settings needed to begin provisioning securely. This is done because many ATAs/IP phones have limited or quirky TLS support, so directly provisioning using HTTPS from the get-go may fail (e.g. you may need to specify your cert's CA in the bootstrap config). This is also often a good time to update the firmware.

After the device bootstrap, it is directed to a vendor-specific port for HTTPS provisioning. MTLS is used, so that only the actual ATA or IP phone for which a configuration is intended is able to retrieve it, which keeps your SIP credentials and other configuration information secure. A different port is used for each vendor, since not all devices support SNI (Server Name Indication). After the bootstrap, provisioning is only done securely via HTTPS.

## Requirements

* Apache HTTP web server
* A recent version of PHP 8. It is assumed that `mod_php` is being used.
* If you need to support older Polycom IP phones, you first need to compile Apache (and subsequently, PHP) from source. (A [recent bugfix](https://github.com/notroj/httpd/commit/258226ff551b3985227820de05bd9a249d1a495d) is required for MTLS to work on Polycom phones with expired certificates, and this fix is not yet present in the packages for most systems. You can use `supplementary/compile-apache2.sh` to do this.)

## Installation and Configuration
* Determine what TLS certificates will be used for the server. Commercial TLS certificates work out of the box; Let's Encrypt certificates should also work, but you may need to specify the CA in the provisioning configs for each vendor as Let's Encrypt support is not as universal (and you certainly will if using an internal or self-signed CA as well).
* Clone this repo to a subdirectory of `/var/www/html` on your web server.
* Add the necessary virtualhosts for your Apache web server config. An example is provided in `supplementary/apache2.conf`. The CA certificates for each vendor are also located in `supplementary`, to move to `/etc/ssl/certs`.
* Run `sed -i 's/create 640 root adm/create 640 www-data adm/' /etc/logrotate.d/apache2` so that Apache can write to custom log files after they are created or rotated.
* Copy `config.sample.inc.php` to `config.inc.php` and update the settings in `config.inc.php` as needed for your environment.
* If you need to support older Polycom phones, copy the `polycerts` subdirectory to a location on the system outside the webroot, e.g. `/home/polycerts`, update `polycerts/validate.sh` with the full path to the CA files, and set `$polyCertDir` accordingly in `polycom.php`
* Test the home page and make sure that you get a 404 error. If you get a 500 error, setup may not be complete. Guided error messages will help you complete setup.
* Disable `$outputErrors` in `config.inc.php` (e.g. `$outputErrors = false;`) to disable suggestions provided during setup and ensure there are no warnings or errors in the Apache error log.

## Database Schema

The `mac` table contains one entry per each of your devices, identified by MAC address (for esoteric reasons that are too boring to explain, this table was not called `devices`). While any device is allowed to request bootstrap configs over port 80, only devices with a matching MAC address in this table will be able to retrieve provisioning configs from the vendor-specific HTTPS port.

The schema is fairly self-explanatory, but in a nutshell, these are the current columns:
- `mac` - Device MAC address
- `serial` - Device serial number (for record-keeping only). Not used for provisioning, but if this table is your master table of devices, you could store the SN here
- `admin_pw` - The admin password for the device
- `http_credentials` - The current HTTP Basic Authentication credentials for provisioning (only used for Grandstream, currently)
- `mfer` - Device manufacturer
- `model` - Device model
- `location` - Device location (for record-keeping only)
- `tz` - The time zone in which the device is located
- `zip` - ZIP code where the device is located. Only used for the Polycom weather application
- `ip` - IP CIDR range restriction for provisioning (if non-NULL, only provisioning requests from this CIDR range are allowed)
- `added` - When the device was added to the table
- `last_resync` - When the device last successfully provisioned

If you have a business system that inserts records into this table, for Grandstream devices supported by GDMS (if you use GDMS), it would be a good idea to add the device to GDMS at the same time you add it to this table.

The `provision` table contains provisioning records (typically one per each SIP endpoint or line on the device):
- `id` - Auto-increment ID
- `tn` - The telephone number of the line. Only used for display purposes within the configuration and on the line key (for IP phones)
- `jack` - The physical port (ATA) or line key (IP phone) to use for this line
- `server` - SIP server
- `port` - SIP port
- `username` - SIP username
- `password` - SIP password
- `autodial` - If non-NULL, the SIP extension to autodial when going off-hook
- `encryption` - Whether to use TLS encryption for signaling and SRTP encryption for media
- `active` - Whether the line is active. Default is 1, and if set to 0, the line is disabled and will not be provisioned
- `shared` - Whether the line is a shared line appearance (e.g. for Polycom phones)
- `added` - When the line was added to the table

## FAQ

**What protocols are supported for provisioned devices?**

Currently, only SIP is supported.

**Is this the same code used by the PhreakNet provisioning server?**

No, although much of the code here is originally adapted from that. This is a heavily simplified and cleaned-up version that is intended for standalone deployments in heterogenous environments. The PhreakNet provisioning server contains a significant amount of implementation-specific logic, which has not been ported here.

**Help, it's not provisioning!**

While there are many possible things that can go wrong, a good place to start is factory resetting the device and pointing it at the HTTP provisioning endpoint in your environment, even if it's already been provisioned. This will ensure it starts from a clean state. Also, make sure you have recent firmware for your device.

If issues persist, you may wish to open a bug report with the appropriate vendor, as bugs with these kinds of embedded devices are not uncommon.
