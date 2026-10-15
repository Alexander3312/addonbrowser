<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Models\Mod;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Models\ModFile;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\AddonBrowserLogger;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\AddonStats;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\CollisionJournal;

/**
 * The only writer of the installed-mods referential (`addonbrowser_mods` and
 * `addonbrowser_mod_files` tables). Panel-wide: "this file = this mod" holds
 * on every server.
 *
 * What goes in: only **official** data obtained by the backend from an API
 * (download, checked manifest, answer to a hash computed server-side, search
 * result). Never a filename found on a server, never a value sent by the
 * browser. The official name, size and hashes are always recorded; the
 * normalized key is computed here, from the official name.
 *
 * Rules:
 * - **an id already set is never overwritten**: a Modrinth or CurseForge id
 *   different from the found mod's = conflict;
 * - two mods, one with only a Modrinth id, the other with only a CurseForge
 *   id, that the recorded file connects (same SHA1) are **merged** (files
 *   re-attached to the kept mod, the oldest one; the other deleted), **only**
 *   if no id conflicts;
 * - otherwise **nothing is changed**;
 * - these rules apply to every source, search results included (see the
 *   indexing of search results below);
 * - every merge and every conflict is written to the journal
 *   (CollisionJournal), after the transaction commits; the same conflict
 *   (game, SHA1, reason) at most once per day, merges always;
 * - statistics (Support\AddonStats), counted after the commit, once per
 *   write: `mod_created`, `mod_linked` (id added to an existing mod),
 *   `mod_merged`, `conflict_refused` (every refusal, without deduplication),
 *   `file_added_<source>` (new file row);
 * - a platform id attached to a mod of another game = conflict: no match ever
 *   crosses games;
 * - idempotent write on (game, sha1): an already known file is never
 *   duplicated; only its missing details (fingerprint, version ids) are
 *   filled in, never replaced;
 * - the mod's name is only required to **create** a mod; an official name
 *   whose normalized key is empty (e.g. `.jar`) is refused.
 *
 * Every write happens in a transaction on the model's connection (never DB::
 * on the default connection), with a lock on the rows read (no effect on
 * SQLite), retried up to 3 times on a deadlock (MariaDB in REPEATABLE-READ
 * puts gap locks on missing rows read FOR UPDATE: two jobs creating at the
 * same time can deadlock). A unique constraint violation (concurrent write)
 * gives a second pass. Logs and the journal never make the write fail.
 *
 * Used by the feeding jobs (no write during a user's request, see
 * Jobs\RecordDownloadedModJob and Jobs\IndexCurseForgeSearchJob). Indexing of
 * CurseForge search results, with `source = search_result`:
 * - step 1 (SHA1 known, so the mod exists): if it already has another
 *   CurseForge id, nothing is changed and the conflict is journaled. If it
 *   has none: if no other mod carries this CurseForge id, it's added to it
 *   (link); if another mod carries it, the merge rule above applies (merge if
 *   no id conflicts, otherwise nothing changed and conflict journaled).
 *   That's exactly what recordFile() does with the CurseForge id alone;
 * - step 2 (CurseForge id known): file added to that mod;
 * - step 3 (grouped Modrinth call): up to the job, which calls recordFile()
 *   with the Modrinth id (and version id) returned **for that same SHA1, with
 *   the same size** as well, whatever the step. If no mod carries either id,
 *   the mod is created directly with both; otherwise, same rules (link,
 *   merge or conflict). The job only passes the Modrinth id of a project
 *   missing from the database if that project is of type `mod` (checked by a
 *   grouped call, as after a download);
 * - step 4: creation of the mod and the file.
 */
class ModReferenceService
{
    /** Modrinth project or version id (base 62, 8 characters today). */
    private const MODRINTH_ID = '/^[A-Za-z0-9]{1,64}$/';

    private const SHA1 = '/^[0-9a-f]{40}$/';

    private const UINT32_MAX = 4294967295;

    /** SHA1s per findFilesBySha1() query. */
    private const LOOKUP_CHUNK = 500;

    /** Attempts of a transaction (retried on a deadlock). */
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private AddonGameMapService $gameMapService,
        private FilenameNormalizer $normalizer
    ) {
    }

    /**
     * Records an official file of a mod (creation, attachment to an existing
     * mod, Modrinth ↔ CurseForge link or merge, per the rules above).
     *
     * `$project`: project data returned by the API for **this** file. Both
     * ids are only given together if they come from official answers about
     * this same SHA1 (e.g. a CurseForge file whose SHA1 is also known to
     * Modrinth): that's the only proof that allows linking or merging two
     * mods.
     *
     * `name` is only required if a mod has to be created (otherwise
     * `rejected`, "name required to create a mod"): Modrinth's `version_files`
     * and CurseForge's `mods/files` responses don't give it.
     *
     * @param array{name?: ?string, modrinth_project_id?: ?string, curseforge_project_id?: int|string|null} $project
     * @param array{filename: string, size: int|string, sha1: string, cf_fingerprint?: int|string|null, modrinth_version_id?: ?string, curseforge_file_id?: int|string|null} $file
     * @param string $source one of the ModFile::SOURCES values
     */
    public function recordFile(string $game, array $project, array $file, string $source): ModReferenceResult
    {
        $input = $this->validate($game, $project, $file, $source);

        if (is_string($input)) {
            $this->log('warning', "Mod referential: write refused, {$input}.", [
                'game' => $game,
                'source' => $source,
                'filename' => is_string($file['filename'] ?? null) ? $file['filename'] : null,
            ]);

            return new ModReferenceResult(ModReferenceResult::REJECTED, reason: $input);
        }

        try {
            return $this->write($input);
        } catch (UniqueConstraintViolationException $ex) {
            // Concurrent write of the same file or the same mod (two jobs at
            // once): the transaction was rolled back, the second pass finds
            // the rows created in the meantime.
            try {
                return $this->write($input);
            } catch (UniqueConstraintViolationException $ex) {
                $this->log('warning', 'Mod referential: write given up after a concurrent write.', [
                    'game' => $input['game'],
                    'sha1' => $input['sha1'],
                    'error' => $ex->getMessage(),
                ]);

                return new ModReferenceResult(ModReferenceResult::REJECTED, reason: 'concurrent write');
            }
        }
    }

    /**
     * A game's known files for a list of SHA1s (step 3 of the cascade, step 1
     * of the CurseForge indexing), with their mod, keyed by SHA1. Invalid SHA1s
     * are ignored.
     *
     * @param string[] $sha1s
     * @return Collection<string, ModFile>
     */
    public function findFilesBySha1(string $game, array $sha1s): Collection
    {
        $sha1s = collect($sha1s)
            ->filter(fn ($sha1) => is_string($sha1))
            ->map(fn (string $sha1) => strtolower($sha1))
            ->filter(fn (string $sha1) => preg_match(self::SHA1, $sha1) === 1)
            ->unique()
            ->values();

        $found = collect();
        foreach ($sha1s->chunk(self::LOOKUP_CHUNK) as $chunk) {
            $found = $found->merge(
                ModFile::query()
                    ->with('mod')
                    ->where('game', strtolower($game))
                    ->whereIn('sha1', $chunk->all())
                    ->get()
            );
        }

        return $found->keyBy('sha1');
    }

    public function findModByModrinthId(string $projectId): ?Mod
    {
        return preg_match(self::MODRINTH_ID, $projectId) === 1
            ? Mod::query()->where('modrinth_project_id', $projectId)->first()
            : null;
    }

    public function findModByCurseForgeId(int $projectId): ?Mod
    {
        return $projectId > 0 ? Mod::query()->where('curseforge_project_id', $projectId)->first() : null;
    }

    /**
     * Does the referential accept writes for this game (configured game, with
     * a known normalizer, i.e. `installed_detection` in gameConfig.json)? Lets
     * the triggers (download(), search()) dispatch no job and call no API for
     * a game without detection: recordFile() would refuse everything.
     */
    public function acceptsGame(string $game): bool
    {
        $game = strtolower(trim($game));

        return $game !== ''
            && $this->gameMapService->isConfigured($game)
            && $this->normalizer->has($this->gameMapService->normalizer($game));
    }

    /**
     * Normalized key of a filename for a game (normalizer chosen in
     * gameConfig.json), or null if the game has no normalizer.
     *
     * @return array{key: string, disabled: bool}|null
     */
    public function normalizeFor(string $game, string $filename): ?array
    {
        $normalizer = $this->gameMapService->normalizer($game);

        return $this->normalizer->has($normalizer) ? $this->normalizer->normalize($filename, $normalizer) : null;
    }

    /**
     * One write pass, in a transaction. The journal lines and the statistics
     * (`mod_created`, `mod_linked`, `mod_merged`, `conflict_refused`,
     * `file_added_<source>`) are only written after the commit, once: a
     * rolled-back transaction leaves neither a false "merge" in the journal
     * nor an extra count (both are reset on every attempt of a retried
     * transaction, and a pass that throws writes nothing).
     */
    private function write(array $in): ModReferenceResult
    {
        $journal = [];
        $metrics = [];

        $result = $this->connection()->transaction(function () use ($in, &$journal, &$metrics) {
            // Start again from an empty journal and counters on every attempt
            // (retried transaction).
            $journal = [];
            $metrics = [];
            $game = $in['game'];
            $modrinthId = $in['modrinth_project_id'];
            $curseforgeId = $in['curseforge_project_id'];

            $existing = ModFile::query()
                ->where('game', $game)
                ->where('sha1', $in['sha1'])
                ->lockForUpdate()
                ->first();

            // Same SHA1, different size: inconsistent data, nothing is touched.
            if ($existing !== null && (int) $existing->size !== $in['size']) {
                $this->log('warning', 'Mod referential: same SHA1 with a different size, nothing changed.', [
                    'game' => $game,
                    'sha1' => $in['sha1'],
                    'known_size' => $existing->size,
                    'size' => $in['size'],
                ]);

                return new ModReferenceResult(ModReferenceResult::REJECTED, reason: 'different size for a known SHA1');
            }

            $byFile = $existing !== null ? Mod::query()->lockForUpdate()->find($existing->mod_id) : null;
            $byModrinth = $modrinthId !== null
                ? Mod::query()->where('modrinth_project_id', $modrinthId)->lockForUpdate()->first()
                : null;
            $byCurseForge = $curseforgeId !== null
                ? Mod::query()->where('curseforge_project_id', $curseforgeId)->lockForUpdate()->first()
                : null;

            /** @var Collection<int, Mod> $mods */
            $mods = collect([$byFile, $byModrinth, $byCurseForge])->filter()->unique('id')->values();

            if ($mods->contains(fn (Mod $mod) => $mod->game !== $game)) {
                return $this->conflict($in, $mods, 'platform id attached to a mod of another game', $journal, $metrics);
            }

            // No known mod: creation.
            if ($mods->isEmpty()) {
                if ($in['name'] === null) {
                    return new ModReferenceResult(ModReferenceResult::REJECTED, reason: 'name required to create a mod');
                }
                $mod = Mod::query()->create([
                    'game' => $game,
                    'name' => $in['name'],
                    'modrinth_project_id' => $modrinthId,
                    'curseforge_project_id' => $curseforgeId,
                ]);
                $file = $this->createFile($mod, $in, $metrics);
                $metrics[AddonStats::MOD_CREATED] = 1;

                return new ModReferenceResult(ModReferenceResult::CREATED, $mod, $file);
            }

            $changed = false;
            $merged = false;

            if ($mods->count() === 1) {
                // A single mod: its missing ids are filled in, never replaced.
                // A missing id isn't carried by any other mod (otherwise that
                // one would have been found above).
                $mod = $mods->first();
                if ($this->clashes($mod->modrinth_project_id, $modrinthId) || $this->clashes($mod->curseforge_project_id, $curseforgeId)) {
                    return $this->conflict($in, $mods, 'a different id is already set', $journal, $metrics);
                }

                $mod->fill(array_filter([
                    'modrinth_project_id' => $mod->modrinth_project_id === null ? $modrinthId : null,
                    'curseforge_project_id' => $mod->curseforge_project_id === null ? $curseforgeId : null,
                ], fn ($id) => $id !== null));

                if ($mod->isDirty()) {
                    $mod->save();
                    $changed = true;
                    // Platform id added to an existing mod: link.
                    $metrics[AddonStats::MOD_LINKED] = 1;
                }
            } else {
                // Several mods involved (e.g. step 1 of a CurseForge search
                // indexing: the SHA1 is known to a mod without a CurseForge
                // id, and another mod carries that id): merge rule, whatever
                // the source.
                $pair = $mods->count() === 2 ? $this->mergeablePair($mods[0], $mods[1], $modrinthId, $curseforgeId) : null;
                if ($pair === null) {
                    return $this->conflict($in, $mods, 'merge impossible (conflicting ids)', $journal, $metrics);
                }

                [$kept, $removed, $ids] = $pair;
                $before = [$this->describe($kept), $this->describe($removed)];

                ModFile::query()->where('mod_id', $removed->id)->update(['mod_id' => $kept->id]);
                // Deleted before completing the kept mod: ids are unique.
                $removed->delete();
                $kept->fill($ids)->save();

                $existing?->refresh();
                $mod = $kept;
                $merged = true;
                $metrics[AddonStats::MOD_MERGED] = 1;

                $journal[] = [CollisionJournal::TYPE_MERGE, null, 'Two mods merged (same SHA1).', [
                    'game' => $game,
                    'sha1' => $in['sha1'],
                    'filename' => $in['filename'],
                    'source' => $in['source'],
                    'kept' => $before[0],
                    'removed' => $before[1],
                    'result' => $this->describe($kept),
                ]];
            }

            if ($existing === null) {
                $file = $this->createFile($mod, $in, $metrics);
                $changed = true;
            } else {
                // File already known (necessarily of this mod): its missing
                // details are filled in, nothing is replaced.
                $file = $existing;
                foreach (['cf_fingerprint', 'modrinth_version_id', 'curseforge_file_id'] as $column) {
                    if ($file->{$column} === null && $in[$column] !== null) {
                        $file->{$column} = $in[$column];
                    }
                }
                if ($file->isDirty()) {
                    $file->save();
                    $changed = true;
                }
            }

            $status = $merged ? ModReferenceResult::MERGED : ($changed ? ModReferenceResult::UPDATED : ModReferenceResult::UNCHANGED);

            return new ModReferenceResult($status, $mod, $file);
        }, self::TRANSACTION_ATTEMPTS);

        // After the commit. CollisionJournal::write() and AddonStats never throw.
        foreach ($journal as [$type, $oncePerDay, $message, $context]) {
            CollisionJournal::write($type, $message, $context, $oncePerDay);
        }
        if ($metrics !== []) {
            AddonStats::incrementMany($in['game'], $metrics);
        }

        $this->log('debug', 'Mod referential: ' . $result->status, [
            'game' => $in['game'],
            'sha1' => $in['sha1'],
            'source' => $in['source'],
            'mod_id' => $result->mod?->id,
        ]);

        return $result;
    }

    /**
     * Two mergeable mods: one with only a Modrinth id, the other with only a
     * CurseForge id, of the same game, and the ids of the current write equal
     * to theirs. Returns [kept (the oldest), removed, ids to give the kept
     * one], or null if a condition is missing.
     *
     * @return array{0: Mod, 1: Mod, 2: array{modrinth_project_id: string, curseforge_project_id: int}}|null
     */
    private function mergeablePair(Mod $a, Mod $b, ?string $modrinthId, ?int $curseforgeId): ?array
    {
        $modrinthOnly = fn (Mod $m) => $m->modrinth_project_id !== null && $m->curseforge_project_id === null;
        $curseforgeOnly = fn (Mod $m) => $m->modrinth_project_id === null && $m->curseforge_project_id !== null;

        if ($modrinthOnly($a) && $curseforgeOnly($b)) {
            [$mr, $cf] = [$a, $b];
        } elseif ($modrinthOnly($b) && $curseforgeOnly($a)) {
            [$mr, $cf] = [$b, $a];
        } else {
            return null;
        }

        if ($a->game !== $b->game
            || $this->clashes($mr->modrinth_project_id, $modrinthId)
            || $this->clashes($cf->curseforge_project_id, $curseforgeId)) {
            return null;
        }

        [$kept, $removed] = $a->id < $b->id ? [$a, $b] : [$b, $a];

        return [$kept, $removed, [
            'modrinth_project_id' => $mr->modrinth_project_id,
            'curseforge_project_id' => $cf->curseforge_project_id,
        ]];
    }

    /** A known id and an incoming id, both set and different. */
    private function clashes(string|int|null $known, string|int|null $incoming): bool
    {
        return $known !== null && $incoming !== null && $known !== $incoming;
    }

    /**
     * Write refused: nothing was changed in the transaction (the refusal is
     * decided before any write); `conflict` line in the journal (at most one
     * per day for the same game, SHA1 and reason).
     *
     * Counted in `conflict_refused` on every refusal, without the journal's
     * deduplication (the cautious choice: the counter says how many writes were
     * refused, the journal only keeps one line per day).
     *
     * @param Collection<int, Mod> $mods
     */
    private function conflict(array $in, Collection $mods, string $reason, array &$journal, array &$metrics): ModReferenceResult
    {
        $metrics[AddonStats::CONFLICT_REFUSED] = 1;

        // At most one line per day for the same conflict (the CurseForge
        // search indexing replays the same files on every search).
        $journal[] = [CollisionJournal::TYPE_CONFLICT, $in['game'] . '|' . $in['sha1'] . '|' . $reason, "Write refused: {$reason}.", [
            'game' => $in['game'],
            'sha1' => $in['sha1'],
            'filename' => $in['filename'],
            'source' => $in['source'],
            'incoming' => [
                'name' => $in['name'],
                'modrinth_project_id' => $in['modrinth_project_id'],
                'curseforge_project_id' => $in['curseforge_project_id'],
            ],
            'mods' => $mods->map(fn (Mod $mod) => $this->describe($mod))->all(),
        ]];

        return new ModReferenceResult(ModReferenceResult::CONFLICT, reason: $reason);
    }

    /** New file row, counted in `file_added_<source>` (after the commit). */
    private function createFile(Mod $mod, array $in, array &$metrics): ModFile
    {
        $metrics[AddonStats::fileAdded($in['source'])] = 1;

        return ModFile::query()->create([
            'game' => $mod->game,
            'mod_id' => $mod->id,
            'filename' => $in['filename'],
            'filename_key' => $in['filename_key'],
            'size' => $in['size'],
            'sha1' => $in['sha1'],
            'cf_fingerprint' => $in['cf_fingerprint'],
            'modrinth_version_id' => $in['modrinth_version_id'],
            'curseforge_file_id' => $in['curseforge_file_id'],
            'source' => $in['source'],
        ]);
    }

    /** Summary of a mod for the journal. */
    private function describe(Mod $mod): array
    {
        return [
            'id' => $mod->id,
            'name' => $mod->name,
            'modrinth_project_id' => $mod->modrinth_project_id,
            'curseforge_project_id' => $mod->curseforge_project_id,
        ];
    }

    /** Connection of the referential's models (Mod's, shared by ModFile). */
    private function connection(): ConnectionInterface
    {
        return (new Mod())->getConnection();
    }

    /**
     * Checks and shapes the received data. Returns the normalized array, or
     * the refusal reason (text).
     */
    private function validate(string $game, array $project, array $file, string $source): array|string
    {
        $game = strtolower(trim($game));
        if ($game === '' || ! $this->gameMapService->isConfigured($game)) {
            return "unknown game ({$game})";
        }

        $normalizer = $this->gameMapService->normalizer($game);
        if (! $this->normalizer->has($normalizer)) {
            return "game without a filename normalizer ({$game})";
        }

        if (! in_array($source, ModFile::SOURCES, true)) {
            return "unknown source ({$source})";
        }

        // Optional (only required to create a mod); empty = absent.
        $name = $project['name'] ?? null;
        if ($name !== null && (! is_string($name) || ! mb_check_encoding($name, 'UTF-8'))) {
            return 'invalid mod name';
        }
        $name = $name !== null && trim($name) !== '' ? mb_substr(trim($name), 0, 255, 'UTF-8') : null;

        $modrinthId = $project['modrinth_project_id'] ?? null;
        if ($modrinthId !== null && (! is_string($modrinthId) || preg_match(self::MODRINTH_ID, $modrinthId) !== 1)) {
            return 'invalid Modrinth project id';
        }

        $curseforgeId = $this->unsignedInt($project['curseforge_project_id'] ?? null, 1, self::UINT32_MAX);
        if ($curseforgeId === false) {
            return 'invalid CurseForge project id';
        }

        if ($modrinthId === null && $curseforgeId === null) {
            return 'no project id';
        }

        $filename = $file['filename'] ?? null;
        if (! is_string($filename) || $filename === '' || strlen($filename) > 255
            || ! mb_check_encoding($filename, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $filename) !== 0) {
            return 'invalid filename';
        }

        // Empty normalized key (official name reduced to the extension, e.g.
        // `.jar`): it could never be compared without false positives.
        $filenameKey = $this->normalizer->key($filename, $normalizer);
        if ($filenameKey === '') {
            return 'empty normalized key';
        }

        $size = $this->unsignedInt($file['size'] ?? null, 0, PHP_INT_MAX);
        if ($size === null || $size === false) {
            return 'invalid size';
        }

        $sha1 = is_string($file['sha1'] ?? null) ? strtolower($file['sha1']) : '';
        if (preg_match(self::SHA1, $sha1) !== 1) {
            return 'invalid SHA1';
        }

        $fingerprint = $this->unsignedInt($file['cf_fingerprint'] ?? null, 0, self::UINT32_MAX);
        if ($fingerprint === false) {
            return 'invalid CurseForge fingerprint';
        }

        $versionId = $file['modrinth_version_id'] ?? null;
        if ($versionId !== null && (! is_string($versionId) || preg_match(self::MODRINTH_ID, $versionId) !== 1)) {
            return 'invalid Modrinth version id';
        }

        $fileId = $this->unsignedInt($file['curseforge_file_id'] ?? null, 1, self::UINT32_MAX);
        if ($fileId === false) {
            return 'invalid CurseForge file id';
        }

        return [
            'game' => $game,
            'normalizer' => $normalizer,
            'source' => $source,
            'name' => $name,
            'modrinth_project_id' => $modrinthId,
            'curseforge_project_id' => $curseforgeId,
            'filename' => $filename,
            'filename_key' => $filenameKey,
            'size' => $size,
            'sha1' => $sha1,
            'cf_fingerprint' => $fingerprint,
            'modrinth_version_id' => $versionId,
            'curseforge_file_id' => $fileId,
        ];
    }

    /**
     * Addon log that never throws: an unusable Laravel log must not make a
     * referential write fail (or roll it back).
     */
    private function log(string $level, string $message, array $context = []): void
    {
        try {
            AddonBrowserLogger::{$level}($message, $context);
        } catch (\Throwable $ex) {
            // Nothing more to do.
        }
    }

    /**
     * Integer between $min and $max (PHP integer or digit string, as in some
     * API responses). Null if absent, false if invalid.
     */
    private function unsignedInt(mixed $value, int $min, int $max): int|null|false
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) && ctype_digit($value) && strlen($value) <= 19) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < $min || $value > $max) {
            return false;
        }

        return $value;
    }
}
