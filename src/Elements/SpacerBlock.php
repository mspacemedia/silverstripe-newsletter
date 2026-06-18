<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

class SpacerBlock extends NewsletterBlockElemental
{
    private static string $table_name = 'Newsletter_SpacerBlock';

    private static string $icon = 'font-icon-element-vertical';

    private static string $singular_name = 'Spacer';

    private static string $plural_name = 'Spacers';

    private static array $db = [
        'Height' => 'Int',
    ];

    private static array $defaults = [
        'PaddingTop' => 0,
        'PaddingRight' => 0,
        'PaddingBottom' => 0,
        'PaddingLeft' => 0,
        'Height' => 20,
    ];

    public function getType(): string
    {
        return 'Spacer';
    }

    public function SpacerHeight(): int
    {
        return max(1, (int) $this->Height);
    }
}
