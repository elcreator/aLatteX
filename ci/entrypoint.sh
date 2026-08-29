#!/usr/bin/env bash
#
# Entrypoint of the local toolchain image (ci/Dockerfile).
#
# Commands:
#   test    build a CMS with this plugin (dev dependencies included) and run
#           the plugin's unit suite against it
#   build   build an installed, ready-to-serve CMS with this plugin, run the
#           smoke test, and write a zip into /out
#   serve   build the same tree and serve it on port 80 with php's own server,
#           so the plugin can be clicked through in a real manager
#   shell   drop into bash with everything on PATH
#
# If /evo-src is mounted, the CMS is built from that checkout. Otherwise it is
# cloned from EVO_REPO@EVO_REF (see ci/plugin.env).
#
set -euo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
build_dir=${EVO_BUILD_DIR:-/build}

compose_args=()
if [ -d /evo-src ]; then
    echo "Using the Evolution CMS checkout mounted at /evo-src"
    compose_args+=(--evo-src /evo-src)
else
    echo "No /evo-src mount; the CMS will be cloned"
fi

command=${1:-test}
shift || true

case "$command" in
    test)
        # --dev: the borrowed-core test mode runs on the core's Pest binary,
        # which is a dev dependency of the core.
        "$here/compose-evo.sh" "$build_dir" "${compose_args[@]}" --dev
        "$here/run-tests.sh" "$build_dir" "$@"
        ;;

    build)
        "$here/compose-evo.sh" "$build_dir" "${compose_args[@]}" --install
        "$here/smoke.sh" "$build_dir"
        mkdir -p /out
        name="evolution-cms-$(. "$here/plugin.env" && echo "$PLUGIN_SLUG")-sqlite.zip"
        ( cd "$build_dir" && rm -rf .git .github install core/tests core/phpunit.xml && zip -qr "/out/$name" . )
        ls -lh "/out/$name"
        ;;

    serve)
        "$here/compose-evo.sh" "$build_dir" "${compose_args[@]}" --install
        # php's built-in server has no rewrite engine, and the CMS's friendly
        # URLs cannot be emulated by a router script either: Core.php decides
        # between the alias and id lookup with filter_input(INPUT_GET, 'q'),
        # which reads the SAPI's original request and ignores anything a router
        # writes into $_GET or QUERY_STRING. So a served build would answer
        # every pretty URL with the site start page and no link would work.
        #
        # Turning friendly URLs off makes the CMS generate index.php?id=N links
        # instead, which this server can serve. Nothing else about the build
        # changes, and the shipped images - which run apache/nginx/frankenphp
        # with the real rewrite rules - are unaffected.
        db=$(ls "$build_dir"/core/database/*.sqlite 2>/dev/null | head -1)
        if [ -n "$db" ]; then
            php -r "
                \$pdo = new PDO('sqlite:' . \$argv[1]);
                \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                \$sql = 'UPDATE evo_system_settings SET setting_value = ? WHERE setting_name = ?';
                if (!\$pdo->prepare(\$sql)->execute(['0', 'friendly_urls'])) {
                    exit(1);
                }
                if (!\$pdo->query('SELECT changes()')->fetchColumn()) {
                    \$pdo->prepare('INSERT INTO evo_system_settings (setting_name, setting_value) VALUES (?, ?)')
                        ->execute(['friendly_urls', '0']);
                }
            " "$db"
            rm -f "$build_dir"/core/storage/bootstrap/siteCache.idx.php \
                  "$build_dir"/core/storage/bootstrap/*.pageCache.php
            echo "Friendly URLs disabled for the built-in server (see ci/entrypoint.sh)."
        fi

        echo
        echo "Site:    http://localhost:8080/"
        echo "Manager: http://localhost:8080/manager/  (admin / Passw0rd123)"
        php -S 0.0.0.0:80 -t "$build_dir"
        ;;

    shell)
        exec bash "$@"
        ;;

    *)
        echo "entrypoint.sh: unknown command '$command' (test|build|serve|shell)" >&2
        exit 2
        ;;
esac
