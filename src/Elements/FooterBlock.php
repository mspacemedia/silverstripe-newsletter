<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

/**
 * Footer block — renders sender address/legal text plus the unsubscribe and
 * "view online" links. The links are injected by NewsletterRenderService at
 * render time (placeholders $UnsubscribeLink / $ViewOnlineLink in the template).
 */
class FooterBlock extends NewsletterBlockElemental
{
    private static string $table_name = 'Newsletter_FooterBlock';

    private static string $icon = 'font-icon-block-content';

    private static string $singular_name = 'Footer';

    private static string $plural_name = 'Footers';

    private static array $db = [
        'Content' => 'HTMLText',
    ];

    private static array $defaults = [
        'PaddingTop' => 20,
        'PaddingRight' => 20,
        'PaddingBottom' => 20,
        'PaddingLeft' => 20,
        'Alignment' => 'center',
    ];

    public function getType(): string
    {
        return 'Footer';
    }
}
