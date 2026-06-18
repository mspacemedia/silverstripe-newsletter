<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

class TextBlock extends NewsletterBlockElemental
{
    private static string $table_name = 'Newsletter_TextBlock';

    private static string $icon = 'font-icon-block-content';

    private static string $singular_name = 'Text';

    private static string $plural_name = 'Text blocks';

    private static array $db = [
        'Content' => 'HTMLText',
    ];

    public function getType(): string
    {
        return 'Text';
    }

    public function usesBlockAlignment(): bool
    {
        return false;
    }
}
