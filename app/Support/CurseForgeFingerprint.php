<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support;

/**
 * CurseForge fingerprint of a file: 32-bit MurmurHash2, seed 1, over the
 * content **without** the bytes 0x09, 0x0A, 0x0D and 0x20. CurseForge's only
 * content-based lookup (`POST /v1/fingerprints/{gameId}`). About 30 times more
 * expensive than SHA1 in PHP (~1.3 s for 20 MB): only computed as a last
 * resort (step 5).
 *
 * The computation needs the final length up front: when streaming, add()
 * strips those bytes chunk by chunk and keeps the chunks (MAX_BYTES at most in
 * total, the read ceiling for one file), then value() computes chunk by chunk,
 * freeing each chunk once processed. The chunks are kept in a list rather than
 * concatenated: a growing string gets reallocated (copied) as it grows, which
 * doubled the memory peak (~96 MB measured for 50 MB, ~55 MB with the list).
 *
 * A fingerprint (32 bits, real collisions, easy to forge) is **never proof
 * for writing** to the referential (see ModReferenceService).
 */
class CurseForgeFingerprint
{
    /** Ceiling on accumulated bytes (the one-file read ceiling, 50 MB). */
    public const MAX_BYTES = 50 * 1024 * 1024;

    /** Bytes ignored by CurseForge: tab, line feed, carriage return, space. */
    private const IGNORED = ["\x09" => '', "\x0A" => '', "\x0D" => '', "\x20" => ''];

    /** @var string[] chunks already stripped of the ignored bytes */
    private array $parts = [];

    private int $length = 0;

    private bool $overflow = false;

    private ?int $result = null;

    /**
     * Adds a chunk that was read (ignored bytes stripped). Past MAX_BYTES, the
     * computation is given up. After value(), the chunks are freed: adding one
     * is a programming error (LogicException).
     */
    public function add(string $chunk): void
    {
        if ($this->result !== null) {
            throw new \LogicException('Fingerprint already computed: no more chunks can be added.');
        }
        if ($this->overflow) {
            return;
        }
        $part = strtr($chunk, self::IGNORED);
        if ($part === '') {
            return;
        }
        $this->length += strlen($part);
        if ($this->length > self::MAX_BYTES) {
            $this->overflow = true;
            $this->parts = [];

            return;
        }
        $this->parts[] = $part;
    }

    /**
     * Fingerprint of everything added, or null if the ceiling was exceeded.
     * Same computation as compute(), done chunk by chunk; the result is kept
     * (the chunks are freed along the way).
     */
    public function value(): ?int
    {
        if ($this->overflow) {
            return null;
        }
        if ($this->result !== null) {
            return $this->result;
        }

        $m = 0x5bd1e995;
        $mul = fn (int $a, int $b): int => ((($a & 0xFFFF) * $b) + (((($a >> 16) * $b) & 0xFFFF) << 16)) & 0xFFFFFFFF;

        $h = (1 ^ $this->length) & 0xFFFFFFFF;
        $tail = '';
        foreach (array_keys($this->parts) as $i) {
            // Remainder of the previous chunk (under 4 bytes) + current chunk.
            $data = $tail . $this->parts[$i];
            unset($this->parts[$i]);
            $n = strlen($data) - (strlen($data) % 4);
            for ($off = 0; $off < $n; $off += 65536) {
                foreach (unpack('V*', substr($data, $off, min(65536, $n - $off))) as $k) {
                    $k = $mul($k, $m);
                    $k ^= $k >> 24;
                    $k = $mul($k, $m);
                    $h = $mul($h, $m) ^ $k;
                }
            }
            $tail = substr($data, $n);
        }

        $rem = strlen($tail);
        if ($rem >= 3) {
            $h ^= ord($tail[2]) << 16;
        }
        if ($rem >= 2) {
            $h ^= ord($tail[1]) << 8;
        }
        if ($rem >= 1) {
            $h ^= ord($tail[0]);
            $h = $mul($h, $m);
        }

        $h ^= $h >> 13;
        $h = $mul($h, $m);
        $h ^= $h >> 15;

        return $this->result = $h & 0xFFFFFFFF;
    }

    /**
     * Reference implementation of the algorithm (verified: same value as the
     * API's `fileFingerprint` on real files). `$stripped` = file content
     * already stripped of the ignored bytes.
     */
    public static function compute(string $stripped): int
    {
        $m = 0x5bd1e995;
        // 32-bit multiplication without overflowing PHP's 64-bit integers.
        $mul = fn (int $a, int $b): int => ((($a & 0xFFFF) * $b) + (((($a >> 16) * $b) & 0xFFFF) << 16)) & 0xFFFFFFFF;

        $len = strlen($stripped);
        $h = (1 ^ $len) & 0xFFFFFFFF;
        $n = $len - ($len % 4);

        // In 64 KB blocks: unpack() over 50 MB at once would build an array of
        // 12 million integers.
        for ($off = 0; $off < $n; $off += 65536) {
            foreach (unpack('V*', substr($stripped, $off, min(65536, $n - $off))) as $k) {
                $k = $mul($k, $m);
                $k ^= $k >> 24;
                $k = $mul($k, $m);
                $h = $mul($h, $m) ^ $k;
            }
        }

        $rem = $len % 4;
        if ($rem >= 3) {
            $h ^= ord($stripped[$n + 2]) << 16;
        }
        if ($rem >= 2) {
            $h ^= ord($stripped[$n + 1]) << 8;
        }
        if ($rem >= 1) {
            $h ^= ord($stripped[$n]);
            $h = $mul($h, $m);
        }

        $h ^= $h >> 13;
        $h = $mul($h, $m);
        $h ^= $h >> 15;

        return $h & 0xFFFFFFFF;
    }

    /** Fingerprint of a full content (strips the ignored bytes, then compute()). */
    public static function of(string $content): int
    {
        return self::compute(strtr($content, self::IGNORED));
    }
}
