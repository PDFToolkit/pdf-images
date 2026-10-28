<?php

declare(strict_types=1);

namespace PDF\Images;

/** PNG 3 section 11.3.2.3: a Latin-1 name and a bounded zlib RGB or grayscale profile. */
final readonly class PngProfileReader
{
    public function __construct(private ImageImportLimits $limits)
    {
    }

    public function read(string $data, PngHeader $header, ImageImportBudget $budget): ImageIccProfile
    {
        $separator = strpos($data, "\0");
        if ($separator === false || $separator < 1 || $separator > 79 || strlen($data) < $separator + 3) {
            throw new \InvalidArgumentException('PNG ICC profile name or compressed framing is invalid.');
        }
        $name = substr($data, 0, $separator);
        if (preg_match('/^[\x20-\x7e\xa1-\xff]+$/D', $name) !== 1 || trim($name, ' ') !== $name
            || str_contains($name, '  ') || $data[$separator + 1] !== "\0") {
            throw new \InvalidArgumentException('PNG ICC profile name or compression method is invalid.');
        }
        $bytes = (new ImageDataInflater())->decode(substr($data, $separator + 2), $this->limits->maxProfileBytes, $budget);
        return new ImageIccProfile($bytes, $header->colorType->colorSpace()->components(), $this->limits->maxProfileTags);
    }
}
