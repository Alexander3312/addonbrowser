<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A mod in the panel-wide referential (installed-mods detection), independent
 * of the platform: Modrinth id, CurseForge id, or both. A mod has no
 * normalized name of its own: it's recognized by the set of `filename_key`s
 * of its known files (ModFile).
 *
 * Written only through Services\ModReferenceService (linking, merging and
 * conflict rules): never create()/update() anywhere else in the code.
 *
 * @property int $id
 * @property string $game
 * @property string $name
 * @property string|null $modrinth_project_id
 * @property int|null $curseforge_project_id
 */
class Mod extends Model
{
    protected $table = 'addonbrowser_mods';

    protected $fillable = ['game', 'name', 'modrinth_project_id', 'curseforge_project_id'];

    protected $casts = [
        'curseforge_project_id' => 'int',
    ];

    public function files(): HasMany
    {
        return $this->hasMany(ModFile::class, 'mod_id');
    }
}
