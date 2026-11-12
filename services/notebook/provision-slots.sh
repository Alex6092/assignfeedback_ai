#!/bin/sh
# Run on the dedicated workspace VM, on an XFS mount with prjquota enabled.
set -eu
root=${1:-/srv/notebooks}
count=${2:-128}
case "$root" in /srv/notebooks) ;; *) echo 'Expected dedicated /srv/notebooks mount' >&2; exit 1;; esac
mountpoint -q "$root"
findmnt -n -o FSTYPE "$root" | grep -qx xfs
findmnt -n -o OPTIONS "$root" | grep -Eq '(^|,)(prjquota|pquota)(,|$)'
i=0
while [ "$i" -lt "$count" ]; do
    project=$((20000 + i))
    mkdir -p "$root/$i"
    chown 1000:1000 "$root/$i"
    chmod 700 "$root/$i"
    xfs_quota -x -c "project -s -p $root/$i $project" "$root"
    xfs_quota -x -c "limit -p bhard=2g ihard=100000 $project" "$root"
    touch "$root/$i.quota"
    i=$((i + 1))
done
