#!/usr/bin/env bash
# Probe ZRA's VFMS test server from a developer machine. Reads VFMS_TEST_* from .env (never commit values).
#   scripts/vfms-probe.sh post <hotel|services> <vfms-request-type> <path> '<json>'
#   scripts/vfms-probe.sh receipt <receiptNumber>   # what ZRA's viewer shows for a receipt
# Test only: refuses any URL other than ZRA's test server.
set -euo pipefail
set -a; . "$(dirname "$0")/../.env"; set +a
case "${VFMS_TEST_URL:-}" in http://102.223.7.131:6060/*) ;; *) echo "VFMS_TEST_URL is not ZRA's test server" >&2; exit 1 ;; esac
origin=${VFMS_TEST_URL%/vfms/api/*}

case "${1:-}" in
post)
    if [ "$2" = services ]; then id=$VFMS_TEST_SERVICES_INTEGRATION_ID token=$VFMS_TEST_SERVICES_TOKEN
    else id=$VFMS_TEST_INTEGRATION_ID token=$VFMS_TEST_TOKEN; fi
    curl -s -m 30 -w '\nHTTP %{http_code}\n' -X POST "${VFMS_TEST_URL%/}/$4" -H 'Content-Type: application/json' \
        -H "vfms-request-type: $3" -H "vfms-intergration-id: $id" -H "vfms-token-id: $token" -d "$5" ;;
receipt)
    # The viewer page's own fixed header: "ZRA" + Zanzibar date (YYYYMMDD) + "VFMS!@#".
    curl -s -m 30 -H "token: ZRA$(TZ=Africa/Dar_es_Salaam date +%Y%m%d)VFMS!@#" "$origin/sales-service/api/v2/sales_detail/receipt/$2" ;;
*) sed -n 2,6p "$0"; exit 1 ;;
esac
