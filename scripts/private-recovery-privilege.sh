#!/bin/sh
# NONIUSWTAX local backup recovery ONLY. Runs inside the isolated Docker
# MariaDB service. Never runs on Hostinger or changes the source SQL backup.
# Keep LF line endings on Windows via existing .gitattributes.
set -eu

if [ "$#" -ne 1 ]; then
  echo "Invalid local recovery privilege operation" >&2
  exit 2
fi

case "$1" in
  grant)
    sql="GRANT SET USER ON *.* TO 'nonius_private_import'@'%';"
    ;;
  revoke)
    sql="REVOKE SET USER ON *.* FROM 'nonius_private_import'@'%';"
    ;;
  *)
    echo "Invalid local recovery privilege operation" >&2
    exit 2
    ;;
esac

: "${MARIADB_ROOT_PASSWORD:?Local disposable MariaDB root password is unavailable}"
# The password is read from the disposable DB container environment. No
# password, SQL statement or secret is printed; no shell positional forwarding
# or nested quoting through Windows PowerShell 5.1 is required.
MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb --batch --silent \
  --skip-column-names --user=root --execute="$sql"
