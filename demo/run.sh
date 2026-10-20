#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
root=$PWD
runtime="$root/demo/.runtime"
engine=${CONTAINER_ENGINE:-docker}
identity=()
if [[ "$engine" == *docker ]]; then identity=(--user "$(id -u):$(id -g)"); fi
image='docker.io/library/wordpress@sha256:607e9f6829f9e4f3001bc91c98426aaa742d164af030aa7a8073932798e2af6b'
composer='docker.io/library/composer@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac'
database='docker.io/library/mariadb@sha256:1292844148b311e4ed4300022a996d39083f415a963e970cf47cad1b3b18e3a6'
mkdir -p "$runtime/site"
"$engine" run --rm "${identity[@]}" -e COMPOSER_HOME=/tmp/soca-composer --network host -v "$root:/app" -w /app "$composer" install --no-interaction --no-progress --ignore-platform-req=ext-curl
python3 bin/package.py
if [[ ! -f "$runtime/site/libraries/src/Version.php" ]]; then
  curl -fsSL https://github.com/joomla/joomla-cms/releases/download/5.4.0/Joomla_5.4.0-Stable-Full_Package.tar.gz -o "$runtime/joomla.tar.gz"
  printf '%s  %s\n' 2fe15f0e123a41db0b82e9cd0a79bfbd8581179363ae6169f66ded2fa8f66ab4 "$runtime/joomla.tar.gz" | sha256sum -c -
  tar -xzf "$runtime/joomla.tar.gz" -C "$runtime/site"
fi
if ! "$engine" inspect joomla-soca-demo-db > /dev/null 2>&1; then
  "$engine" run -d --name joomla-soca-demo-db -p 127.0.0.1:13408:3306 -e MARIADB_ROOT_PASSWORD=soca-disposable-local-test -e MARIADB_DATABASE=soca_joomla "$database" > /dev/null
else "$engine" start joomla-soca-demo-db > /dev/null; fi
for attempt in $(seq 1 60); do
  if "$engine" exec joomla-soca-demo-db mariadb --protocol=tcp -h127.0.0.1 -uroot -psoca-disposable-local-test -e "SELECT 1" > /dev/null 2>&1; then break; fi
  sleep 1
done
run() { "$engine" run --rm "${identity[@]}" -e COMPOSER_HOME=/tmp/soca-composer --network host -e JOOMLA_ROOT=/demo/site -v "$root:/plugin" -v "$runtime:/demo" -w /demo/site "$image" php "$@"; }
if [[ ! -f "$runtime/site/configuration.php" ]]; then
  run installation/joomla.php install --site-name='SOCA Joomla demo' --admin-user='Demo Administrator' --admin-username=admin --admin-password='Demo-Only-Replace-Me-2841' --admin-email=admin@example.com --db-type=mysqli --db-host=127.0.0.1:13408 --db-user=root --db-pass=soca-disposable-local-test --db-name=soca_joomla --db-prefix=soca_ --no-interaction > "$runtime/install.log" 2>&1
fi
run cli/joomla.php extension:install --path=/plugin/build/pkg_soca-0.1.0.zip --no-interaction
# Joomla's CLI web-server mode needs a configured canonical site URL.
python3 - "$runtime/site/configuration.php" <<'PYCODE'
from pathlib import Path
import sys
path = Path(sys.argv[1])
path.write_text(path.read_text().replace("public $live_site = '';", "public $live_site = 'http://127.0.0.1:18083';"))
PYCODE
run /plugin/tests/host.php

# Public documents are checked locally without weakening production HTTPS rules.
run -r '$key=openssl_pkey_new(["private_key_type"=>OPENSSL_KEYTYPE_EC,"curve_name"=>"prime256v1"]);openssl_pkey_export($key,$pem);file_put_contents("/demo/http-key.pem",$pem);'
printf '%s' '{"enabled":false,"origin":"https://app.example.com","allowed_domains":["example.com"]}' > "$runtime/http-profile.json"
"$engine" run -d --stop-signal SIGTERM --stop-timeout 1 --name joomla-soca-smoke --network host "${identity[@]}" -e SOCA_CONFIG_FILE=/demo/http-profile.json -e SOCA_PRIVATE_KEY_FILE=/demo/http-key.pem -v "$root:/plugin" -v "$runtime:/demo" -w /demo/site "$image" php -d max_input_vars=5000 -S 127.0.0.1:18083 -t /demo/site > /dev/null
trap '"$engine" rm -f joomla-soca-smoke > /dev/null 2>&1 || true' EXIT
python3 tests/http_smoke.py
"$engine" kill joomla-soca-smoke > /dev/null
"$engine" rm -f joomla-soca-smoke > /dev/null
trap - EXIT

if [[ ${1:-test} == serve ]]; then
  printf 'Local demo: http://127.0.0.1:18083/administrator (admin / Demo-Only-Replace-Me-2841)\n'
  run -S 127.0.0.1:18083 -t /demo/site
fi
