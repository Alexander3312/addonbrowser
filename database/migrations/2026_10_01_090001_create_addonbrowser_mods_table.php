<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('addonbrowser_mods')) {
            return;
        }

        // Exact (case-sensitive) comparison on MySQL/MariaDB: the panel's
        // default collation (utf8mb4_unicode_ci) would treat two Modrinth ids
        // that only differ by case as equal (`AANobbMI` and `aanobbmi` are two
        // different projects). SQLite already compares byte by byte and
        // doesn't know this collation.
        $exact = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        Schema::create('addonbrowser_mods', function (Blueprint $table) use ($exact) {
            // A mod, independent of the platform: Modrinth id, CurseForge id,
            // or both (at least one of them, checked by ModReferenceService,
            // the only writer of this table).
            $table->id();
            // Addonbrowser game key (`minecraft`…, see gameConfig.json).
            $table->string('game')->index();
            // Official display name (API).
            $table->string('name', 255);
            // Platform ids are unique across all games: the game is an
            // attribute of the project, on Modrinth as on CurseForge.
            $modrinth = $table->string('modrinth_project_id', 64)->nullable()->unique();
            if ($exact) {
                $modrinth->collation('utf8mb4_bin');
            }
            $table->unsignedInteger('curseforge_project_id')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addonbrowser_mods');
    }
};
