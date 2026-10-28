<?php

declare(strict_types=1);

namespace PDF\Images;

/** ICC.1:2004-04 Annex B.5: reassemble APP2 parts by sequence, never physical marker order. */
final class JpegProfileParts
{
    /** @var array<int, string> */
    private array $parts = [];
    private ?int $count = null;
    private int $bytes = 0;

    public function __construct(private readonly ImageImportLimits $limits, private readonly ImageImportBudget $budget)
    {
    }

    public function append(string $payload): void
    {
        if (strlen($payload) < 15 || !str_starts_with($payload, "ICC_PROFILE\0")) {
            throw new \InvalidArgumentException('JPEG ICC segment header or profile data is incomplete.');
        }
        $sequence = ord($payload[12]);
        $count = ord($payload[13]);
        $length = strlen($payload) - 14;
        if ($sequence < 1 || $count < 1 || $sequence > $count || isset($this->parts[$sequence])
            || $this->count !== null && $this->count !== $count) {
            throw new \InvalidArgumentException('JPEG ICC segment numbering is inconsistent or repeated.');
        }
        if ($length > $this->limits->maxProfileBytes - $this->bytes) {
            throw new \InvalidArgumentException('JPEG ICC profile exceeds the configured profile byte limit.');
        }
        $this->budget->reserve($length);
        $this->parts[$sequence] = substr($payload, 14);
        $this->count = $count;
        $this->bytes += $length;
    }

    public function profile(int $components): ?ImageIccProfile
    {
        if ($this->count === null) {
            return null;
        }
        if (count($this->parts) !== $this->count) {
            throw new \InvalidArgumentException('JPEG ICC profile has missing segments.');
        }
        ksort($this->parts);
        $this->budget->reserve($this->bytes);
        return new ImageIccProfile(implode('', $this->parts), $components, $this->limits->maxProfileTags);
    }
}
