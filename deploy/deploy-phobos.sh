#!/usr/bin/env bash
# Build this fork on phobos and switch ~/radio-api to the new image.
# Usage: deploy/deploy-phobos.sh   (pushes nothing; builds the commit at origin/master)
# After the switch it runs deploy/smoke-test.sh; any failure switches back to the previous image.
# Manual rollback: set image back in ~/radio-api/compose.yaml (previous tag kept) and `docker compose up -d`.
set -euo pipefail
HOST=${HOST:-djones@192.168.50.2}
BRANCH=${BRANCH:-master}
ssh -o BatchMode=yes "$HOST" BRANCH="$BRANCH" bash -s <<'REMOTE'
set -euo pipefail
src=~/src/Radio-API
[ -d "$src/.git" ] || git clone -q https://github.com/dylan7474/Radio-API.git "$src"
cd "$src" && git fetch -q origin && git checkout -q "$BRANCH" && git reset -q --hard "origin/$BRANCH"
tag="$(sed -n 2p VERSION)-dj-$(git rev-parse --short HEAD)"
docker build -q -f utils/Dockerfile -t "radio-api-dj:$tag" . >/dev/null
cd ~/radio-api
old=$(grep -oP 'image: \K\S+' compose.yaml)
sed -i "s|image: .*|image: radio-api-dj:$tag|" compose.yaml
docker compose up -d 2>&1 | tail -1
for i in $(seq 1 20); do s=$(docker inspect -f '{{.State.Health.Status}}' radio_api); [ "$s" = healthy ] && break; sleep 2; done
code=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/gui/)
echo "was: $old  now: radio-api-dj:$tag  health: $s  gui: $code"
rollback() { echo "FAILED ($1) - rolling back to $old"; sed -i "s|image: .*|image: $old|" compose.yaml; docker compose up -d; exit 1; }
[ "$s" = healthy ] && [ "$code" = 200 ] || rollback "health/GUI"
# the whole fork end to end: radios' XML + JSON menus, page, relay, Music + ffmpeg (deploy/smoke-test.sh)
bash "$src/deploy/smoke-test.sh" || rollback "smoke test"
# phobos disk is tight: keep only the running image and the one before it (for rollback)
for img in $(docker images radio-api-dj --format '{{.Repository}}:{{.Tag}}'); do
  [ "$img" = "radio-api-dj:$tag" ] || [ "$img" = "$old" ] || docker rmi -f "$img" >/dev/null
done
docker image prune -f >/dev/null
REMOTE
