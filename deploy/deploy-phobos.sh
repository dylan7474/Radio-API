#!/usr/bin/env bash
# Build this fork on phobos and switch ~/radio-api to the new image.
# Usage: deploy/deploy-phobos.sh   (pushes nothing; builds the commit at origin/master)
# Rollback: set image back in ~/radio-api/compose.yaml (previous tag kept) and `docker compose up -d`.
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
[ "$s" = healthy ] && [ "$code" = 200 ] || { echo "FAILED - rolling back to $old"; sed -i "s|image: .*|image: $old|" compose.yaml; docker compose up -d; exit 1; }
REMOTE
