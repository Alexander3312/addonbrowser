<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('addonbrowser_stats')) {
            return;
        }

        Schema::create('addonbrowser_stats', function (Blueprint $table) {
            // Event counters of the installed-mods detection, one per day, per
            // game and per metric: counted once, where they happen (hash
            // verification job, referential writer service), never during
            // GET /installed. Written only through Support\AddonStats
            // (INSERT … ON DUPLICATE KEY UPDATE total = total + n).
            $table->id();
            // Day, in the application's timezone.
            $table->date('day');
            // Addonbrowser game key (`minecraft`…, see gameConfig.json); 191 = the
            // panel's default string length, same as the other tables.
            $table->string('game', 191);
            // Short metric name (`verify_sha1_db`, `mod_created`…).
            $table->string('metric', 64);
            $table->unsignedBigInteger('total')->default(0);

            // One row per (day, game, metric): target of the upsert.
            $table->unique(['day', 'game', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addonbrowser_stats');
    }
};
