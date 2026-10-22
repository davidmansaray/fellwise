#!/usr/bin/env bash
# Create the isolated `fellwise_test` vhost and load the production topology
# into it, so broker tests can never touch live queues.
#   RABBITMQ_MANAGEMENT_URL=http://127.0.0.1:15672 RABBITMQ_USER=… RABBITMQ_PASSWORD=… scripts/broker-test-vhost.sh
set -euo pipefail
URL="${RABBITMQ_MANAGEMENT_URL:-http://127.0.0.1:15672}"
AUTH="${RABBITMQ_USER:-fellwise}:${RABBITMQ_PASSWORD:?set RABBITMQ_PASSWORD}"
DIR="$(cd "$(dirname "$0")/.." && pwd)"

curl -fsS -u "$AUTH" -X PUT "$URL/api/vhosts/fellwise_test" >/dev/null
curl -fsS -u "$AUTH" -X PUT -H 'content-type: application/json' \
  -d '{"configure":".*","write":".*","read":".*"}' "$URL/api/permissions/fellwise_test/${RABBITMQ_USER:-fellwise}" >/dev/null
php -r '$d = json_decode(file_get_contents($argv[1]), true);
  foreach (["exchanges", "queues", "bindings"] as $k) { foreach ($d[$k] as &$x) { $x["vhost"] = "fellwise_test"; } unset($x); }
  echo json_encode(["exchanges" => $d["exchanges"], "queues" => $d["queues"], "bindings" => $d["bindings"]]);' \
  "$DIR/infra/rabbitmq/definitions.json" \
  | curl -fsS -u "$AUTH" -X POST -H 'content-type: application/json' --data @- "$URL/api/definitions/fellwise_test" >/dev/null
echo "fellwise_test vhost ready"
