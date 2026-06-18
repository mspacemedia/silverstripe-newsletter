<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Forms;

use TractorCow\Colorpicker\Forms\ColorField;

/**
 * Colour-picker field that round-trips our `#rrggbb` storage.
 *
 * tractorcow's ColorField works with bare 6-hex values (and its validator rejects
 * a leading `#`), whereas the newsletter models store colours as `#rrggbb` and use
 * that directly in CSS. This subclass strips the `#` for the picker/validation and
 * re-adds it when writing back, and stays empty-able so a blank value still means
 * "inherit the brand default".
 */
class HexColorField extends ColorField
{
    public function setValue($value, $data = null)
    {
        return parent::setValue(ltrim((string) $value, '#'), $data);
    }

    public function dataValue()
    {
        $value = parent::dataValue();

        return $value ? '#' . ltrim((string) $value, '#') : $value;
    }
}
