<?php

declare(strict_types=1);

namespace PDF\Images;

/** LittleCMS-generated standard profiles, with the exact piecewise sRGB transfer curve. */
final readonly class PngSrgbProfile
{
    private const RGB = 'AAACTGxjbXMEQAAAbW50clJHQiBYWVogB+oACgAcAAAAAAAAYWNzcEFQUEwAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAPbWAAEAAAAA0y1sY21zAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAALZGVzYwAAAQgAAAA2Y3BydAAAAUAAAABMd3RwdAAAAYwAAAAUY2hhZAAAAaAAAAAsclhZWgAAAcwAAAAUYlhZWgAAAeAAAAAUZ1hZWgAAAfQAAAAUclRSQwAAAggAAAAgZ1RSQwAAAggAAAAgYlRSQwAAAggAAAAgY2hybQAAAigAAAAkbWx1YwAAAAAAAAABAAAADGVuVVMAAAAaAAAAHABzAFIARwBCACAAYgB1AGkAbAB0AC0AaQBuAABtbHVjAAAAAAAAAAEAAAAMZW5VUwAAADAAAAAcAE4AbwAgAGMAbwBwAHkAcgBpAGcAaAB0ACwAIAB1AHMAZQAgAGYAcgBlAGUAbAB5WFlaIAAAAAAAAPbWAAEAAAAA0y1zZjMyAAAAAAABDEIAAAXe///zJQAAB5MAAP2Q///7of///aIAAAPcAADAblhZWiAAAAAAAABvoAAAOPUAAAOQWFlaIAAAAAAAACSfAAAPhAAAtsNYWVogAAAAAAAAYpcAALeHAAAY2XBhcmEAAAAAAAMAAAACZmYAAPKnAAANWQAAE9AAAApbY2hybQAAAAAAAwAAAACj1wAAVHsAAEzNAACZmgAAJmYAAA9c';
    private const GRAY = 'AAABbGxjbXMEQAAAbW50ckdSQVlYWVogB+oACgAcAAAAAAAAYWNzcEFQUEwAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAPbWAAEAAAAA0y1sY21zAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEZGVzYwAAALQAAAA2Y3BydAAAAOwAAABMd3RwdAAAATgAAAAUa1RSQwAAAUwAAAAgbWx1YwAAAAAAAAABAAAADGVuVVMAAAAaAAAAHABnAHIAYQB5ACAAYgB1AGkAbAB0AC0AaQBuAABtbHVjAAAAAAAAAAEAAAAMZW5VUwAAADAAAAAcAE4AbwAgAGMAbwBwAHkAcgBpAGcAaAB0ACwAIAB1AHMAZQAgAGYAcgBlAGUAbAB5WFlaIAAAAAAAAPNRAAEAAAABFsxwYXJhAAAAAAADAAAAAmZmAADypwAADVkAABPQAAAKWw==';

    public function read(int $components, int $intent, ImageImportLimits $limits, ImageImportBudget $budget): ImageIccProfile
    {
        if (!in_array($components, [1, 3], true) || $intent < 0 || $intent > 3) {
            throw new \InvalidArgumentException('An sRGB PNG needs gray/RGB components and a defined rendering intent.');
        }
        $encoded = $components === 1 ? self::GRAY : self::RGB;
        $length = intdiv(strlen($encoded), 4) * 3 - (str_ends_with($encoded, '==') ? 2 : (str_ends_with($encoded, '=') ? 1 : 0));
        if ($length > $limits->maxProfileBytes) {
            throw new \InvalidArgumentException('The standard sRGB profile exceeds the configured profile-byte limit.');
        }
        // Base64 decoding and the per-image rendering-intent patch each allocate a profile buffer.
        $budget->reserve($length * 2);
        $bytes = base64_decode($encoded, true);
        if ($bytes === false) {
            throw new \LogicException('The built-in sRGB profile is invalid.');
        }
        $bytes = substr_replace($bytes, pack('N', $intent), 64, 4);
        return new ImageIccProfile($bytes, $components, $limits->maxProfileTags);
    }
}
