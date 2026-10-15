#!/bin/bash
# Run automatically by Blueprint when the extension is removed (see
# scripts/commands/extensions/remove.sh at the panel root), as $WEBUSER, with
# the cwd set to the panel root. Blueprint runs `php artisan config:cache` right
# after this script: no need to do it here.
#
# Variables provided by Blueprint: ENGINE, EXTENSION_IDENTIFIER, EXTENSION_TARGET,
# EXTENSION_VERSION, PTERODACTYL_DIRECTORY, BLUEPRINT_VERSION.

set -euo pipefail

TARGET="$PTERODACTYL_DIRECTORY/config/addonbrowser.php"

if [[ -f "$TARGET" ]]; then
  rm -f "$TARGET"
  echo "[addonbrowser] $TARGET removed."
else
  echo "[addonbrowser] $TARGET already absent, nothing to do."
fi
