#!/bin/bash
set -e
if [ "$1" = "" ]; then
	echo "Missing argument"
	exit 1
fi
openssl verify -no_check_time -CAfile /home/polycerts/polycom_root_ca.crt -untrusted <(cat /home/polycerts/polycom_intermediate_eqp*.pem /home/polycerts/polycom_intermediate_policy.pem) $1
