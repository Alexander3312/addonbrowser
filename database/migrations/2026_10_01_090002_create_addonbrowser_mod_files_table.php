<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('addonbrowser_mod_files')) {
            return;
        }

        // See the addonbrowser_mods migration: strict equality on
        // MySQL/MariaDB for the normalized key (step 1 of the detection needs
        // an **equal** key, never "equal give or take an accent" as
        // utf8mb4_unicode_ci would do) and for the Modrinth version id.
        $exact = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        Schema::create('addonbrowser_mod_files', function (Blueprint $table) use ($exact) {
            // A known file of a mod (one row per file, not per mod: each
            // version has its own name, size and hash). Only official API
            // data goes in, through ModReferenceService.
            $table->id();
            // Same value as the mod: no match ever crosses games.
            $table->string('game');
            $table->unsignedBigInteger('mod_id');
            // Exact official name (255 bytes at most, like a filename).
            $table->string('filename', 255);
            // Normalized name (FilenameNormalizer), the only column searched by name.
            $key = $table->string('filename_key', 255);
            $table->unsignedBigInteger('size');
            $table->char('sha1', 40);
            $table->unsignedInteger('cf_fingerprint')->nullable();
            $version = $table->string('modrinth_version_id', 64)->nullable();
            $table->unsignedInteger('curseforge_file_id')->nullable();
            // download, modpack_index, hash_lookup or search_result.
            $table->string('source', 32);
            $table->timestamps();

            if ($exact) {
                $key->collation('utf8mb4_bin');
                $version->collation('utf8mb4_bin');
            }

            // Only indexes: the other columns are stored, never searched.
            $table->index(['game', 'filename_key']);
            // Unique per game (also serves as the index for lookups by SHA1).
            $table->unique(['game', 'sha1']);
            $table->index('mod_id');
            $table->foreign('mod_id')->references('id')->on('addonbrowser_mods')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addonbrowser_mod_files');
    }
};
