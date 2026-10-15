<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services;

use Illuminate\Support\Facades\Bus;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Jobs\WatchModpackInstallJob;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\AddonBrowserLogger;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\ModpackEggs;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\MinecraftJavaImages;
use Pterodactyl\BlueprintFramework\Extensions\eggswitch\Models\EggSwitchPendingReinstall;
use Pterodactyl\BlueprintFramework\Extensions\eggswitch\Support\EggWhitelist;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary as BlueprintExtensionLibrary;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Pure translation between "the user picked this modpack, this version" and
 * the call to eggswitch (the egg-switching addon) — see
 * architecture-addonbrowser-addon.md §6.
 *
 * Non-negotiable rule: this class re-implements NONE of the security checks
 * eggswitch already does (server ownership, whitelist, variable filtering,
 * node reachability, backup slot, double-operation guard) — it only resolves
 * which egg to target and builds the `environment` payload, then delegates
 * entirely to `EggSwitchRequestService::requestReinstallForAddon()`. If
 * eggswitch isn't installed on this panel (or is too old, see
 * MIN_EGGSWITCH_VERSION), this class must never fail other than with a clean
 * refusal — see isAvailable().
 */
class ModpackInstallService
{
    /**
     * Minimum eggswitch version: 1.1.0 is the first one to expose
     * `Support\EggWhitelist` (whitelist in the database + admin screen) — an
     * older version doesn't have this class at all, and calling it would crash
     * instead of cleanly disabling the integration.
     */
    private const MIN_EGGSWITCH_VERSION = '1.1.0';

    /** States of an eggswitch request as seen by the install watch (installState()). */
    public const STATE_PENDING = 'pending';

    public const STATE_LAUNCHED = 'launched';

    public const STATE_ABANDONED = 'abandoned';

    /**
     * True only if: an egg is mapped for this (game, platform), AND eggswitch
     * is installed in a compatible version, AND that egg is actually
     * whitelisted in eggswitch for addon calls. Doesn't depend on any specific
     * server — it's a panel-wide fact.
     */
    public function isAvailable(string $game, string $platform): bool
    {
        $eggId = ModpackEggs::eggIdFor($game, $platform);

        if ($eggId === null) {
            return false;
        }

        if (!$this->eggswitchIsCompatible()) {
            return false;
        }

        return EggWhitelist::isAllowedForAddon($eggId);
    }

    /**
     * Checks that eggswitch is installed AND in a version >= MIN_EGGSWITCH_VERSION,
     * through `BlueprintAdminLibrary::extensionConfig()` (see Blueprint's extension library documentation),
     * which reads the ACTUALLY installed `conf.yml`
     * (.blueprint/extensions/eggswitch/private/.store/conf.yml), not an assumed
     * number: calling `Support\EggWhitelist` on an older eggswitch install
     * would crash (missing class) instead of cleanly disabling the
     * integration.
     */
    private function eggswitchIsCompatible(): bool
    {
        $blueprint = app(BlueprintExtensionLibrary::class);

        if (!$blueprint->extension('eggswitch')) {
            return false;
        }

        $version = $blueprint->extensionConfig('eggswitch')['info']['version'] ?? null;

        return $version !== null && version_compare($version, self::MIN_EGGSWITCH_VERSION, '>=');
    }

    /**
     * The Docker images (Java versions) declared by the target egg for this
     * (game, platform), as is (label => image) — [] if no egg is
     * mapped/whitelisted or if the egg no longer exists. Read-only, triggers
     * nothing: used to fill a selector in the frontend before the install.
     *
     * @return array<string, string>
     */
    public function dockerImages(string $game, string $platform): array
    {
        $eggId = ModpackEggs::eggIdFor($game, $platform);

        if ($eggId === null || !$this->isAvailable($game, $platform)) {
            return [];
        }

        $egg = Egg::find($eggId);

        return $egg ? $egg->docker_images : [];
    }

    /**
     * Recommends a Docker image among the target egg's, from the RAW
     * game_versions array of a file/version picked in a Modrinth/CurseForge
     * search — it's this method, not the frontend, that guesses which
     * Minecraft version to use: the frontend only passes on the raw data
     * received from Modrinth/CurseForge, every decision stays here. The
     * version -> image mapping lives in MinecraftJavaImages, IN THIS ADDON
     * (see its header): it's up to addonbrowser, which already knows the
     * game/platform/modpack, to settle on a concrete choice and only pass an
     * already-decided `dockerImage` to
     * EggSwitchRequestService::requestReinstallForAddon() (see install()).
     * Returns null if no recommendation is possible (no recognized version in
     * the array, no matching image...) — the caller must then offer its own
     * default choice.
     *
     * @param string[] $gameVersions
     */
    public function recommendedDockerImage(string $game, string $platform, array $gameVersions): ?string
    {
        $images = $this->dockerImages($game, $platform);

        if ($images === []) {
            return null;
        }

        return MinecraftJavaImages::recommendFromGameVersions($images, $gameVersions);
    }

    /**
     * Triggers the install of the chosen modpack for $server, on behalf of
     * $user. $user must be the user actually authenticated for the current
     * request — never rebuilt from an id supplied by the caller.
     *
     * @throws HttpException if no egg is mapped/whitelisted for this
     *                        (game, platform).
     * @throws \Throwable any exception thrown by
     *                     EggSwitchRequestService::requestReinstallForAddon()
     *                     (ownership, whitelist, confirmation, node, backup,
     *                     double-operation guard) — never caught nor reworded
     *                     here, passed on as is to the caller.
     *
     * Last step, with no possible effect on the result: watching the install
     * for the installed-mods referential (watchInstall()).
     */
    public function install(
        User $user,
        Server $server,
        string $game,
        string $platform,
        string $projectId,
        string $versionNumber,
        string $confirmServerName,
        bool $withBackup,
        ?string $dockerImage = null,
        array $gameVersions = []
    ) {
        $eggId = ModpackEggs::eggIdFor($game, $platform);

        if ($eggId === null || !$this->isAvailable($game, $platform)) {
            throw new HttpException(422, "No modpack egg available for this game/platform.");
        }

        // Resolved HERE, in addonbrowser: eggswitch only ever receives an
        // already-decided Docker image (or nothing) — never a Minecraft version
        // to interpret, that would be Minecraft logic inside a generic
        // egg-switching mechanism. An explicit $dockerImage (the user's choice
        // in the dropdown, pre-filled by recommendedDockerImage() but editable)
        // always wins; otherwise the same recommendation is tried again here as
        // a last resort from $gameVersions (e.g. if the frontend couldn't reach
        // modpack-install-available before submitting).
        $resolvedDockerImage = $dockerImage ?? $this->recommendedDockerImage($game, $platform, $gameVersions);

        $pending = app(\Pterodactyl\BlueprintFramework\Extensions\eggswitch\Services\EggSwitchRequestService::class)
            ->requestReinstallForAddon(
                $user,
                $server,
                $eggId,
                $confirmServerName,
                $withBackup,
                $this->buildEnvironment($platform, $projectId, $versionNumber),
                $resolvedDockerImage
            );

        $this->watchInstall($pending, $server, $game, $platform);

        return $pending;
    }

    /**
     * Last step of install(): queues the install watch, which will read the
     * pack's manifest once the install is done to fill the installed-mods
     * referential. Only for a game whose referential accepts writes
     * (`installed_detection`). Never throws: a failure here has no effect on
     * the install, already started (or waiting for its backup) in eggswitch.
     */
    private function watchInstall(mixed $pending, Server $server, string $game, string $platform): void
    {
        try {
            if (! app(ModReferenceService::class)->acceptsGame($game) || ModpackEggs::manifestsFor($platform) === []) {
                return;
            }

            $requestedAt = $pending->requested_at ?? null;
            if (! is_int($pending->id ?? null) || ! $requestedAt instanceof \DateTimeInterface) {
                AddonBrowserLogger::warning('Modpack: unexpected eggswitch request, install not watched.', ['server' => $server->uuid]);

                return;
            }

            Bus::dispatch((new WatchModpackInstallJob(
                $pending->id,
                $server->uuid,
                strtolower(trim($platform)),
                $requestedAt->getTimestamp(),
            ))->delay(WatchModpackInstallJob::POLL_SECONDS));
        } catch (\Throwable $ex) {
            try {
                AddonBrowserLogger::warning('Modpack: install watch not dispatched.', ['server' => $server->uuid, 'error' => $ex->getMessage()]);
            } catch (\Throwable $ex) {
                // Nothing more to do.
            }
        }
    }

    /**
     * State of an eggswitch request, for WatchModpackInstallJob (the only
     * class that knows eggswitch):
     * - STATE_PENDING: `waiting_backup`, `ready_to_wipe`, `wiping`;
     * - STATE_LAUNCHED: `done`, which means "reinstall started by Wings", not
     *   "finished" (the server then says whether it finished);
     * - STATE_ABANDONED: `failed`, `cancelled`, request gone, unknown status,
     *   eggswitch missing or too old.
     * Never throws (error → STATE_ABANDONED).
     */
    public function installState(int $pendingId): string
    {
        try {
            if (! $this->eggswitchIsCompatible()) {
                return self::STATE_ABANDONED;
            }

            $status = EggSwitchPendingReinstall::query()->whereKey($pendingId)->value('status');

            return match ($status) {
                EggSwitchPendingReinstall::STATUS_WAITING_BACKUP,
                EggSwitchPendingReinstall::STATUS_READY_TO_WIPE,
                EggSwitchPendingReinstall::STATUS_WIPING => self::STATE_PENDING,
                EggSwitchPendingReinstall::STATUS_DONE => self::STATE_LAUNCHED,
                default => self::STATE_ABANDONED,
            };
        } catch (\Throwable $ex) {
            return self::STATE_ABANDONED;
        }
    }

    /**
     * Mapping (platform -> names of the variables the target egg expects).
     * Platform-specific implementation detail — precisely why this class
     * exists rather than letting the frontend know an egg's variable names.
     */
    private function buildEnvironment(string $platform, string $projectId, string $versionNumber): array
    {
        return match (strtolower(trim($platform))) {
            'modrinth' => [
                'MODRINTH_PROJECT_ID' => $projectId,
                // SERVER_JARFILE is deliberately NOT provided here: it's up to
                // eggswitch to apply the default_value declared by the target
                // egg for any variable not provided, not up to addonbrowser to
                // know/repeat that value — see
                // EggSwitchRequestService::handleReinstallRequest().
                // CAREFUL (see DEVELOPMENT.md, section "Installing a modpack"):
                // mrpack-install expects the Modrinth version_number (e.g.
                // "13.5.0"), NOT the API's internal id (e.g. "jmTldfTf") —
                // $versionNumber must therefore already be the version_number
                // field of a version returned by ModrinthService::getProjectVersions()
                // (GET /files), never its id.
                'MODRINTH_VERSION_ID' => $versionNumber,
            ],
            // CurseForge modpack egg (see Support\ModpackEggs) — expects the
            // numeric CurseForge fileId in VERSION_ID (the one used to build the
            // /mods/{id}/files/{fileId}/download-url URL in its script_install),
            // not a filename nor a displayName. $versionNumber must therefore be
            // the `id` field of a file returned by CurseForgeService::getModFiles(),
            // never `displayName`/`fileName` — same risk as on Modrinth, a
            // different platform behind a generic parameter name.
            // API_KEY is NOT provided here, same reasoning as SERVER_JARFILE
            // above: it's an admin-only variable (user_viewable/user_editable
            // = false on the egg), its default_value is applied by eggswitch.
            'curseforge' => [
                'PROJECT_ID' => $projectId,
                'VERSION_ID' => $versionNumber,
            ],
            default => [],
        };
    }
}
