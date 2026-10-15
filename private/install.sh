#!/bin/bash
# Run automatically by Blueprint when the extension is installed (see
# scripts/commands/extensions/install.sh at the panel root), as $WEBUSER, with
# the cwd set to the panel root. Blueprint runs `php artisan config:cache` right
# after this script: no need to do it here.
#
# Variables provided by Blueprint: ENGINE, EXTENSION_IDENTIFIER, EXTENSION_TARGET,
# EXTENSION_VERSION, PTERODACTYL_DIRECTORY, BLUEPRINT_VERSION, BLUEPRINT_DEVELOPER.

set -euo pipefail

SOURCE="$PTERODACTYL_DIRECTORY/.blueprint/extensions/$EXTENSION_IDENTIFIER/private/config/addonbrowser.php"
TARGET="$PTERODACTYL_DIRECTORY/config/addonbrowser.php"

# Always refreshed, otherwise a new config key (e.g. modrinth_api_key) would
# never reach a panel where the addon is already installed. This file only
# holds env() calls, no secret and no install-specific setting: overwriting it
# loses nothing. The previous one is copied to .bak only if it differed (edited
# by hand).
if [[ -f "$TARGET" ]] && ! cmp -s "$SOURCE" "$TARGET"; then
  cp "$TARGET" "$TARGET.bak"
  echo "[addonbrowser] previous version saved to $TARGET.bak."
fi
cp "$SOURCE" "$TARGET"
echo "[addonbrowser] $TARGET deployed."
