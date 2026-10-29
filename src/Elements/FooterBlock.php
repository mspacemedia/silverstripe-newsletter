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
        return _t(__CLASS__ . '.TYPE', 'Footer');
    }

    /**
     * Footer copy is small print: muted colour, links matching it.
     */
    protected function richTextColor(): ?string
    {
        return $this->EffectiveMutedColor();
    }

    protected function richTextLinkColor(): ?string
    {
        return $this->safeColor($this->LinkColor) ?: $this->EffectiveMutedColor();
    }

    /**
     * Keep paragraphs at footer size rather than the brand body size, which
     * the inlined typography would otherwise apply.
     */
    protected function richTextTagStyles(string $tag): array
    {
        return in_array($tag, ['p', 'li'], true)
            ? ['font-size' => '12px', 'line-height' => '1.6', 'margin' => '0 0 8px']
            : [];
    }
}
