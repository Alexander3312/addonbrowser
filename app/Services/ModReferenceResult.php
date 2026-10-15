<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services;

use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Models\Mod;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Models\ModFile;

/**
 * Result of a ModReferenceService::recordFile() write.
 *
 * - created   : new mod and its file;
 * - updated   : existing mod completed (file added, platform id added, file
 *               details filled in);
 * - merged    : two mods merged (then the file recorded on the kept mod);
 * - unchanged : everything was already known (idempotent write);
 * - conflict  : conflicting ids (a different id already set, merge
 *               impossible, mod of another game): nothing was changed
 *               (`conflict` line in the journal);
 * - rejected  : invalid data (empty normalized key included), game without
 *               a normalizer, missing name when a mod has to be created, same
 *               SHA1 with another size: nothing was changed.
 *
 * `mod` and `file` are set for created, updated, merged and unchanged.
 */
final class ModReferenceResult
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const MERGED = 'merged';
    public const UNCHANGED = 'unchanged';
    public const CONFLICT = 'conflict';
    public const REJECTED = 'rejected';

    public function __construct(
        public readonly string $status,
        public readonly ?Mod $mod = null,
        public readonly ?ModFile $file = null,
        public readonly ?string $reason = null,
    ) {
    }

    /** The file is in the referential (recorded now or already known). */
    public function isRecorded(): bool
    {
        return in_array($this->status, [self::CREATED, self::UPDATED, self::MERGED, self::UNCHANGED], true);
    }
}
