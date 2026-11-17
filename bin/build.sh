#!/usr/bin/env bash
# Build dist/brn-webhooks.zip from the current commit (same as the release workflow).
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p dist
git archive --format=zip --prefix=brn-webhooks/ -o dist/brn-webhooks.zip HEAD
echo "Built dist/brn-webhooks.zip ($(du -h dist/brn-webhooks.zip | cut -f1))"
