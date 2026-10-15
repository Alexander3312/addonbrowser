<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A known file of a mod: **official** name, size and hash (returned by an
 * API, never the name of a file found on a server). Unique per (game, sha1):
 * no match ever crosses games, even for a binary published for two games.
 * Deleted with its mod (cascading foreign key).
 *
 * Written only through Services\ModReferenceService.
 *
 * @property int $id
 * @property string $game
 * @property int $mod_id
 * @property string $filename
 * @property string $filename_key
 * @property int $size
 * @property string $sha1
 * @property int|null $cf_fingerprint
 * @property string|null $modrinth_version_id
 * @property int|null $curseforge_file_id
 * @property string $source
 */
class ModFile extends Model
{
    /** File downloaded by addonbrowser. */
    public const SOURCE_DOWNLOAD = 'download';

    /** Entry of an installed modpack's manifest, checked against the API. */
    public const SOURCE_MODPACK_INDEX = 'modpack_index';

    /** An API's answer to a hash computed server-side. */
    public const SOURCE_HASH_LOOKUP = 'hash_lookup';

    /** Recent file from a CurseForge search result. */
    public const SOURCE_SEARCH_RESULT = 'search_result';

    public const SOURCES = [
        self::SOURCE_DOWNLOAD,
        self::SOURCE_MODPACK_INDEX,
        self::SOURCE_HASH_LOOKUP,
        self::SOURCE_SEARCH_RESULT,
    ];

    protected $table = 'addonbrowser_mod_files';

    protected $fillable = [
        'game',
        'mod_id',
        'filename',
        'filename_key',
        'size',
        'sha1',
        'cf_fingerprint',
        'modrinth_version_id',
        'curseforge_file_id',
        'source',
    ];

    protected $casts = [
        'mod_id' => 'int',
        'size' => 'int',
        'cf_fingerprint' => 'int',
        'curseforge_file_id' => 'int',
    ];

    public function mod(): BelongsTo
    {
        return $this->belongsTo(Mod::class, 'mod_id');
    }
}
