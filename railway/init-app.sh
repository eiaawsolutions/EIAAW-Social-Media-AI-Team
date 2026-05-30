#!/usr/bin/env sh
#
# App-service Pre-Deploy command for Railway (the `app` service ONLY).
#
# WHY THIS EXISTS: migrations were never running automatically on deploy. The
# only `migrate` in composer.json is under `post-create-project-cmd`, which
# fires once at project scaffolding — never on `composer install` during a
# deploy. So pending migrations sat un-run until someone ran them by hand
# (this bit us on the Metricool migration deploy, 2026-05-30). This script is
# the deploy-time migration step, wired as the app service's Pre-Deploy Command:
#
#   chmod +x ./railway/init-app.sh && sh ./railway/init-app.sh
#
# It runs AFTER build, BEFORE the new version takes traffic, with the database
# reachable. A failed migration fails the deploy (the new version is not
# promoted) — which is what we want, not a silently half-migrated app.
#
# IMPORTANT — only the `app` service runs this. The `scheduler` and `worker`
# services keep their own start commands and do NOT migrate. Even so, migrate
# is run with `--isolated` (atomic advisory lock) so concurrent invocations
# can never race or double-apply a migration.

set -e

echo "[init-app] Running database migrations (isolated, forced)…"
php artisan migrate --force --isolated

echo "[init-app] Caching config, routes, events, views for production…"
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache

echo "[init-app] Pre-deploy complete."
