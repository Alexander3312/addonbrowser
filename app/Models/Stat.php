<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Event counter of the installed-mods detection: total of one metric, for one
 * game, on one day. Unique per (day, game, metric).
 *
 * Written only through Support\AddonStats (upsert `total = total + n`, on
 * this model's connection); read by Services\AdminOverviewService.
 *
 * @property int $id
 * @property string $day
 * @property string $game
 * @property string $metric
 * @property int $total
 */
class Stat extends Model
{
    protected $table = "addonbrowser_stats";

    public $timestamps = false;

    protected $fillable = ['day', 'game', 'metric', 'total'];

    protected $casts = [
        'total' => 'int',
    ];
}
