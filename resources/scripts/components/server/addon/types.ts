import type { Platform } from './gameConfig';

export interface UnifiedProject {
    id: string;
    title: string;
    description: string;
    author: string;
    downloads: number;
    icon_url: string;
    categories: string[];
    date_modified: string;
    platform: Platform;
}

export interface UnifiedVersion {
    id: string;
    name: string;
    // Needed for modpack installs: mrpack-install expects the Modrinth
    // version_number, never the id (see DEVELOPMENT.md — the two look alike
    // but aren't interchangeable).
    version_number: string;
    version_type: string;
    date_published: string;
    downloads: number;
    files: {
        url: string;
        filename: string;
        primary: boolean;
        size: number;
    }[];
    game_versions: string[];
    loaders: string[];
}

// A file in the mods folder recognized by GET /installed (installed-mods
// detection). A mod recognized through a CurseForge file only (`cf_match`) only
// has curseforge_project_id. matched_by is for debugging only.
export interface InstalledModFile {
    modrinth_project_id: string | null;
    curseforge_project_id: number | null;
    filename: string;
    disabled: boolean;
    matched_by: 'name' | 'cache';
}

// GET /installed response: enabled = false for a game without detection
// (nothing else); compatible = false if the server has no mods folder.
// pending_verification > 0: a hash-based verification is running on the server
// (loading icon and a new call every 10 s, 5 min at most).
export interface InstalledModsResponse {
    enabled: boolean;
    compatible?: boolean;
    installed?: InstalledModFile[];
    pending_verification?: number;
    unverified_overflow?: number;
}
