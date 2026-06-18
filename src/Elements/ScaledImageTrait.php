<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

/**
 * Shared helper for blocks with an `Image` has_one and an optional `MaxWidth`
 * field: returns the image scaled to MaxWidth (for an absolute-URL <img>), or
 * the original if no width is set.
 */
trait ScaledImageTrait
{
    public function ScaledImage()
    {
        $image = $this->Image();

        if (!$image || !$image->exists()) {
            return null;
        }

        if ((int) $this->MaxWidth > 0) {
            return $image->ScaleWidth((int) $this->MaxWidth);
        }

        return $image;
    }
}
