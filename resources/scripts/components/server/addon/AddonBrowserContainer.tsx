import React, { useEffect, useState, useCallback, useRef, useMemo } from 'react';
import { ServerContext } from '@/state/server';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import http from '@/api/http';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faSearch, faSlidersH, faDownload, faHeart, faClock, faHdd, faCloudDownloadAlt, faSpinner, faCheck, faExclamationCircle, faTimes, faCube } from '@fortawesome/free-solid-svg-icons';
import debounce from 'lodash-es/debounce';
import { gameConfigService, type GameSlug, type Platform } from './gameConfig';
import type { InstalledModFile, InstalledModsResponse, UnifiedProject, UnifiedVersion } from './types';
import { extractErrorMessage } from './errors';

// <select> options shared by every dropdown on this page (Browse by,
// Category, Loader, Sort, Game Version, Java...): a native <select> rather
// than a custom dropdown component — simpler, accessible by default
// (keyboard, screen readers), and a single class (addonbrowser-select) for a
// theme to handle instead of a dedicated design system.
interface SelectOption {
    id: string;
    name: string;
}

const AddonSelect = ({
    value,
    options,
    onChange,
    className = '',
}: {
    value: string;
    options: SelectOption[];
    onChange: (val: string) => void;
    className?: string;
}) => (
    <select
        className={`addonbrowser-select w-full bg-neutral-900 border border-neutral-700 text-neutral-300 text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-indigo-500 ${className}`}
        value={value}
        onChange={(e) => onChange(e.target.value)}
    >
        {options.map((o) => (
            <option key={o.id} value={o.id}>
                {o.name}
            </option>
        ))}
    </select>
);

// Tab button (Modrinth/CurseForge platform, Mods/Modpacks mode) — the
// "active" color is a third-party brand color (Modrinth green, CurseForge
// orange) passed as is, never taken over by a theme (same logic as the game
// icons: a brand color isn't themed).
const tabBaseClass =
    'addonbrowser-tab px-4 py-2 rounded-lg font-medium text-sm transition-all flex items-center gap-2';

const tabInactiveClass = 'bg-neutral-800 text-neutral-400 border border-neutral-700 hover:bg-neutral-700';

// Calling GET /installed again during a hash-based verification: every 10 s,
// for 5 min at most since the start of the series.
const PENDING_POLL_INTERVAL_MS = 10000;
const PENDING_POLL_MAX_MS = 5 * 60 * 1000;

const tabActiveStyle = (color: string): React.CSSProperties => ({
    background: color,
    color: 'white',
    borderColor: color,
});

export default () => {
    const server = ServerContext.useStoreState((state: any) => state.server.data);
    const [platform, setPlatform] = useState<Platform>('modrinth');
    const [game, setGame] = useState<GameSlug>('minecraft');
    // Until resolve-game has confirmed a configured game ('supported'), no
    // browsing request is made and the browser isn't shown: an unrecognized
    // nest isn't supported (no `default` entry), and a resolution error never
    // silently falls back on Minecraft.
    const [gameStatus, setGameStatus] = useState<'resolving' | 'supported' | 'unsupported' | 'error'>('resolving');
    const [gameError, setGameError] = useState<string | null>(null);
    const [query, setQuery] = useState('');
    const [filters, setFilters] = useState({ category: '', classId: '', loader: '', sort: 'relevance', gameVersion: '' });
    // "Game Version" filter: list of the game's versions (empty = no filter
    // for this game) and the version detected on the server. In mods mode,
    // that version is pre-selected, never forced: the user stays free, and
    // gameVersionTouched prevents overwriting it once they've made
    // their own choice. In modpacks mode, no default value.
    const [gameVersions, setGameVersions] = useState<string[]>([]);
    const [serverGameVersion, setServerGameVersion] = useState<string | null>(null);
    const gameVersionTouched = useRef(false);
    // Same principle for the loader: the server's is pre-selected in mods
    // mode if it's in the platform's menu, never forced.
    const [serverLoader, setServerLoader] = useState<string | null>(null);
    const loaderTouched = useRef(false);
    const [projects, setProjects] = useState<UnifiedProject[]>([]);
    const [loading, setLoading] = useState(false);
    const [showFilters, setShowFilters] = useState(false);
    const [totalHits, setTotalHits] = useState(0);
    const [page, setPage] = useState(0);
    // Counter incremented on every search started: used to ignore the response
    // of a request that became stale (arriving after a more recent search), so
    // an outdated result (previous game/filter) never overwrites the current one.
    const searchRequestId = useRef(0);

    const [selectedProject, setSelectedProject] = useState<UnifiedProject | null>(null);
    const [versions, setVersions] = useState<UnifiedVersion[]>([]);
    // showPreReleases: releases only by default; beta/alpha versions only
    // show up when the box is checked.
    const [versionFilters, setVersionFilters] = useState({ gameVersion: '', loader: '', showPreReleases: false });
    const [loadingVersions, setLoadingVersions] = useState(false);
    // Without a game version filter, CurseForge only returns the 50 most recent files.
    const [versionsTruncated, setVersionsTruncated] = useState(false);
    const versionsRequestId = useRef(0);

    // --- Modpack installs (see DEVELOPMENT.md) ---
    // browsingModpacks: "browse whole modpacks" mode rather than "mods one by
    // one". Addonbrowser only offers mods and modpacks: no other project type
    // to choose.
    const [modpackAvailable, setModpackAvailable] = useState(false);
    const [browsingModpacks, setBrowsingModpacks] = useState(false);
    const [modpackCategoryOptions, setModpackCategoryOptions] = useState<{ id: string; name: string }[]>([]);
    const [backupLimit, setBackupLimit] = useState(0);
    const [backupUsed, setBackupUsed] = useState(0);
    const [withBackup, setWithBackup] = useState(false);
    const [installVersion, setInstallVersion] = useState<UnifiedVersion | null>(null);
    const [installConfirmName, setInstallConfirmName] = useState('');
    const [installing, setInstalling] = useState(false);
    const [installError, setInstallError] = useState<string | null>(null);
    const [installSuccess, setInstallSuccess] = useState(false);
    // Java version (Docker image) selector for the current install — filled
    // when the confirmation dialog opens (see openInstallDialog()), pre-filled
    // with the backend's recommendation based on the chosen modpack's
    // Minecraft version, but editable by the server owner before confirming.
    const [installDockerImages, setInstallDockerImages] = useState<{ id: string; name: string }[]>([]);
    const [installDockerImage, setInstallDockerImage] = useState('');
    // RAW game_versions of the chosen version, as received from
    // Modrinth/CurseForge — passed as is to the backend
    // (modpack-install-available then install-modpack): IT decides which
    // Minecraft version to take from it, never the frontend (see
    // MinecraftJavaImages::guessVersion() on the backend).
    const [installGameVersions, setInstallGameVersions] = useState<string[]>([]);

    const hasBackupSlot = backupLimit > 0 && backupUsed < backupLimit;

    // --- Mods already installed (GET /installed, installed-mods detection) ---
    // Last valid response from the backend, or null until one has arrived
    // (first load failed: 503 node unreachable, 429… → no badge, the tab stays
    // usable; see refreshInstalled()). The backend recomputes everything from
    // the folder's actual listing on every call; nothing is inferred here
    // beyond comparing ids.
    const [installedMods, setInstalledMods] = useState<InstalledModsResponse | null>(null);
    const installedRequestId = useRef(0);
    // Incremented at the end of each /installed call (successful or not):
    // reschedules the next call during a verification (see below).
    const [installedTick, setInstalledTick] = useState(0);

    useEffect(() => {
        if (!server?.uuid) return;

        http.get(`/extensions/addonbrowser/resolve-game`, {
            params: { serverUuid: server.uuid },
        })
            .then(response => {
                const resolvedGame = response.data?.game as GameSlug | null | undefined;
                if (!resolvedGame || !response.data?.supported) {
                    setGameStatus('unsupported');
                    return;
                }
                setGame(resolvedGame);
                setBackupLimit(response.data?.backup_limit ?? 0);
                setBackupUsed(response.data?.backup_used ?? 0);
                setGameStatus('supported');
            })
            .catch(e => {
                console.error('Failed to resolve game for server:', e);
                setGameError(extractErrorMessage(e));
                setGameStatus('error');
            });
    }, [server?.uuid]);

    const [availableLoaderOptions, setAvailableLoaderOptions] = useState<string[]>([]);
    const [browseByOptions, setBrowseByOptions] = useState<{ id: string; name: string }[]>([]);
    const [categoryOptions, setCategoryOptions] = useState<{ id: string; name: string; parentId: string }[]>([]);

    // The backend is the only authority on this availability (egg mapping +
    // eggswitch whitelist) — the modpacks mode is just shown (or not) based on
    // this boolean, no availability logic on the client beyond that.
    useEffect(() => {
        if (gameStatus !== 'supported') return;

        http.get('/extensions/addonbrowser/modpack-install-available', { params: { game, platform } })
            .then(({ data }) => setModpackAvailable(!!data?.available))
            .catch(() => setModpackAvailable(false));
    }, [game, platform, gameStatus]);

    useEffect(() => {
        if (!browsingModpacks || gameStatus !== 'supported') return;

        http.get('/extensions/addonbrowser/categories', { params: { game, platform, project_type: 'modpack' } })
            .then(res => {
                const all = Array.isArray(res.data?.data) ? res.data.data : [];
                setModpackCategoryOptions(all.map((c: any) => ({ id: c.id, name: c.name })));
            })
            .catch(() => setModpackCategoryOptions([]));
    }, [browsingModpacks, game, platform, gameStatus]);

    useEffect(() => {
        if (gameStatus !== 'supported') return;

        http.get(`/extensions/addonbrowser/loaders`, {
            params: { game, platform },
        })
            .then(res => setAvailableLoaderOptions(Array.isArray(res.data) ? res.data : []))
            .catch(() => setAvailableLoaderOptions([]));
    }, [game, platform, gameStatus]);


    useEffect(() => {
        if (gameStatus !== 'supported') return;

        http.get('/extensions/addonbrowser/game-versions', { params: { game } })
            .then(res => setGameVersions(Array.isArray(res.data?.data) ? res.data.data : []))
            .catch(() => setGameVersions([]));
    }, [game, gameStatus]);

    // Detection of the server's game version and loader: a call separate from
    // resolve-game, so that a slow or unreachable node blocks nothing (the
    // filters then stay on "All").
    useEffect(() => {
        if (gameStatus !== 'supported' || !server?.uuid) return;

        http.get('/extensions/addonbrowser/server-environment', { params: { serverUuid: server.uuid } })
            .then(res => {
                setServerGameVersion(res.data?.version || null);
                setServerLoader(res.data?.loader || null);
            })
            .catch(() => {
                setServerGameVersion(null);
                setServerLoader(null);
            });
    }, [server?.uuid, gameStatus]);

    // Installed mods: when the tab opens (once the game is confirmed) and after
    // each successful download (see downloadVersion()). A response older than a
    // later call is ignored.
    const refreshInstalled = useCallback(() => {
        if (!server?.uuid) return;
        const requestId = ++installedRequestId.current;

        http.get('/extensions/addonbrowser/installed', { params: { serverUuid: server.uuid } })
            .then(({ data }) => {
                if (requestId !== installedRequestId.current) return;
                if (data && typeof data === 'object' && typeof data.enabled === 'boolean') {
                    setInstalledMods(data as InstalledModsResponse);
                }
            })
            .catch(() => {
                // A refresh failed (429, 503…): the last valid response is KEPT
                // (the badges stay). Only a failure on the first load leaves the
                // tab without badges (installedMods stays null). The call every
                // 10 s relies on this behavior (a passing 429 doesn't make the
                // badges flicker).
            })
            .finally(() => {
                // End of a call (successful or not): reschedules the next call
                // below, even if the response changed nothing.
                if (requestId === installedRequestId.current) setInstalledTick(t => t + 1);
            });
    }, [server?.uuid]);

    useEffect(() => {
        if (gameStatus !== 'supported') return;
        refreshInstalled();
    }, [gameStatus, refreshInstalled]);

    // Hash-based verification running on the server (pending_verification > 0):
    // GET /installed called again every 10 s (chained setTimeout: one call at a
    // time, the next one is only scheduled once the previous one is done).
    // Stops when the counter is back to 0, when the tab is left (unmount), or
    // after 5 min since the start of the series.
    // No calls in Modpacks mode (no badge is shown there); they resume when
    // going back to Mods mode, within the same series. Once the series has
    // stopped (5 min), the icon is hidden: the displayed counter is no longer
    // up to date.
    const pendingSeriesStart = useRef<number | null>(null);
    const [pendingPollExpired, setPendingPollExpired] = useState(false);
    const pendingVerification = installedMods?.enabled && installedMods.compatible ? installedMods.pending_verification || 0 : 0;
    useEffect(() => {
        if (pendingVerification <= 0) {
            pendingSeriesStart.current = null;
            setPendingPollExpired(false);
            return;
        }
        if (browsingModpacks) return;
        if (pendingSeriesStart.current === null) pendingSeriesStart.current = Date.now();
        if (Date.now() - pendingSeriesStart.current >= PENDING_POLL_MAX_MS) {
            setPendingPollExpired(true);
            return;
        }

        const timer = setTimeout(refreshInstalled, PENDING_POLL_INTERVAL_MS);
        return () => clearTimeout(timer);
    }, [pendingVerification, installedTick, refreshInstalled, browsingModpacks]);
    const showPendingIcon = !browsingModpacks && pendingVerification > 0 && !pendingPollExpired;

    // Installed files by project id, for each platform (ids compared as text:
    // CurseForge returns numbers, the cards have strings).
    const installedIndex = useMemo(() => {
        const index: Record<Platform, Map<string, InstalledModFile[]>> = { modrinth: new Map(), curseforge: new Map() };
        if (!installedMods?.enabled || !installedMods.compatible || !Array.isArray(installedMods.installed)) return index;

        const add = (platformKey: Platform, id: string | number | null | undefined, file: InstalledModFile) => {
            if (id === null || id === undefined || id === '') return;
            const key = String(id);
            index[platformKey].set(key, [...(index[platformKey].get(key) || []), file]);
        };
        installedMods.installed.forEach(file => {
            add('modrinth', file.modrinth_project_id, file);
            add('curseforge', file.curseforge_project_id, file);
        });
        return index;
    }, [installedMods]);

    // Installed files of a project, for the displayed platform. Never in
    // Modpacks mode: a modpack isn't a file of the mods folder.
    const installedFilesFor = (project: UnifiedProject | null): InstalledModFile[] =>
        !project || browsingModpacks ? [] : installedIndex[project.platform].get(String(project.id)) || [];

    // Server without a mods folder: a message instead of the badges, in Mods mode.
    const modsIncompatible = !browsingModpacks && !!installedMods?.enabled && installedMods.compatible === false;

    // Detection arrives after the first render: the version is pre-selected only
    // in mods mode, if nothing is chosen yet and if the user hasn't touched the
    // filter already.
    useEffect(() => {
        if (serverGameVersion && !browsingModpacks && !gameVersionTouched.current) {
            setFilters(prev => (prev.gameVersion ? prev : { ...prev, gameVersion: serverGameVersion }));
        }
    }, [serverGameVersion]);

    // Loader: pre-selected as soon as the detection AND the platform's menu are
    // loaded (both arrive after the first render), in mods mode, if the user
    // hasn't made their own choice and if that loader is offered.
    useEffect(() => {
        if (serverLoader && !browsingModpacks && !loaderTouched.current && availableLoaderOptions.includes(serverLoader)) {
            setFilters(prev => (prev.loader ? prev : { ...prev, loader: serverLoader }));
        }
    }, [serverLoader, availableLoaderOptions, browsingModpacks]);

    // Mod categories, for both platforms, read from the backend. CurseForge can
    // also return classes (isClass), which feed "Browse by" when a game has
    // several (Palworld); Modrinth has none.
    useEffect(() => {
        if (gameStatus !== 'supported') return;

        http.get(`/extensions/addonbrowser/categories`, { params: { game, platform } })
            .then(res => {
                const all = Array.isArray(res.data?.data) ? res.data.data : [];

                const classes = all
                    .filter((c: any) => c.isClass)
                    .map((c: any) => ({ id: c.id.toString(), name: c.name }));

                const subcats = all
                    .filter((c: any) => !c.isClass)
                    .map((c: any) => ({ id: c.id.toString(), name: c.name, parentId: c.parentCategoryId?.toString() }));

                setBrowseByOptions(classes);
                setCategoryOptions(subcats);
            })
            .catch(() => {
                setBrowseByOptions([]);
                setCategoryOptions([]);
            });
    }, [game, platform, gameStatus]);


    const searchBackend = async (q: string, offset: number): Promise<{ projects: UnifiedProject[], total: number }> => {

        try {
            const response = await http.get(`/extensions/addonbrowser/search`, {
                params: {
                    game,
                    platform,
                    query: q.trim() || undefined,
                    classId: filters.classId || undefined,
                    categoryId: filters.category || undefined,
                    // The CurseForge loader (modLoaderType) only exists for
                    // Minecraft (see CurseForgeService::MOD_LOADER_TYPES), and only
                    // outside modpacks mode (as for Modrinth) — never sent for the
                    // other CurseForge games (Hytale, Palworld), and ignored by the
                    // backend anyway if sent outside Minecraft.
                    loader: !browsingModpacks ? (filters.loader || undefined) : undefined,
                    sort: filters.sort,
                    gameVersion: filters.gameVersion || undefined,
                    offset: offset.toString(),
                    limit: '12',
                    // `mod` or `modpack`: search facet on Modrinth, choice of the
                    // enforced class on CurseForge (see
                    // AddonBrowserController::resolveCurseForgeClassId()).
                    project_type: browsingModpacks ? 'modpack' : 'mod',
                },
            });

            const data = response.data;

            // Handle both Modrinth and CurseForge response formats
            if (platform === 'modrinth') {
                const hits = Array.isArray(data?.hits) ? data.hits : [];
                const projects: UnifiedProject[] = hits.map((p: any) => ({
                    id: p.project_id,
                    title: p.title,
                    description: p.description,
                    author: p.author,
                    downloads: p.downloads,
                    icon_url: p.icon_url,
                    categories: p.categories,
                    date_modified: p.date_modified,
                    platform: 'modrinth' as Platform,
                }));

                return { projects, total: Number(data?.total_hits ?? 0) };
            } else {
                // CurseForge format
                const mods = Array.isArray(data?.data) ? data.data : [];
                const projects: UnifiedProject[] = mods.map((m: any) => ({
                    id: m.id.toString(),
                    title: m.name,
                    description: m.summary,
                    author: m.authors?.[0]?.name || 'Unknown',
                    downloads: m.downloadCount || 0,
                    icon_url: m.logo?.thumbnailUrl || '',
                    categories: m.categories?.map((c: any) => c.name) || [],
                    date_modified: m.dateModified || new Date().toISOString(),
                    platform: 'curseforge' as Platform,
                }));

                const pagination = data?.pagination || {};
                return {
                    projects,
                    total: pagination.totalCount || 0
                };
            }
        } catch (e) {
            console.error('Backend search error:', e);
            throw e;
        }
    };

    const searchProjects = useCallback(async (q: string, offset: number) => {
        const requestId = ++searchRequestId.current;
        setLoading(true);
        try {
            const result = await searchBackend(q, offset);
            // A more recent search started in the meantime: this stale response
            // is dropped rather than overwriting a more up-to-date result.
            if (requestId !== searchRequestId.current) return;
            setProjects(result.projects);
            setTotalHits(result.total);
        } catch (e) {
            console.error(e);
            if (requestId === searchRequestId.current) setProjects([]);
        } finally {
            if (requestId === searchRequestId.current) setLoading(false);
        }
    }, [platform, filters, game, browsingModpacks]);

    const debouncedSearch = useCallback(debounce((q: string) => {
        setPage(0);
        searchProjects(q, 0);
    }, 600), [searchProjects]);

    // searchProjects (hence debouncedSearch) is recreated on every
    // game/platform/filter change: without this, the pending previous debounce
    // is never cancelled and can fire later with stale state, causing a ghost
    // search on top of the right one (a "double load").
    useEffect(() => {
        return () => debouncedSearch.cancel();
    }, [debouncedSearch]);

    useEffect(() => {
        if (gameStatus !== 'supported') return;
        debouncedSearch(query);
    }, [query, filters, platform, game, browsingModpacks, gameStatus]);

    // Only resets the platform if the current one isn't offered by the new game
    // (e.g. Hytale/Palworld only have CurseForge) — otherwise a user who chose
    // CurseForge for Minecraft would have it overwritten on every re-run of this
    // effect, while Minecraft does offer both (see gameConfig.json).
    useEffect(() => {
        const available = gameConfigService.getAvailablePlatforms(game);
        if (!available.includes(platform)) {
            setPlatform(available[0]);
        }
    }, [game]);

    // Explicit page change (not an effect on `page`, which would rerun nothing
    // when going back to page 1 if the search were limited to pages > 0). The
    // initial search and those triggered by a filter always go through
    // debouncedSearch, which resets the page to 0 itself.
    const goToPage = (target: number) => {
        setPage(target);
        searchProjects(query, target * 12);
    };

    useEffect(() => {
        gameVersionTouched.current = false;
        loaderTouched.current = false;
        setFilters({
            category: '',
            classId: '',
            loader: '',
            sort: platform === 'modrinth' ? 'relevance' : 'popularity',
            // Mods: the server's version pre-selected; modpacks: none.
            gameVersion: !browsingModpacks && serverGameVersion ? serverGameVersion : '',
        });
        setQuery('');
        setPage(0);
    }, [platform, game, browsingModpacks]);

    // Leaves modpacks mode automatically if the backend says it's no longer
    // available for this (game, platform) — the only authority, see the effect
    // that fills modpackAvailable above.
    useEffect(() => {
        if (!modpackAvailable && browsingModpacks) {
            setBrowsingModpacks(false);
        }
    }, [modpackAvailable, browsingModpacks]);

    // Opening the versions window: filters pre-filled from the search (game
    // version, loader), releases only by default. The loading itself is done by
    // the effect just below, which reruns when the game version changes: that
    // version is filtered by the API, not in the browser, so older versions are
    // never missed.
    const loadVersions = (project: UnifiedProject) => {
        setVersions([]);
        setVersionFilters({
            gameVersion: filters.gameVersion,
            loader: filters.loader,
            showPreReleases: false,
        });
        setSelectedProject(project);
    };

    const fetchVersions = async (project: UnifiedProject, gameVersion: string) => {
    const requestId = ++versionsRequestId.current;
    setLoadingVersions(true);

    try {
        if (project.platform === 'modrinth') {
            const response = await http.get(`/extensions/addonbrowser/files`, {
                params: { modId: project.id, platform: 'modrinth', gameVersion: gameVersion || undefined },
            });
            if (requestId !== versionsRequestId.current) return;
            const data: any[] = Array.isArray(response.data) ? response.data : [];
            setVersionsTruncated(false);

            const versions: UnifiedVersion[] = data.map((v: any) => ({
                id: v.id,
                name: v.name || v.version_number,
                version_number: v.version_number,
                version_type: v.version_type || 'release',
                date_published: v.date_published,
                downloads: v.downloads || 0,
                files: (v.files || []).map((f: any) => ({
                    url: f.url,
                    filename: f.filename,
                    primary: !!f.primary,
                    size: f.size || 0,
                })),
                game_versions: v.game_versions || [],
                loaders: v.loaders || [],
            }));

            setVersions(versions);
        } else if (project.platform === 'curseforge') {
            const response = await http.get(`/extensions/addonbrowser/files`, {
                params: { modId: project.id, platform: 'curseforge', gameVersion: gameVersion || undefined },
            });
            if (requestId !== versionsRequestId.current) return;
            const data = response.data?.data || [];
            setVersionsTruncated(!!response.data?.pagination?.truncated);

            // CurseForge mixes into gameVersions real Minecraft versions
            // ("1.21.9"), the loader ("Fabric", "Forge"...) and non-game tags
            // ("Client", "Server") — seen on a real Fabric API file
            // (["Client", "Fabric", "1.21.8", ...]).
            // They're split here so that game_versions/loaders mean the same
            // thing as on Modrinth, otherwise the loader filter (kept from the
            // search, see loadVersions() above) would never match anything.
            // Without 'cauldron' (hybrid Forge + Bukkit server, tied to plugins):
            // same list as CurseForgeService::MOD_LOADER_TYPES on the backend.
            const CURSEFORGE_LOADER_NAMES = ['forge', 'liteloader', 'fabric', 'quilt', 'neoforge'];
            const isNumericVersion = (v: string) => /^\d{1,4}(\.\d{1,4}){0,2}$/.test(v);

            const versions: UnifiedVersion[] = data.map((f: any) => {
                const tags: string[] = f.gameVersions || [];
                return {
                    id: f.id.toString(),
                    name: f.displayName || f.fileName,
                    // CurseForge has no notion of a version_number separate from its
                    // id — here version_number IS the id (numeric fileId), not
                    // displayName/fileName: that fileId is what the CurseForge
                    // modpack egg expects in VERSION_ID to build the download URL
                    // (see ModpackInstallService::buildEnvironment() on the backend).
                    version_number: f.id.toString(),
                    version_type: f.releaseType === 1 ? 'release' : f.releaseType === 2 ? 'beta' : 'alpha',
                    date_published: f.fileDate,
                    downloads: f.downloadCount || 0,
                    files: [{
                        url: f.downloadUrl,
                        filename: f.fileName,
                        primary: true,
                        size: f.fileLength || 0,
                    }],
                    game_versions: tags.filter(isNumericVersion),
                    loaders: tags.filter(t => CURSEFORGE_LOADER_NAMES.includes(t.toLowerCase())).map(t => t.toLowerCase()),
                };
            });

            setVersions(versions);
        }
        } catch (e) {
            console.error(e);
        } finally {
            if (requestId === versionsRequestId.current) setLoadingVersions(false);
        }
    };

    useEffect(() => {
        if (selectedProject) {
            fetchVersions(selectedProject, versionFilters.gameVersion);
        }
    }, [selectedProject, versionFilters.gameVersion]);

    // Sorts offered per platform (translated by the backend, see
    // AddonBrowserController::SORT_MODRINTH / SORT_CURSEFORGE). CurseForge has
    // no relevance sort: its first choice is popularity.
    const sortOptions = platform === 'modrinth'
        ? [
            { id: 'relevance', name: 'Relevance' },
            { id: 'downloads', name: 'Downloads' },
            { id: 'follows', name: 'Popularity' },
            { id: 'newest', name: 'Newest' },
            { id: 'updated', name: 'Updated' },
        ]
        : [
            { id: 'popularity', name: 'Popularity' },
            { id: 'downloads', name: 'Downloads' },
            { id: 'newest', name: 'Newest' },
            { id: 'updated', name: 'Updated' },
        ];

    // State of each version's download button (key: platform, project,
    // version), held by React (never rewritten directly in the DOM: the label
    // also depends on the "Installed" badges). "Success" and "Error" stay
    // displayed for 3 s, whatever /installed refreshes happen meanwhile.
    const [downloadStates, setDownloadStates] = useState<Record<string, 'downloading' | 'success' | 'error'>>({});
    const downloadTimers = useRef<ReturnType<typeof setTimeout>[]>([]);
    useEffect(() => () => downloadTimers.current.forEach(clearTimeout), []);

    const downloadKey = (project: UnifiedProject, version: UnifiedVersion) => `${project.platform}:${project.id}:${version.id}`;

    const downloadVersion = async (key: string, file: UnifiedVersion['files'][0]) => {
        // Neither during the download nor during the 3 s of "Success": a click
        // on the green check mark would start a full download again.
        if (!server || downloadStates[key] === 'downloading' || downloadStates[key] === 'success') return;

        const setState = (state: 'downloading' | 'success' | 'error' | null) =>
            setDownloadStates(prev => {
                const next = { ...prev };
                if (state === null) delete next[key];
                else next[key] = state;
                return next;
            });
        // Back to the normal state after 3 s, only if nothing else changed
        // this version's state in the meantime.
        const resetLater = (from: 'success' | 'error') => {
            downloadTimers.current.push(setTimeout(() => {
                setDownloadStates(prev => {
                    if (prev[key] !== from) return prev;
                    const next = { ...prev };
                    delete next[key];
                    return next;
                });
            }, 3000));
        };

        setState('downloading');
        try {
            await http.post(`/extensions/addonbrowser/download`, {
                // No `filename`: the backend derives it from the URL (never from a
                // parameter sent by the browser, see AddonBrowserController::download()).
                downloadUrl: file.url,
                serverUuid: server.uuid,
            });
            setState('success');
            resetLater('success');
            // Up-to-date badges. A mod the referential has never seen is only
            // recognized once the recording job has run (`low` queue): it may
            // only show up on the tab's next load.
            refreshInstalled();
        } catch (e: any) {
            console.error(e);
            setState('error');
            resetLater('error');
            alert('Failed to download: ' + extractErrorMessage(e));
        }
    };

    // Opens the destructive confirmation dialog — never a direct install on a
    // click on "Install this modpack".
    const openInstallDialog = (version: UnifiedVersion) => {
        setInstallVersion(version);
        setInstallConfirmName('');
        setInstallError(null);
        setInstallSuccess(false);
        setInstallDockerImages([]);
        setInstallDockerImage('');
        setInstallGameVersions(version.game_versions);

        if (!selectedProject) return;

        // Queries modpack-install-available again with this version's RAW
        // game_versions array: the backend decides itself which Minecraft version
        // to take from it and returns the target egg's images + its
        // recommendation (see AddonBrowserController::modpackInstallAvailable(),
        // shared with ModpackInstallService/MinecraftJavaImages) — never
        // interpreted on the client.
        http.get('/extensions/addonbrowser/modpack-install-available', {
            params: { game, platform: selectedProject.platform, gameVersions: version.game_versions },
        })
            .then((response: any) => {
                const data = response.data;
                const images = Array.isArray(data?.docker_images) ? data.docker_images : [];
                setInstallDockerImages(images.map((img: any) => ({ id: img.id, name: img.label })));
                setInstallDockerImage(data?.recommended_image || images[0]?.id || '');
            })
            .catch(() => {
                setInstallDockerImages([]);
                setInstallDockerImage('');
            });
    };

    const installModpack = async () => {
        if (!server || !selectedProject || !installVersion) return;
        if (installConfirmName !== server.name) {
            setInstallError("The name you typed doesn't match the server's name.");
            return;
        }

        setInstalling(true);
        setInstallError(null);

        try {
            await http.post('/extensions/addonbrowser/install-modpack', {
                serverUuid: server.uuid,
                platform: selectedProject.platform,
                projectId: selectedProject.id,
                // The exact format depends on the selected project's platform — see
                // the UnifiedVersion interface and ModpackInstallService on the
                // backend (Modrinth version_number vs CurseForge fileId).
                versionNumber: installVersion.version_number,
                confirm_server_name: installConfirmName,
                with_backup: withBackup && hasBackupSlot,
                // The server owner's Java choice (pre-filled by a recommendation,
                // see openInstallDialog()) — checked again by eggswitch anyway
                // (EggSwitchRequestService::resolveDockerImage()).
                dockerImage: installDockerImage || undefined,
                // RAW game_versions, as a fallback if dockerImage is missing — it's
                // ModpackInstallService that decides what to do with it, never this component.
                gameVersions: installGameVersions,
            });
            setInstallSuccess(true);
        } catch (e: any) {
            // Shown as is, without rewording — these are eggswitch's own
            // messages (whitelist, node unreachable, backup slot, operation
            // already in progress...).
            setInstallError(extractErrorMessage(e));
        } finally {
            setInstalling(false);
        }
    };

    const isShownType = (v: UnifiedVersion) => versionFilters.showPreReleases || v.version_type === 'release';

    const filteredVersions = versions.filter(v => {
        if (versionFilters.gameVersion && !v.game_versions.includes(versionFilters.gameVersion)) return false;
        if (versionFilters.loader && !v.loaders.includes(versionFilters.loader)) return false;
        return isShownType(v);
    });

    // Installed files of the versions window's project (empty in Modpacks mode).
    const projectInstalledFiles = installedFilesFor(selectedProject);

    // Hidden beta/alpha versions that would match the other filters: used to
    // explain an empty list (a mod that only has pre-releases).
    const hiddenPreReleaseCount = versionFilters.showPreReleases ? 0 : versions.filter(v =>
        v.version_type !== 'release' &&
        (!versionFilters.gameVersion || v.game_versions.includes(versionFilters.gameVersion)) &&
        (!versionFilters.loader || v.loaders.includes(versionFilters.loader))
    ).length;

    // Game versions sorted from newest to oldest, numerically (an alphabetical
    // sort would put 1.9 before 1.21).
    const compareGameVersions = (a: string, b: string): number => {
        const pa = a.split('.').map(n => parseInt(n, 10) || 0);
        const pb = b.split('.').map(n => parseInt(n, 10) || 0);
        for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
            const d = (pb[i] ?? 0) - (pa[i] ?? 0);
            if (d !== 0) return d;
        }
        return 0;
    };

    // The window's "Game Version" menu: the game's full list when it exists
    // (already from newest to oldest), otherwise the versions present in the
    // loaded files, sorted. The chosen version is filtered by the API (see
    // fetchVersions()), not here.
    const modalGameVersionOptions = useMemo(() => {
        if (gameVersions.length > 0) return gameVersions;
        return [...new Set(versions.flatMap(v => v.game_versions))].sort(compareGameVersions);
    }, [gameVersions, versions]);

    // "Loader" menu: only the loaders that actually exist given the other
    // filters (game version, pre-releases) — without this, a combination that
    // exists in no version can be chosen, ending on "No versions found" without
    // understanding why. Computed from `versions`, not from `filteredVersions`
    // (which already excludes the chosen loader itself).
    const availableLoaders = useMemo(() => [...new Set(
        versions
            .filter(v =>
                (!versionFilters.gameVersion || v.game_versions.includes(versionFilters.gameVersion)) &&
                (versionFilters.showPreReleases || v.version_type === 'release')
            )
            .flatMap(v => v.loaders)
    )].sort(), [versions, versionFilters.gameVersion, versionFilters.showPreReleases]);

    // The chosen loader is never reset automatically: otherwise the shared
    // filter would be cleared every time the window opens (list still empty
    // while loading), or as soon as a mod doesn't exist for that loader. The
    // filter therefore stays applied; if it has no version, the menu still
    // shows it, marked "(no version)", and the empty list explains it.
    const selectedLoaderUnavailable = !!versionFilters.loader && !loadingVersions && !availableLoaders.includes(versionFilters.loader);
    const modalLoaderOptions = versionFilters.loader && !availableLoaders.includes(versionFilters.loader)
        ? [...availableLoaders, versionFilters.loader]
        : availableLoaders;


    const filteredCategoryOptions = categoryOptions.filter(
        c => !filters.classId || c.parentId === filters.classId
    );

    if (gameStatus !== 'supported') {
        return (
            <ServerContentBlock title={'Addon Browser'} className={'addonbrowser-page'}>
                <div className="addonbrowser-panel bg-neutral-700 rounded p-6 flex items-center gap-3">
                    {gameStatus === 'resolving' ? (
                        <>
                            <FontAwesomeIcon icon={faSpinner} spin className="text-neutral-400" />
                            <p className="addonbrowser-muted text-neutral-400 text-sm">Detecting the server's game…</p>
                        </>
                    ) : (
                        <>
                            <FontAwesomeIcon icon={faExclamationCircle} className="text-red-400" />
                            <p className={`${gameStatus === 'error' ? 'addonbrowser-error text-red-400' : 'addonbrowser-text text-neutral-200'} text-sm`}>
                                {gameStatus === 'error'
                                    ? gameError
                                    : "This server's game isn't recognized or isn't supported by the Addon Browser."}
                            </p>
                        </>
                    )}
                </div>
            </ServerContentBlock>
        );
    }

    return (
        <ServerContentBlock title={'Addon Browser'} className={'addonbrowser-page'}>
            {/* Header */}
            <div className="addonbrowser-panel bg-neutral-700 rounded p-4 mb-4 flex flex-col md:flex-row justify-between items-center gap-4">
                <div className="flex items-center gap-4">
                    <div className="w-12 h-12 rounded-xl bg-indigo-500/20 flex items-center justify-center text-indigo-400 flex-shrink-0">
                        <FontAwesomeIcon icon={faCube} className="text-2xl" />
                    </div>
                    <div>
                        <h1 className="addonbrowser-heading text-2xl font-bold text-white tracking-tight">Addon Browser</h1>
                        <p className="addonbrowser-muted text-neutral-400 text-sm">
                            {platform === 'modrinth' ? 'Powered by Modrinth API' : 'Powered by CurseForge API'}
                        </p>
                    </div>
                </div>

                {/* The game is never chosen by hand: it's detected automatically
                    from the current server's nest (see the effect that calls
                    resolve-game on mount, and AddonGameMapService::
                    resolveGameFromNestName() / "nest_names" in gameConfig.json on the
                    backend — that nest -> game mapping is the authority, never a
                    value chosen on the client). Read-only display. */}
                <div className="flex flex-col md:flex-row items-center gap-2">
                    <label className="addonbrowser-muted text-xs uppercase tracking-wide text-neutral-400">Game</label>
                    <span className="addonbrowser-panel bg-neutral-900 border border-neutral-700 text-neutral-200 text-sm rounded-lg p-2.5 addonbrowser-text">
                        {gameConfigService.getGameConfig(game).label}
                    </span>
                </div>

                {/* Platform Selector — only makes sense if this game offers more
                    than one platform (only Minecraft for now, see gameConfig.json
                    "platforms"). The other games (Hytale, Palworld) only have
                    CurseForge: their backend would force it anyway
                    (AddonBrowserController::resolvePlatform()), so showing an
                    inactive toggle would bring nothing. */}
                {gameConfigService.getAvailablePlatforms(game).length > 1 && (
                <div className="flex gap-2">
                    <button
                        type={'button'}
                        onClick={() => setPlatform('modrinth')}
                        className={`${tabBaseClass} ${platform === 'modrinth' ? '' : tabInactiveClass}`}
                        style={platform === 'modrinth' ? tabActiveStyle('rgb(30, 215, 96)') : undefined}
                    >
                        <svg viewBox="0 0 512 514" className="w-5 h-5" fill="currentColor">
                            <path d="M503.16 323.56C514.55 281.47 515.32 235.91 503.2 190.76C466.57 54.2299 326.04 -26.8001 189.33 9.77991C83.8101 38.0199 11.3899 128.07 0.689941 230.47H43.99C54.29 147.33 113.74 74.7298 199.75 51.7098C306.05 23.2598 415.13 80.6699 453.17 181.38L411.03 192.65C391.64 145.8 352.57 111.45 306.3 96.8198L298.56 140.66C335.09 154.13 364.72 184.5 375.56 224.91C391.36 283.8 361.94 344.14 308.56 369.17L320.09 412.16C390.25 383.21 432.4 310.3 422.43 235.14L464.41 223.91C468.91 252.62 467.35 281.16 460.55 308.07L503.16 323.56Z" />
                            <path d="M321.99 504.22C185.27 540.8 44.7501 459.77 8.11011 323.24C3.84011 307.31 1.17 291.33 0 275.46H43.27C44.36 287.37 46.4699 299.35 49.6799 311.29C53.0399 323.8 57.45 335.75 62.79 347.07L101.38 323.92C98.1299 316.42 95.39 308.6 93.21 300.47C69.17 210.87 122.41 118.77 212.13 94.7601C229.13 90.2101 246.23 88.4401 262.93 89.1501L255.19 133C244.73 133.05 234.11 134.42 223.53 137.25C157.31 154.98 118.01 222.95 135.75 289.09C136.85 293.16 138.13 297.13 139.59 300.99L188.94 271.38L174.07 231.95L220.67 184.36L279.72 171.55L293.49 215.73L237.05 234.2L252.13 274.67L311.09 249.12L360.74 273.53L324.74 296.71L281.83 279.14L227.18 296.96L209.68 350.02L252.62 366.63L277.5 331.34L322.68 349.12L371.83 321.51L382.05 363.1L329.57 389.95L291.99 408.36L258.45 362.82L214.59 345.23L175.45 369.62C196.85 391.39 224.33 407.96 256.16 416.58C326.12 435.32 396.69 400.61 432.71 338.05L472.07 358.34C430.12 437.82 340.08 483.93 249.56 483.93C239.21 483.93 228.9 483.02 218.69 481.08L210.68 524.92C298.68 539.56 398.73 487.67 452.72 401.62L493.94 426.79C470.56 466.72 438.88 499.28 398.74 522.12C377.1 534.34 353.83 543.46 329.24 548.99L321.99 504.22Z" />
                        </svg>
                        Modrinth
                    </button>
                    <button
                        type={'button'}
                        onClick={() => setPlatform('curseforge')}
                        className={`${tabBaseClass} ${platform === 'curseforge' ? '' : tabInactiveClass}`}
                        style={platform === 'curseforge' ? tabActiveStyle('rgb(242, 113, 28)') : undefined}
                    >
                        <svg viewBox="0 0 24 24" className="w-5 h-5" fill="currentColor">
                            <path d="M13.606 23.333c1.97-1.127 3.553-2.95 4.97-4.707 3.033-3.76 1.76-10.274-1.28-12.727-2.733-2.147-3.693-1.073-4.14-1.073-.447 0-1.407-1.074-4.14 1.073-3.04 2.453-4.314 8.967-1.28 12.727 1.413 1.76 3 3.6 4.97 4.707.24.126.66.126.9.006v-.006Zm-.553-15.68c.533.4 1.253.307 1.633.007.247-.194.254-.38.007-.58-.58-.46-1.547-.46-2.126 0-.247.2-.24.386.006.58.38.3 1.1.4 1.633-.007-.353-.273-.86-.273-1.213 0 .02-.02.046-.033.06-.033.012 0 .04.013.06.033-.147.113-.374.113-.52-.007v.007Zm1.166 4.633c1.78.687 2.067 2.767 1.227 4.887-.807 2.227-2.9 3.06-4.993 2.127-1.994-.88-2.614-3.567-1.254-5.467 1.247-1.873 3.327-2.193 5.02-1.547Zm-.447.887c-1.34-.693-3.06.08-3.666 1.587-.554 1.373.187 3.066 1.673 3.493 1.5.427 3.073-.787 3.353-2.313.294-1.587-1.28-2.767-1.36-2.767ZM12.006 2a1.2 1.2 0 0 1 1.2 1.2c0 .66-.54 1.2-1.2 1.2a1.2 1.2 0 0 1-1.2-1.2 1.2 1.2 0 0 1 1.2-1.2Zm0-2a3.2 3.2 0 0 1 3.2 3.2c0 1.767-1.433 3.2-3.2 3.2a3.2 3.2 0 0 1-3.2-3.2 3.2 3.2 0 0 1 3.2-3.2Z" />
                        </svg>
                        CurseForge
                    </button>
                </div>
                )}

                {/* Mods vs Modpacks mode — only shown if the backend confirms
                    availability (egg mapping + eggswitch whitelist), never guessed
                    on the client (see architecture-addonbrowser-addon.md §6). */}
                {modpackAvailable && (
                    <div className="flex gap-2">
                        <button
                            type={'button'}
                            onClick={() => setBrowsingModpacks(false)}
                            className={`${tabBaseClass} ${!browsingModpacks ? '' : tabInactiveClass}`}
                            style={!browsingModpacks ? tabActiveStyle('rgb(99, 102, 241)') : undefined}
                        >
                            Mods
                        </button>
                        <button
                            type={'button'}
                            onClick={() => setBrowsingModpacks(true)}
                            className={`${tabBaseClass} ${browsingModpacks ? '' : tabInactiveClass}`}
                            style={browsingModpacks ? tabActiveStyle('rgb(99, 102, 241)') : undefined}
                        >
                            <FontAwesomeIcon icon={faCube} className="w-4 h-4" />
                            Modpacks
                        </button>
                    </div>
                )}
            </div>

            {/* Search Bar */}
            <div className="addonbrowser-panel bg-neutral-700 rounded p-4 mb-4">
                <div className="flex gap-3">
                    <div className="relative flex-1">
                        <FontAwesomeIcon icon={faSearch} className="absolute left-3 top-1/2 -translate-y-1/2 text-neutral-400" />
                        <input
                            type="text"
                            value={query}
                            onChange={e => setQuery(e.target.value)}
                            className="addonbrowser-input w-full bg-neutral-800/50 border border-neutral-700 text-neutral-200 text-sm rounded-xl focus:ring-2 focus:ring-indigo-500 block pl-10 p-2.5 transition-all outline-none placeholder-neutral-500"
                            placeholder={platform === 'modrinth' ? 'Search Modrinth addons...' : 'Search CurseForge addons...'}
                        />
                    </div>
                    <button onClick={() => setShowFilters(!showFilters)} className="addonbrowser-muted px-4 py-2 bg-neutral-800/50 hover:bg-neutral-700/50 border border-neutral-700 text-neutral-300 rounded-xl transition-all flex items-center gap-2">
                        <FontAwesomeIcon icon={faSlidersH} /> <span className="hidden sm:inline">Filter</span>
                    </button>
                </div>
                {/* The filters panel is collapsed by default: the filtered game
                    version and loader (often pre-selected from the server) are
                    recalled here, each removable in one click. */}
                {(filters.gameVersion || (filters.loader && !browsingModpacks)) && (
                    <p className="addonbrowser-muted mt-2 text-xs text-neutral-400 flex flex-wrap gap-x-3">
                        {filters.gameVersion && (
                            <span>
                                Game version: {filters.gameVersion}
                                {filters.gameVersion === serverGameVersion ? ' (server)' : ''}
                                {' · '}
                                <button
                                    type={'button'}
                                    className="underline hover:text-neutral-200"
                                    onClick={() => {
                                        gameVersionTouched.current = true;
                                        setFilters({ ...filters, gameVersion: '' });
                                    }}
                                >
                                    Show all versions
                                </button>
                            </span>
                        )}
                        {filters.loader && !browsingModpacks && (
                            <span>
                                Loader: {filters.loader}
                                {filters.loader === serverLoader ? ' (server)' : ''}
                                {' · '}
                                <button
                                    type={'button'}
                                    className="underline hover:text-neutral-200"
                                    onClick={() => {
                                        loaderTouched.current = true;
                                        setFilters({ ...filters, loader: '' });
                                    }}
                                >
                                    Show all loaders
                                </button>
                            </span>
                        )}
                    </p>
                )}
            </div>

            {/* Filters */}
            {showFilters && (
                <div className="addonbrowser-panel bg-neutral-700 rounded p-4 mb-4 relative z-10">
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
                        <div>
                            {/* "Browse By" only if the game has several mod
                                classes on CurseForge (Palworld): the backend enforces
                                the class, and only returns classes in that case
                                (see resolveCurseForgeClassId()). */}
                            {platform === 'curseforge' && !browsingModpacks && browseByOptions.length > 1 && (
                            <div className="mb-4">
                                <label className="addonbrowser-muted block mb-2 text-sm font-medium text-neutral-300">Browse By</label>
                                <AddonSelect
                                    value={filters.classId}
                                    options={[{ id: '', name: 'All' }, ...browseByOptions]}
                                    onChange={val => setFilters({ ...filters, classId: val, category: '' })}
                                />
                            </div>
                            )}
                            <label className="addonbrowser-muted block mb-2 text-sm font-medium text-neutral-300">Category</label>
                            <AddonSelect
                                value={filters.category}
                                options={[
                                    { id: '', name: 'All Categories' },
                                    // Modpacks: their own list (another taxonomy);
                                    // otherwise the mod categories returned by the
                                    // backend, for both platforms.
                                    ...(browsingModpacks ? modpackCategoryOptions : filteredCategoryOptions),
                                ]}
                                onChange={val => setFilters({ ...filters, category: val })}
                            />
                        </div>
                        {/* Only if the platform has loaders for this game (the
                            backend returns an empty list otherwise). */}
                        {!browsingModpacks && availableLoaderOptions.length > 0 && (
                            <div>
                                <label className="addonbrowser-muted block mb-2 text-sm font-medium text-neutral-300">Loader</label>
                                <AddonSelect
                                    value={filters.loader}
                                    options={[{ id: '', name: 'All Loaders' }, ...availableLoaderOptions.map(l => ({ id: l, name: l === serverLoader ? `${l} (server)` : l }))]}
                                    onChange={val => {
                                        loaderTouched.current = true;
                                        setFilters({ ...filters, loader: val });
                                    }}
                                />
                            </div>
                        )}
                        {gameVersions.length > 0 && (
                            <div>
                                <label className="addonbrowser-muted block mb-2 text-sm font-medium text-neutral-300">Game Version</label>
                                <AddonSelect
                                    value={filters.gameVersion}
                                    options={[
                                        { id: '', name: 'All Versions' },
                                        ...gameVersions.map(v => ({ id: v, name: v === serverGameVersion ? `${v} (server)` : v })),
                                    ]}
                                    onChange={val => {
                                        gameVersionTouched.current = true;
                                        setFilters({ ...filters, gameVersion: val });
                                    }}
                                />
                            </div>
                        )}
                        <div>
                            <label className="addonbrowser-muted block mb-2 text-sm font-medium text-neutral-300">Sort By</label>
                            <AddonSelect
                                value={filters.sort}
                                options={sortOptions}
                                onChange={val => setFilters({ ...filters, sort: val })}
                            />
                        </div>
                    </div>
                </div>
            )}

            {/* Loading / Results */}
            {loading ? (
                <div className="flex items-center justify-center py-16">
                    <div className="w-12 h-12 border-4 border-indigo-500/30 border-t-indigo-500 rounded-full animate-spin"></div>
                </div>
            ) : (
                <>
                    <div className="addonbrowser-muted mb-4 text-neutral-400 text-sm">
                        Showing {projects.length} of {totalHits} results
                    </div>
                    {/* Hash-based verification running (GET /installed:
                        pending_verification > 0): loading icon followed by the text,
                        shown in plain sight (a `title` tooltip didn't show on hover
                        over the spinning icon). Not in Modpacks mode. */}
                    {showPendingIcon && (
                        <p className="addonbrowser-installed-pending addonbrowser-muted mb-4 text-sm text-neutral-400 flex items-center gap-2">
                            <FontAwesomeIcon icon={faSpinner} spin />
                            Verifying {pendingVerification} installed file{pendingVerification > 1 ? 's' : ''}…
                        </p>
                    )}
                    {/* Server without a mods folder (GET /installed: compatible = false):
                        a message instead of the badges, browsing stays possible. */}
                    {modsIncompatible && (
                        <p className="addonbrowser-mods-incompatible addonbrowser-muted mb-4 text-sm text-neutral-400 flex items-center gap-2">
                            <FontAwesomeIcon icon={faExclamationCircle} />
                            Server not compatible with mods
                        </p>
                    )}
                    <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6 mb-8">
                        {projects.map(project => {
                            // "Installed" badge: a file on the server belongs to
                            // this project; "(disabled)" variant if all its present
                            // files are disabled (.disabled).
                            const installedFiles = installedFilesFor(project);
                            const allDisabled = installedFiles.length > 0 && installedFiles.every(f => f.disabled);
                            return (
                            <div
                                key={`${project.platform}-${project.id}`}
                                onClick={() => loadVersions(project)}
                                className="addonbrowser-card bg-neutral-800 border border-neutral-700 rounded-xl p-6 cursor-pointer transition-all hover:border-indigo-400"
                            >
                                <div className="flex gap-4 mb-4">
                                    <img
                                        src={project.icon_url || 'https://via.placeholder.com/64'}
                                        alt={project.title}
                                        className="w-16 h-16 rounded-lg object-cover flex-shrink-0"
                                        onError={(e) => { (e.target as HTMLImageElement).src = 'https://via.placeholder.com/64'; }}
                                    />
                                    <div className="flex-1 min-w-0">
                                        <h3 className="addonbrowser-heading text-lg font-bold text-white mb-1 truncate">{project.title}</h3>
                                        <p className="addonbrowser-muted text-neutral-400 text-xs truncate">{project.author}</p>
                                        {installedFiles.length > 0 && (
                                            <span
                                                className={`addonbrowser-installed-badge${allDisabled ? ' addonbrowser-installed-badge--disabled' : ''} inline-flex items-center gap-1 mt-1 text-xs px-2 py-0.5 rounded-full font-medium`}
                                                style={allDisabled
                                                    ? { background: 'rgba(163, 163, 163, 0.2)', color: 'rgb(212, 212, 212)' }
                                                    : { background: 'rgba(16, 185, 129, 0.2)', color: 'rgb(52, 211, 153)' }}
                                                title={installedFiles.map(f => f.filename).join('\n')}
                                            >
                                                <FontAwesomeIcon icon={faCheck} />
                                                {allDisabled ? 'Installed (disabled)' : 'Installed'}
                                            </span>
                                        )}
                                    </div>
                                </div>
                                <p className="addonbrowser-text text-neutral-300 text-sm mb-4 line-clamp-2 h-10 overflow-hidden">{project.description}</p>
                                <div className="flex flex-wrap gap-2 mb-4">
                                    {project.categories.slice(0, 3).map(cat => (
                                        <span
                                            key={cat}
                                            className="addonbrowser-tag text-xs px-2 py-0.5 rounded-full font-medium tracking-wide"
                                            style={{ background: 'rgba(99, 102, 241, 0.2)', color: 'rgb(129, 140, 248)' }}
                                        >
                                            {cat}
                                        </span>
                                    ))}
                                </div>
                                <div className="addonbrowser-muted flex justify-between items-center text-xs text-neutral-400 border-t border-neutral-700/50 pt-4">
                                    <div className="flex items-center gap-4">
                                        <span className="flex items-center gap-1"><FontAwesomeIcon icon={faDownload} /> {(project.downloads / 1000).toFixed(1)}k</span>
                                    </div>
                                    <span className="flex items-center gap-1"><FontAwesomeIcon icon={faClock} /> {new Date(project.date_modified).toLocaleDateString()}</span>
                                </div>
                            </div>
                            );
                        })}
                    </div>

                    {/* Pagination */}
                    <div className="flex justify-center items-center gap-2">
                        <button disabled={page === 0} onClick={() => goToPage(page - 1)} className="addonbrowser-btn-neutral px-4 py-2 bg-neutral-800 text-neutral-300 rounded-lg disabled:opacity-50">Prev</button>
                        <span className="addonbrowser-muted px-4 py-2 text-neutral-400">Page {page + 1}</span>
                        <button disabled={(page + 1) * 12 >= totalHits} onClick={() => goToPage(page + 1)} className="addonbrowser-btn-neutral px-4 py-2 bg-neutral-800 text-neutral-300 rounded-lg disabled:opacity-50">Next</button>
                    </div>
                </>
            )}

            {/* Version Modal */}
            {selectedProject && (
                <div className="addonbrowser-modal-backdrop fixed inset-0 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4 z-40 overflow-y-auto">
                    {/* min-h-[460px] (value tested by hand): keeps the modal from
                        shrinking to its content when there are few versions. */}
                    <div className="addonbrowser-modal bg-neutral-800 rounded-2xl max-w-4xl w-full min-h-[460px] max-h-[85vh] flex flex-col overflow-hidden">
                        <div className="addonbrowser-modal__section p-6 border-b border-neutral-700/50 flex justify-between items-center bg-neutral-900/50">
                            <div>
                                <h2 className="addonbrowser-heading text-2xl font-bold text-white">{selectedProject.title}</h2>
                                <p className="addonbrowser-muted text-neutral-400 text-sm">
                                    {selectedProject.platform === 'modrinth' ? 'Modrinth' : 'CurseForge'} • Select a version to download
                                </p>
                            </div>
                            <button onClick={() => setSelectedProject(null)} className="addonbrowser-btn-neutral w-10 h-10 rounded-lg bg-neutral-800 hover:bg-neutral-700 text-neutral-400 hover:text-white flex items-center justify-center">
                                <FontAwesomeIcon icon={faTimes} />
                            </button>
                        </div>

                        {/* Filters for both platforms. */}
                        {(
                            <div className="addonbrowser-modal__section p-6 space-y-4 bg-neutral-900/30 relative z-10">
                                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <label className="addonbrowser-muted block mb-2 text-sm font-medium text-neutral-300">Game Version</label>
                                        <AddonSelect
                                            value={versionFilters.gameVersion}
                                            options={[
                                                { id: '', name: 'All Versions' },
                                                ...modalGameVersionOptions.map(v => ({ id: v, name: v === serverGameVersion ? `${v} (server)` : v })),
                                            ]}
                                            onChange={val => setVersionFilters({ ...versionFilters, gameVersion: val })}
                                        />
                                    </div>
                                    <div>
                                        <label className="addonbrowser-muted block mb-2 text-sm font-medium text-neutral-300">Loader</label>
                                        <AddonSelect
                                            value={versionFilters.loader}
                                            options={[
                                                { id: '', name: 'All Loaders' },
                                                ...modalLoaderOptions.map(v => ({
                                                    id: v,
                                                    name: v === versionFilters.loader && selectedLoaderUnavailable
                                                        ? `${v} (no version)`
                                                        : v === serverLoader ? `${v} (server)` : v,
                                                })),
                                            ]}
                                            onChange={val => setVersionFilters({ ...versionFilters, loader: val })}
                                        />
                                    </div>
                                    <div className="flex items-end">
                                        <label className="addonbrowser-muted flex items-center gap-2 text-sm text-neutral-300 cursor-pointer pb-2.5">
                                            <input
                                                type="checkbox"
                                                className="addonbrowser-checkbox"
                                                checked={versionFilters.showPreReleases}
                                                onChange={e => setVersionFilters({ ...versionFilters, showPreReleases: e.target.checked })}
                                            />
                                            Show beta/alpha versions
                                        </label>
                                    </div>
                                </div>
                            </div>
                        )}

                        <div className="addonbrowser-modal__section flex-1 overflow-y-auto p-6 space-y-3 bg-neutral-900/30">
                            {!loadingVersions && versionsTruncated && (
                                <p className="addonbrowser-muted text-xs text-neutral-400">
                                    Only the 50 most recent files are listed. Pick a game version to see older ones.
                                </p>
                            )}
                            {loadingVersions ? (
                                <div className="text-center py-8"><FontAwesomeIcon icon={faSpinner} spin className="text-3xl text-indigo-500" /></div>
                            ) : filteredVersions.length === 0 ? (
                                <p className="addonbrowser-muted text-center text-neutral-400">
                                    {selectedLoaderUnavailable
                                        ? `This project has no ${versionFilters.loader} ${hiddenPreReleaseCount > 0 ? 'release' : 'version'}${versionFilters.gameVersion ? ` for ${versionFilters.gameVersion}` : ''}.`
                                        : 'No versions found matching filters.'}
                                    {hiddenPreReleaseCount > 0 && (
                                        <> {hiddenPreReleaseCount} beta/alpha version(s) hidden — tick “Show beta/alpha versions” to see them.</>
                                    )}
                                </p>
                            ) : (
                                filteredVersions.map(v => {
                                    const file = v.files.find(f => f.primary) || v.files[0];
                                    if (!file) return null;
                                    // Installed version: its primary file has exactly
                                    // the name of an installed file of THIS project, once
                                    // the .disabled suffix of a disabled mod is removed
                                    // (suffix case ignored, as on the backend).
                                    const installedFile = projectInstalledFiles.find(f =>
                                        f.filename.replace(/\.disabled$/i, '') === file.filename
                                    );
                                    const dlKey = selectedProject ? downloadKey(selectedProject, v) : v.id;
                                    const dlState = downloadStates[dlKey];
                                    return (
                                        <div
                                            key={v.id}
                                            className={`addonbrowser-panel${installedFile ? ' addonbrowser-version-installed' : ''} bg-neutral-800/40 rounded-lg p-4 flex items-center justify-between gap-4 border ${installedFile ? '' : 'border-white/5'}`}
                                            style={installedFile ? { borderColor: 'rgba(52, 211, 153, 0.6)' } : undefined}
                                        >
                                            <div className="flex-1">
                                                <div className="flex items-center gap-2 mb-1">
                                                    <span className="addonbrowser-text font-semibold text-neutral-200">{v.name}</span>
                                                    {installedFile && (
                                                        <span
                                                            className={`addonbrowser-installed-badge${installedFile.disabled ? ' addonbrowser-installed-badge--disabled' : ''} text-[10px] uppercase font-bold px-1.5 py-0.5 rounded`}
                                                            style={installedFile.disabled
                                                                ? { background: 'rgba(163, 163, 163, 0.2)', color: 'rgb(212, 212, 212)' }
                                                                : { background: 'rgba(16, 185, 129, 0.2)', color: 'rgb(52, 211, 153)' }}
                                                        >
                                                            {installedFile.disabled ? 'Installed (disabled)' : 'Installed'}
                                                        </span>
                                                    )}
                                                    <span
                                                        className="text-[10px] uppercase font-bold px-1.5 py-0.5 rounded"
                                                        style={
                                                            v.version_type === 'release'
                                                                ? { background: 'rgba(16, 185, 129, 0.2)', color: 'rgb(52, 211, 153)' }
                                                                : { background: 'rgba(245, 158, 11, 0.2)', color: 'rgb(251, 191, 36)' }
                                                        }
                                                    >
                                                        {v.version_type}
                                                    </span>
                                                </div>
                                                <div className="addonbrowser-muted flex flex-wrap gap-3 text-xs text-neutral-400">
                                                    <span className="flex items-center gap-1"><FontAwesomeIcon icon={faClock} /> {new Date(v.date_published).toLocaleDateString()}</span>
                                                    {v.downloads > 0 && <span className="flex items-center gap-1"><FontAwesomeIcon icon={faCloudDownloadAlt} /> {v.downloads}</span>}
                                                </div>
                                            </div>
                                            {browsingModpacks ? (
                                                <button
                                                    onClick={() => openInstallDialog(v)}
                                                    className="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium transition-all flex items-center gap-2 whitespace-nowrap"
                                                >
                                                    <FontAwesomeIcon icon={faCube} /> Install this modpack
                                                </button>
                                            ) : (
                                                // Label: "Reinstall" for the installed version,
                                                // "Install another version" for the other versions
                                                // of an installed project, "Download" otherwise;
                                                // never blocked. Only a download in progress of
                                                // THIS version and the 3 s of "Success" that
                                                // follow disable the button.
                                                <button
                                                    onClick={() => downloadVersion(dlKey, file)}
                                                    disabled={dlState === 'downloading' || dlState === 'success'}
                                                    className={`px-4 py-2 ${dlState === 'success' ? 'bg-green-600 cursor-default' : dlState === 'error' ? 'bg-red-600' : 'bg-indigo-600 hover:bg-indigo-700'} ${dlState === 'downloading' ? 'opacity-75 cursor-not-allowed' : ''} text-white rounded-lg text-sm font-medium transition-all flex items-center gap-2 whitespace-nowrap`}
                                                >
                                                    {dlState === 'downloading' ? (
                                                        <><FontAwesomeIcon icon={faSpinner} spin /> Downloading...</>
                                                    ) : dlState === 'success' ? (
                                                        <><FontAwesomeIcon icon={faCheck} /> Success</>
                                                    ) : dlState === 'error' ? (
                                                        <><FontAwesomeIcon icon={faExclamationCircle} /> Error</>
                                                    ) : (
                                                        <>
                                                            <FontAwesomeIcon icon={faDownload} />{' '}
                                                            {installedFile ? 'Reinstall' : projectInstalledFiles.length > 0 ? 'Install another version' : 'Download'}
                                                        </>
                                                    )}
                                                </button>
                                            )}
                                        </div>
                                    );
                                })
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* Destructive confirmation before installing a modpack: retype the
                server's name, never a confirmation based only on a checkbox. */}
            {installVersion && selectedProject && server && (
                <div className="addonbrowser-modal-backdrop fixed inset-0 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4 z-50">
                    <div className="addonbrowser-modal bg-neutral-800 rounded-2xl max-w-lg w-full p-6">
                        {installSuccess ? (
                            <>
                                <h2 className="addonbrowser-heading text-xl font-bold text-white mb-2">Install started</h2>
                                <p className="addonbrowser-text text-neutral-300 text-sm mb-4">
                                    The server is being reinstalled with <strong>{selectedProject.title}</strong> ({installVersion.name}).
                                    This can take a few minutes depending on the modpack's size.
                                </p>
                                <div className="flex justify-end">
                                    <button
                                        onClick={() => { setInstallVersion(null); setSelectedProject(null); }}
                                        className="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium"
                                    >
                                        Close
                                    </button>
                                </div>
                            </>
                        ) : (
                            <>
                                <h2 className="addonbrowser-heading text-xl font-bold text-white mb-2">Confirm the modpack install</h2>
                                <p className="addonbrowser-text text-neutral-300 text-sm mb-4">
                                    This will <strong>delete all current files</strong> of{' '}
                                    <strong>{server.name}</strong> and reinstall the server with{' '}
                                    <strong>{selectedProject.title}</strong> ({installVersion.name})
                                    {withBackup && hasBackupSlot ? ', after a safety backup' : ' without a backup first'}.
                                    This can't be undone.
                                </p>

                                <label className="flex items-center mb-4">
                                    <input
                                        type="checkbox"
                                        className="mr-2"
                                        disabled={!hasBackupSlot}
                                        checked={withBackup && hasBackupSlot}
                                        onChange={e => setWithBackup(e.currentTarget.checked)}
                                    />
                                    <span className="addonbrowser-text text-sm text-neutral-300">
                                        Make a backup before reinstalling
                                        {!hasBackupSlot && (
                                            <span className="addonbrowser-muted text-neutral-500"> (no backup slot available on this server)</span>
                                        )}
                                    </span>
                                </label>

                                {installDockerImages.length > 0 && (
                                    <div className="mb-4">
                                        <label className="addonbrowser-muted block mb-2 text-sm font-medium text-neutral-300">
                                            Java Version
                                        </label>
                                        <AddonSelect
                                            value={installDockerImage}
                                            options={installDockerImages}
                                            onChange={val => setInstallDockerImage(val)}
                                        />
                                        <p className="addonbrowser-muted text-xs text-neutral-500 mt-1">
                                            Pre-selected from this modpack's Minecraft version — change it if needed; it can also be changed later from the server's Startup page.
                                        </p>
                                    </div>
                                )}

                                <label className="addonbrowser-text block text-sm mb-1 text-neutral-300">
                                    Type the server's name (<code>{server.name}</code>) to confirm:
                                </label>
                                <input
                                    className="addonbrowser-input w-full rounded bg-neutral-800 border border-neutral-700 px-3 py-2 text-sm mb-2 text-neutral-200"
                                    value={installConfirmName}
                                    onChange={e => setInstallConfirmName(e.currentTarget.value)}
                                    disabled={installing}
                                />

                                {installError && (
                                    <p className="addonbrowser-error text-red-400 text-sm mb-2">{installError}</p>
                                )}

                                <div className="flex justify-end gap-2 mt-4">
                                    <button
                                        onClick={() => setInstallVersion(null)}
                                        disabled={installing}
                                        className="addonbrowser-btn-neutral px-4 py-2 bg-neutral-800 hover:bg-neutral-700 text-neutral-300 rounded-lg text-sm font-medium"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        onClick={installModpack}
                                        disabled={installing || installConfirmName !== server.name}
                                        className="px-4 py-2 bg-red-600 hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-lg text-sm font-medium flex items-center gap-2"
                                    >
                                        {installing && <FontAwesomeIcon icon={faSpinner} spin />}
                                        Reinstall
                                    </button>
                                </div>
                            </>
                        )}
                    </div>
                </div>
            )}
        </ServerContentBlock>
    );
};
