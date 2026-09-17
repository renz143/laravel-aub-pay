#!/usr/bin/env bash
#
# Runs the suite against each supported PHP / Laravel pairing.
#
#   scripts/test.sh            # all three legs
#   scripts/test.sh 8.1        # just the Laravel 10 leg
#
# The legs are not interchangeable. PHP decides most of the tree on its own — 8.1 cannot install
# Laravel 12 or web-token v4 — but 8.2 satisfies both Laravel 11 and 12, so that leg pins the
# framework explicitly rather than drifting up to whatever is newest.
#
# COMPOSER_POLICY_ADVISORIES_BLOCK is off because Laravel 10 and 11 are past end of life and every
# remaining release carries unpatched advisories; without it Composer refuses to build those legs
# at all. It applies only to this dev tree — nothing here is installed into an application, and a
# consuming app resolves laravel/framework under its own policy.
set -euo pipefail

cd "$(dirname "$0")/.."

declare -A LARAVEL=([8.1]='^10.0' [8.2]='^11.0' [8.3]='^12.0')

versions=("$@")
[[ ${#versions[@]} -eq 0 ]] && versions=(8.1 8.2 8.3)

read -ra phpunit_args <<< "${PHPUNIT_ARGS:-}"

for php in "${versions[@]}"; do
    constraint="${LARAVEL[$php]:?unsupported PHP version: $php}"

    echo "==> PHP ${php} / Laravel ${constraint}"

    docker build -q -f Dockerfile.test --build-arg "PHP_VERSION=${php}" -t "aub-pay-test:${php}" .

    run() {
        docker run --rm \
            -e COMPOSER_POLICY_ADVISORIES_BLOCK=false \
            -v "$PWD":/pkg -w /pkg -u "$(id -u):$(id -g)" \
            "aub-pay-test:${php}" "$@"
    }

    # A lock file from another leg would be resolved for the wrong PHP, so each leg re-resolves.
    rm -rf vendor composer.lock

    run composer update --no-interaction --no-progress \
        --with="illuminate/support:${constraint}" \
        --with="illuminate/http:${constraint}"

    run vendor/bin/phpunit "${phpunit_args[@]}"
done
