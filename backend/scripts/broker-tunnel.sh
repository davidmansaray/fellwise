#!/usr/bin/env bash
# Forward a remote RabbitMQ (AMQP 5682, management 15682) to localhost.
#   BROKER_SSH_HOST=my-host scripts/broker-tunnel.sh          start in the background (no-op if up)
#   BROKER_SSH_HOST=my-host scripts/broker-tunnel.sh --watch  stay in the foreground and reconnect forever
# Without BROKER_SSH_HOST the broker is assumed to be local (infra/rabbitmq/docker-compose.yml).
set -euo pipefail
HOST="${BROKER_SSH_HOST:-}"
OPTS=(-N -o ExitOnForwardFailure=yes -o ServerAliveInterval=15 -o ServerAliveCountMax=3
      -L 5682:127.0.0.1:5682 -L 15682:127.0.0.1:15682)

if [[ -z "$HOST" ]]; then
  echo "BROKER_SSH_HOST not set: using a local broker, no tunnel"
  # Stay alive under `composer workers` (concurrently -k stops everything when one exits).
  [[ "${1:-}" == "--watch" ]] && exec tail -f /dev/null
  exit 0
fi

if [[ "${1:-}" == "--watch" ]]; then
  while true; do
    if ! lsof -iTCP:5682 -sTCP:LISTEN >/dev/null 2>&1; then
      echo "$(date +%T) opening broker tunnel to ${HOST}"
      ssh "${OPTS[@]}" "$HOST" || echo "$(date +%T) tunnel dropped"
    fi
    sleep 2
  done
fi

if lsof -iTCP:5682 -sTCP:LISTEN >/dev/null 2>&1; then echo "tunnel already up"; exit 0; fi
ssh -f "${OPTS[@]}" "$HOST"
echo "broker tunnel up: amqp://127.0.0.1:5682  http://127.0.0.1:15682"
