export type GameSlug = 'minecraft' | 'hytale' | 'palworld';
export type Platform = 'modrinth' | 'curseforge';

export interface GameConfigEntry {
    label: string;
    /** Platforms available for this game, the first one being the default.
     *  Most games have only one (CurseForge); Minecraft is the only one with
     *  both, mods and modpacks on each (see
     *  AddonBrowserController::resolvePlatform() and resolveCurseForgeClassId()
     *  on the backend). The target folder and allowed extensions are in
     *  gameConfig.json too, but only the backend reads them: addonbrowser only
     *  handles mods and modpacks, the frontend has nothing to choose. */
    platforms: Platform[];
}

class GameConfigService {
    private config: Record<string, GameConfigEntry> = require('./gameConfig.json');

    /**
     * Get a game's configuration entry. No fallback on a default entry: an
     * unrecognized game isn't supported, and AddonBrowserContainer only shows
     * the browser once resolve-game has confirmed a configured game
     * (supported = true) — so this service is never called with an unknown
     * game.
     */
    getGameConfig(game: GameSlug | string): GameConfigEntry {
        return this.config[(game as string).toLowerCase()];
    }

    /**
     * Get every platform available for a game (the first one is the default).
     */
    getAvailablePlatforms(game: GameSlug | string): Platform[] {
        return this.getGameConfig(game).platforms;
    }
}

export const gameConfigService = new GameConfigService();
export default gameConfigService;
