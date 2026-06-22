<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

/**
 * Shared helper for blocks with an `Image` has_one and an optional `MaxWidth`
 * field: returns the image scaled to the smaller of MaxWidth and the available
 * email content width. Outlook ignores CSS max-width on images, so the rendered
 * width attribute must be safe on its own.
 */
trait ScaledImageTrait
{
    public function ScaledImage()
    {
        $image = $this->Image();

        if (!$image || !$image->exists()) {
            return null;
        }

        $targetWidth = $this->EmailImageTargetWidth((int) $image->getWidth());
        if ((int) $image->getWidth() > $targetWidth) {
            return $image->ScaleWidth($targetWidth);
        }

        return $image;
    }

    public function EmailImageTargetWidth(?int $sourceWidth = null): int
    {
        $brand = $this->getRenderBrand();
        $brandWidth = $brand ? (int) $brand->ContentWidth : 600;
        $availableWidth = $brandWidth ?: 600;

        if (!$this->FullWidth) {
            $availableWidth -= max(0, (int) $this->PaddingLeft) + max(0, (int) $this->PaddingRight);
        }

        $targetWidth = max(1, $availableWidth);

        if ((int) $this->MaxWidth > 0) {
            $targetWidth = min($targetWidth, (int) $this->MaxWidth);
        }

        if ($sourceWidth && $sourceWidth > 0) {
            $targetWidth = min($targetWidth, $sourceWidth);
        }

        return max(1, $targetWidth);
    }
}
