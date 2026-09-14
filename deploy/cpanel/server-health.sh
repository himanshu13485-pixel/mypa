#!/bin/bash
# What the server looks like right now - read-only, changes nothing.
#   bash /home/grapme/netvork/deploy/cpanel/server-health.sh
#
# Run it the moment a site on this box stops answering, BEFORE rebooting: a
# reboot wipes the evidence. netvork.app, grapout.com and grapout.com/trade all
# run under the one cPanel account, so they share its memory, process and PHP
# limits - one of them exhausting a limit takes the others down with it.

APP_USER=${APP_USER:-grapme}
HOST_IP=${HOST_IP:-10.131.0.5}
SITES=${SITES:-"netvork.app grapout.com grapout.com/trade/"}

section() { echo; echo "===== $* ====="; }

section "when"
date
uptime

section "sites (resolved to $HOST_IP)"
for s in $SITES; do
  host=${s%%/*}
  code=$(curl -sk -o /dev/null -w '%{http_code} in %{time_total}s' --max-time 20 \
    --resolve "$host:443:$HOST_IP" "https://$s" 2>/dev/null || echo "000 (no answer)")
  echo "$s -> $code"
done

section "memory"
free -m
echo
swapon --show 2>/dev/null

section "biggest processes by memory"
ps -eo pid,user,rss,etime,comm,args --sort=-rss | head -20 | cut -c1-200

section "processes per user (cPanel / CloudLinux caps these)"
ps -eo user= | sort | uniq -c | sort -rn | head -10
echo
echo "$APP_USER php processes: $(pgrep -u "$APP_USER" -c php 2>/dev/null || echo 0)"
echo "$APP_USER node processes: $(pgrep -u "$APP_USER" -c node 2>/dev/null || echo 0)"

section "kernel out-of-memory kills (last 50)"
(dmesg -T 2>/dev/null || journalctl -k --no-pager 2>/dev/null) \
  | grep -iE 'out of memory|oom-kill|killed process' | tail -50 || echo "none found"

section "CloudLinux account limits (if this is CloudLinux)"
if command -v lveinfo >/dev/null 2>&1; then
  lveinfo --user="$APP_USER" --period=2h --show-columns=From,To,aCPU,mCPU,aPMem,mPMem,aEP,mEP,aNproc,mNproc,PMemF,EPf,NprocF 2>/dev/null \
    || lveinfo --user="$APP_USER" --period=2h 2>/dev/null
  echo
  lvectl list-user 2>/dev/null | head -5
else
  echo "not CloudLinux (no lveinfo)"
fi

section "PHP-FPM: pools hitting max_children"
for log in /opt/cpanel/ea-php*/root/usr/var/log/php-fpm/error.log; do
  [ -f "$log" ] || continue
  echo "-- $log"
  grep -iE 'max_children|seems busy|failed|exited' "$log" | tail -15
done

section "Apache"
if [ -x /scripts/restartsrv_httpd ]; then /scripts/restartsrv_httpd --status 2>&1 | tail -5; fi
for log in /etc/apache2/logs/error_log /usr/local/apache/logs/error_log; do
  [ -f "$log" ] || continue
  echo "-- $log (last 25)"
  tail -25 "$log" | cut -c1-300
done

section "grapout.com recent errors"
for log in /etc/apache2/logs/domlogs/grapout.com /etc/apache2/logs/domlogs/grapout.com-ssl_log \
           /usr/local/apache/domlogs/grapout.com /usr/local/apache/domlogs/grapout.com-ssl_log; do
  [ -f "$log" ] || continue
  echo "-- $log: 5xx in the last 2000 requests"
  tail -2000 "$log" | awk '$9 ~ /^5/ {print $4, $7, $9}' | tail -15
done
for log in /home/$APP_USER/public_html/error_log /home/$APP_USER/public_html/trade/error_log; do
  [ -f "$log" ] && { echo "-- $log"; tail -15 "$log" | cut -c1-300; }
done

section "database"
if command -v mysqladmin >/dev/null 2>&1; then
  mysqladmin ping 2>&1
  mysqladmin status 2>&1
fi

section "disk"
df -h / /home /tmp 2>/dev/null

section "workers"
for unit in netvork-queue netvork-reverb grapout-trade-queue grapout-trade-reverb; do
  printf '%-24s %s\n' "$unit" "$(systemctl is-active "$unit" 2>/dev/null)"
done
ss -ltnp 2>/dev/null | grep -E ':(8443|8444) ' || echo "no reverb port listening"

echo
echo "===== end ====="
