#!/usr/bin/env bash
# Smoke test of the running Radio-API container (fork). Run ON phobos:
#   ~/src/Radio-API/deploy/smoke-test.sh          (deploy-phobos.sh runs it after every deploy)
# Read-only for the radios: uses the radios' own ids from data/table.json, never sends &play
# (so Recently played is untouched) and never starts a relayed station (a receiver would retune).
# It does play ~4 s of the first album in the Music menu to itself, to check ffmpeg end to end.
# Exit code = number of failed checks.
set -uo pipefail
C=${CONTAINER:-radio_api}
BASE=${BASE:-http://127.0.0.1}
SBASE=${SBASE:-https://127.0.0.1}
DATA=${DATA:-$HOME/radio-api/data}
fails=0
ok()   { printf '  ok    %s\n' "$1"; }
bad()  { printf '  FAIL  %s\n' "$1"; fails=$((fails+1)); }
check(){ local name=$1; shift; if "$@" >/dev/null 2>&1; then ok "$name"; else bad "$name"; fi; }

echo "Radio-API smoke test ($(docker inspect -f '{{.Config.Image}}' "$C" 2>/dev/null))"

# 1. every PHP file parses (catches errors in pages that are rarely opened, like music.php);
#    deprecation notices from upstream's code are not failures
lint=$(docker exec "$C" sh -c 'find /php-code -name "*.php" -not -path "*/data/*" -exec php -l {} \; 2>&1; php -l /music-covers.php 2>&1; php -l /station-check.php 2>&1' | grep -E "Parse error|Fatal error|Errors parsing")
[ -z "$lint" ] && ok "PHP files parse" || { bad "PHP files parse"; echo "$lint" | head -5; }

# 2. the tools the fork needs are in the image
check "ffmpeg runs"        docker exec "$C" ffmpeg -hide_banner -version
check "rsvg-convert runs"  docker exec "$C" rsvg-convert --version
check "nightly jobs scheduled (crond + station check)" docker exec "$C" sh -c 'pgrep crond && [ -x /etc/periodic/daily/station-check ] && grep -q CONF_DOMAIN /etc/radio-api.env'

# 3. the web page and its API
check "page /app/ loads"   bash -c "curl -sf -m10 '$BASE/app/' | grep -q '<html'"
who=$(curl -s -m10 "$BASE/app/api.php?a=who")
echo "$who" | grep -q '"ok":true' && ok "page API lists the radios" || bad "page API lists the radios"
check "classic /gui/ loads" bash -c "[ \"\$(curl -s -o /dev/null -w '%{http_code}' -m10 '$BASE/gui/')\" = 200 ]"
check "stand-in logo served" bash -c "curl -sfI -m10 '$BASE/media/station.png' | grep -qi 'content-type: image/png'"

# 3b. logo upload on the page (a fixed 16 px test picture; its file is removed again afterwards)
jar=$(mktemp)
curl -s -m10 -c "$jar" -b "$jar" -X POST -H 'Content-Type: application/json' -d '{"id":1}' "$BASE/app/api.php?a=pick" >/dev/null
up=$(curl -s -m10 -b "$jar" -X POST -H 'Content-Type: application/json' \
  -d '{"png":"data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAGUlEQVR4nGMQMgn7TwlmGDVg1IBRA4aLAQCYX5sQG9SnPgAAAABJRU5ErkJggg=="}' "$BASE/app/api.php?a=logoupload")
np=$(curl -s -m10 -b "$jar" "$BASE/app/api.php?a=nowplaying")
echo "$np" | grep -q '"ok":true' && echo "$np" | grep -q '"radios":\[{' && ok "page Now playing answers" || bad "page Now playing answers"
mus=$(curl -s -m30 -b "$jar" "$BASE/app/api.php?a=music")
if docker exec "$C" sh -c '[ -d /music ] && [ -n "$(ls -A /music)" ]'; then
  echo "$mus" | grep -q '"ok":true' && echo "$mus" | grep -q '"dirs":\[{' && ok "page Music tab lists the library" || bad "page Music tab lists the library"
fi
rm -f "$jar"
upfile=$(echo "$up" | grep -oP 'up-[0-9a-f]{40}\.png' | head -1)
if [ -n "$upfile" ] && curl -sfI -m10 "$BASE/media/logos/$upfile" | grep -qi 'content-type: image/png'; then ok "logo upload"; else bad "logo upload ($up)"; fi
[ -n "$upfile" ] && docker exec "$C" rm -f "/php-code/media/logos/$upfile"

# 4. what each radio asks for: XML (mac) and JSON (radio id over https), for every known radio
ids=$(docker exec "$C" php -r '$t=json_decode(file_get_contents("/php-code/data/table.json"),true); foreach(($t["ids"]??[]) as $i=>$v) echo $i," ",$v[0]??"-"," ",$v[2]??"-","\n";')
[ -n "$ids" ] || bad "radios known (data/table.json)"
firstmac=
while read -r id mac rid; do
  [ -n "$id" ] || continue
  if [ "$mac" != - ]; then
    firstmac=${firstmac:-$mac}
    x=$(curl -s -m10 "$BASE/setupapp/hama/asp/BrowseXML/loginXML.asp?gofile=LoginXML.asp&mac=$mac&dlang=en")
    echo "$x" | grep -q '<ListOfItems>' && echo "$x" | grep -q '<Title>Radio' \
      && ok "radio $id: XML main menu" || bad "radio $id: XML main menu"
  fi
  if [ "$rid" != - ]; then
    auth=$(printf '%s:x' "$rid" | base64)
    j=$(curl -sk -m10 -H "Authorization: $auth" "$SBASE/list?tid=1")
    echo "$j" | grep -q '"entries"' && ok "radio $id: JSON station list (https)" || bad "radio $id: JSON station list (https)"
  fi
done <<< "$ids"

# 5. relay.php answers (without converting anything: an id that does not exist must give 404)
if [ -n "$firstmac" ]; then
  code=$(curl -s -o /dev/null -w '%{http_code}' -m10 "$BASE/relay.php?id=9999&mac=$firstmac")
  [ "$code" = 404 ] && ok "relay.php answers" || bad "relay.php answers (got $code, want 404)"
fi

# 6. Music: first artist -> first album -> 4 s of "Play all" must be MP3, and ffmpeg must stop after
if [ -n "$firstmac" ] && docker exec "$C" sh -c '[ -d /music ] && [ -n "$(ls -A /music)" ]'; then
  firstdir() { curl -s -m20 "$BASE/music?${1:+id=$1&}mac=$firstmac&dlang=en" | grep -oP 'music\?id=\Kmu[0-9a-f]{30}' | head -1; }
  artist=$(firstdir "")
  album=$(firstdir "$artist")
  album=${album:-$artist}
  if [ -z "$album" ]; then bad "Music menu lists the library"
  else
    ok "Music menu lists the library"
    before=$(docker exec "$C" pgrep ffmpeg | wc -l)
    out=$(curl -s -m4 -o /dev/null -w '%{content_type} %{size_download}' "$BASE/music.php?id=$album&mac=$firstmac")
    read -r ctype size <<< "$out"
    [ "$ctype" = audio/mpeg ] && [ "${size:-0}" -gt 50000 ] && ok "Music plays (${size} bytes in 4 s)" || bad "Music plays ($out)"
    sleep 5
    after=$(docker exec "$C" pgrep ffmpeg | wc -l)
    [ "${after:-0}" -le "${before:-0}" ] && ok "ffmpeg stopped when the listener left" || bad "ffmpeg left running ($before before, $after after)"
  fi
else
  echo "  skip  Music (no library mounted)"
fi

[ "$fails" = 0 ] && echo "All checks passed." || echo "$fails check(s) FAILED."
exit "$fails"
