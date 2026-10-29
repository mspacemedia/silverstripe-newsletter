<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

use DOMDocument;
use DOMElement;
use DNADesign\Elemental\Models\BaseElement;
use MSpaceMedia\Newsletter\Forms\HexColorField;
use MSpaceMedia\Newsletter\Model\NewsletterBrand;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\FieldType\DBHTMLText;

/**
 * Base for every newsletter content block. Holds the appearance settings common
 * to all blocks (padding, alignment, colours, visibility) and exposes
 * getInlineStyle() so templates render consistent inline CSS.
 *
 * It is concrete (SilverStripe's schema builder instantiates every data class,
 * so an abstract DataObject with its own table cannot be built). It is hidden
 * from the "add block" menu via canCreateElement(); concrete subclasses remain
 * selectable.
 */
class NewsletterBlockElemental extends BaseElement
{
    private static string $table_name = 'Newsletter_Block';

    private static array $db = [
        'PaddingTop' => 'Int',
        'PaddingRight' => 'Int',
        'PaddingBottom' => 'Int',
        'PaddingLeft' => 'Int',
        'Alignment' => "Enum('left,center,right','left')",
        // Brand-driven section background: none, the soft accent tint, or the
        // primary colour (with inverse text). BackgroundColor still overrides.
        'Surface' => "Enum('none,accent,primary','none')",
        'FontFamily' => 'Varchar(255)',
        'BackgroundColor' => 'Varchar(7)',
        'TextColor' => 'Varchar(7)',
        'LinkColor' => 'Varchar(7)',
        'FullWidth' => 'Boolean',
        'HideOnMobile' => 'Boolean',
    ];

    private static array $defaults = [
        'PaddingTop' => 10,
        'PaddingRight' => 20,
        'PaddingBottom' => 10,
        'PaddingLeft' => 20,
        'Alignment' => 'left',
        'Surface' => 'none',
    ];

    // Edit inline inside the ElementalArea field. The issue is a DataObject in a
    // ModelAdmin (no page route), so non-inline blocks would link their "edit" to
    // the owning page — which resolves to the homepage. Inline editing keeps all
    // editing within the area field.
    private static bool $inline_editable = true;

    /**
     * Transient brand set by the render service so block style methods can
     * resolve effective (brand-inherited) values without taking template args.
     */
    protected ?NewsletterBrand $renderBrand = null;

    public function setRenderBrand(?NewsletterBrand $brand): static
    {
        $this->renderBrand = $brand;

        return $this;
    }

    public function getRenderBrand(): ?NewsletterBrand
    {
        return $this->renderBrand;
    }

    /**
     * Hide only this base class from the add-block menu — late static binding
     * means concrete subclasses return true and stay selectable.
     */
    public function canCreateElement(): bool
    {
        return static::class !== self::class;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $this->applyNewsletterEditorConfig($fields);

        $fields->removeByName([
            'PaddingTop',
            'PaddingRight',
            'PaddingBottom',
            'PaddingLeft',
            'Alignment',
            'Surface',
            'FontFamily',
            'BackgroundColor',
            'TextColor',
            'LinkColor',
            'FullWidth',
            'HideOnMobile',
        ]);

        $colourNote = _t(
            __CLASS__ . '.COLOUR_NOTE',
            'Leave blank to inherit the brand/theme default.'
        );

        $fields->addFieldsToTab('Root.Appearance', [
            HeaderField::create('PaddingHeader', _t(__CLASS__ . '.PADDING', 'Padding (px)')),
            NumericField::create('PaddingTop', _t(__CLASS__ . '.TOP', 'Top')),
            NumericField::create('PaddingRight', _t(__CLASS__ . '.RIGHT', 'Right')),
            NumericField::create('PaddingBottom', _t(__CLASS__ . '.BOTTOM', 'Bottom')),
            NumericField::create('PaddingLeft', _t(__CLASS__ . '.LEFT', 'Left')),
        ]);

        // Rich-text blocks defer alignment to TinyMCE (which already offers
        // left/center/right/justify), so we don't impose a conflicting cell-level
        // text-align on them.
        if ($this->usesBlockAlignment()) {
            $fields->addFieldToTab('Root.Appearance', DropdownField::create(
                'Alignment',
                _t(__CLASS__ . '.ALIGNMENT', 'Alignment'),
                [
                    'left' => _t(__CLASS__ . '.ALIGN_LEFT', 'Left'),
                    'center' => _t(__CLASS__ . '.ALIGN_CENTER', 'Center'),
                    'right' => _t(__CLASS__ . '.ALIGN_RIGHT', 'Right'),
                ]
            ));
        }

        $fields->addFieldToTab('Root.Appearance', DropdownField::create(
            'Surface',
            _t(__CLASS__ . '.SURFACE', 'Section style'),
            [
                'none' => _t(__CLASS__ . '.SURFACE_NONE', 'Plain'),
                'accent' => _t(__CLASS__ . '.SURFACE_ACCENT', 'Accent (soft brand tint)'),
                'primary' => _t(__CLASS__ . '.SURFACE_PRIMARY', 'Primary (brand colour, light text)'),
            ]
        )->setDescription(_t(
            __CLASS__ . '.SURFACE_DESCRIPTION',
            'Follows the brand/theme colours. Give consecutive blocks the same style to build a banded section.'
        )));

        $fields->addFieldsToTab('Root.Appearance', [
            HeaderField::create('OverrideHeader', _t(__CLASS__ . '.BRAND_OVERRIDES', 'Brand overrides')),
            TextField::create('FontFamily', _t(__CLASS__ . '.FONT_FAMILY', 'Font family (CSS stack)'))
                ->setDescription($colourNote),
            HexColorField::create('BackgroundColor', _t(__CLASS__ . '.BACKGROUND_COLOUR', 'Background colour'))
                ->setDescription($colourNote),
            HexColorField::create('TextColor', _t(__CLASS__ . '.TEXT_COLOUR', 'Text colour'))
                ->setDescription($colourNote),
            HexColorField::create('LinkColor', _t(__CLASS__ . '.LINK_COLOUR', 'Link colour'))
                ->setDescription($colourNote),
            CheckboxField::create('FullWidth', _t(__CLASS__ . '.FULL_WIDTH', 'Full width (edge to edge)')),
            CheckboxField::create('HideOnMobile', _t(__CLASS__ . '.HIDE_ON_MOBILE', 'Hide on mobile')),
        ]);

        return $fields;
    }

    protected function applyNewsletterEditorConfig(FieldList $fields): void
    {
        $fields->recursiveWalk(static function ($field): void {
            if ($field instanceof HTMLEditorField) {
                $field->setEditorConfig('newsletter');
            }
        });
    }

    /**
     * Effective font stack: this block's override, else the brand's.
     */
    public function EffectiveFont(): string
    {
        $brand = $this->getRenderBrand();

        return $this->FontFamily ?: ($brand ? $brand->BodyFontStack() : 'Arial, Helvetica, sans-serif');
    }

    /**
     * Effective link colour: this block's override, else the brand's.
     */
    public function EffectiveLinkColor(): string
    {
        if ($color = $this->safeColor($this->LinkColor)) {
            return $color;
        }

        if ($this->isInverse()) {
            return $this->inverseColor();
        }

        return $this->safeColor($this->getRenderBrand()?->LinkColor) ?: '#1a73e8';
    }

    /**
     * Effective text colour: this block's override, else the inverse colour on
     * a primary section, else the brand's body text.
     */
    public function EffectiveTextColor(): string
    {
        return $this->resolveTextColor($this->getRenderBrand()?->BodyTextColor, '#333333');
    }

    /**
     * Heading colour, following the same override / section rules as body text.
     */
    public function EffectiveHeadingColor(): string
    {
        return $this->resolveTextColor($this->getRenderBrand()?->HeadingColor, '#111111');
    }

    /**
     * Secondary text colour (captions, footer small print).
     */
    public function EffectiveMutedColor(): string
    {
        return $this->resolveTextColor($this->getRenderBrand()?->FooterTextColor, '#888888');
    }

    /**
     * Small highlight colour for kickers: the link override, else the brand
     * primary (or the inverse colour on a primary section).
     */
    public function EffectiveAccentColor(): string
    {
        if ($color = $this->safeColor($this->LinkColor)) {
            return $color;
        }

        if ($this->isInverse()) {
            return $this->inverseColor();
        }

        return $this->safeColor($this->getRenderBrand()?->PrimaryColor) ?: '#1a73e8';
    }

    /**
     * Effective heading font stack: this block's override, else the brand's.
     */
    public function EffectiveHeadingFont(): string
    {
        $brand = $this->getRenderBrand();

        return $this->FontFamily ?: ($brand ? $brand->HeadingFontStack() : 'Arial, Helvetica, sans-serif');
    }

    private function resolveTextColor(?string $brandColor, string $fallback): string
    {
        if ($color = $this->safeColor($this->TextColor)) {
            return $color;
        }

        if ($this->isInverse()) {
            return $this->inverseColor();
        }

        return $this->safeColor($brandColor) ?: $fallback;
    }

    /**
     * Section background: an explicit colour wins, else the brand surface.
     */
    public function SurfaceColor(): ?string
    {
        if ($color = $this->safeColor($this->BackgroundColor)) {
            return $color;
        }

        $brand = $this->getRenderBrand();

        return match ($this->Surface) {
            'accent' => $this->safeColor($brand?->AccentBackground) ?: '#eef3fb',
            'primary' => $this->safeColor($brand?->PrimaryColor) ?: '#1a73e8',
            default => null,
        };
    }

    /**
     * True on a primary section, where text flips to the brand inverse colour.
     */
    public function isInverse(): bool
    {
        return $this->Surface === 'primary';
    }

    protected function inverseColor(): string
    {
        return $this->safeColor($this->getRenderBrand()?->InverseTextColor) ?: '#ffffff';
    }

    /**
     * Class for the block's outer cell; the wrapper's mobile CSS targets it to
     * tighten side padding on small screens.
     */
    public function BlockClass(): string
    {
        return 'nl-block';
    }

    /**
     * Outlook-safe ("bulletproof") button. The table cell carries the fill and
     * mso-padding-alt so Outlook renders the full shape; the padded link keeps
     * the whole button clickable everywhere else. Colours default to the brand
     * and flip on a primary section so the button never vanishes into it.
     *
     * @param array{bg?: ?string, color?: ?string, radius?: ?int, outline?: bool, fullWidth?: bool} $options
     */
    public function renderButton(string $url, string $label, array $options = []): DBHTMLText
    {
        $brand = $this->getRenderBrand();
        $inverse = $this->isInverse();

        $bg = $this->safeColor($options['bg'] ?? null)
            ?: ($inverse ? $this->inverseColor() : ($this->safeColor($brand?->ButtonColor) ?: '#1a73e8'));
        $color = $this->safeColor($options['color'] ?? null)
            ?: ($inverse
                ? ($this->safeColor($brand?->PrimaryColor) ?: '#1a73e8')
                : ($this->safeColor($brand?->ButtonTextColor) ?: '#ffffff'));
        $radius = (int) ($options['radius'] ?? 0) ?: ($brand ? (int) $brand->ButtonRadius : 4);
        $radius = max(0, $radius);
        $py = $brand ? max(2, (int) $brand->ButtonPaddingY) : 12;
        $px = $brand ? max(2, (int) $brand->ButtonPaddingX) : 24;
        $outline = !empty($options['outline']);
        $fullWidth = !empty($options['fullWidth']);
        $align = $this->safeAlignment();

        // Outline buttons drop the fill and show the button colour as border + text.
        $fill = $outline ? null : $bg;
        $textColor = $outline ? $bg : $color;

        $tableAttrs = '';
        $tableStyle = 'border-collapse:separate;';
        if ($fullWidth) {
            $tableAttrs .= ' width="100%"';
            $tableStyle .= 'width:100%;';
        } elseif ($align !== 'left') {
            $tableAttrs .= ' align="' . $align . '"';
            if ($align === 'center') {
                $tableStyle .= 'margin:0 auto;';
            }
        }

        // The 2px border is part of the button's size, so padding shrinks to match.
        $html = sprintf(
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0"%s style="%s"><tr>'
            . '<td align="center"%s style="border-radius:%dpx;%smso-padding-alt:%dpx %dpx;">'
            . '<a href="%s" target="_blank" class="nl-btn" style="display:%s;padding:%dpx %dpx;'
            . 'font-family:%s;font-size:16px;font-weight:700;line-height:20px;color:%s;'
            . 'text-decoration:none;text-align:center;border:2px solid %s;border-radius:%dpx;">%s</a>'
            . '</td></tr></table>',
            $tableAttrs,
            $tableStyle,
            $fill ? ' bgcolor="' . $fill . '"' : '',
            $radius,
            $fill ? 'background-color:' . $fill . ';' : '',
            $py,
            $px,
            Convert::raw2att($url),
            $fullWidth ? 'block' : 'inline-block',
            $py - 2,
            $px - 2,
            Convert::raw2att($this->EffectiveFont()),
            $textColor,
            $bg,
            $radius,
            Convert::raw2xml($label)
        );

        return DBHTMLText::create()->setValue($html);
    }

    public function RenderedContent(): DBHTMLText
    {
        return $this->renderedRichTextField('Content');
    }

    protected function renderedRichTextField(string $fieldName): DBHTMLText
    {
        return DBHTMLText::create()->setValue(
            $this->applyTextColorToRichText((string) $this->getField($fieldName))
        );
    }

    /**
     * Colour forced onto rich-text elements, or null to keep brand typography.
     */
    protected function richTextColor(): ?string
    {
        return $this->safeColor($this->TextColor) ?: ($this->isInverse() ? $this->inverseColor() : null);
    }

    /**
     * Colour forced onto rich-text links, or null to keep the brand link colour.
     */
    protected function richTextLinkColor(): ?string
    {
        return $this->safeColor($this->LinkColor) ?: ($this->isInverse() ? $this->inverseColor() : null);
    }

    /**
     * Extra inline declarations per rich-text tag (e.g. a smaller paragraph
     * size in the footer), keyed by property.
     *
     * @return array<string, string>
     */
    protected function richTextTagStyles(string $tag): array
    {
        return [];
    }

    /**
     * Push text/link colour overrides (or a primary section's inverse colour)
     * and any per-tag styles onto rich-text elements as inline styles, so they
     * win over the brand typography that Emogrifier inlines later.
     */
    protected function applyTextColorToRichText(string $html): string
    {
        $color = $this->richTextColor();
        $linkColor = $this->richTextLinkColor();
        $hasTagStyles = (bool) array_filter(array_map(
            fn (string $tag): array => $this->richTextTagStyles($tag),
            ['p', 'li', 'a']
        ));
        if ((!$color && !$linkColor && !$hasTagStyles) || trim($html) === '') {
            return $html;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="newsletter-richtext-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $html;
        }

        $root = null;
        foreach ($document->getElementsByTagName('div') as $element) {
            if ($element->getAttribute('id') === 'newsletter-richtext-root') {
                $root = $element;
                break;
            }
        }

        if (!$root) {
            return $html;
        }

        $textTags = [
            'p', 'div', 'span', 'li', 'blockquote',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'strong', 'b', 'em', 'i', 'u', 'small',
            'td', 'th',
        ];

        foreach (iterator_to_array($root->getElementsByTagName('*')) as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($element->tagName);
            $declarations = $this->richTextTagStyles($tag);

            if ($tag === 'a') {
                if ($linkColor) {
                    $declarations['color'] = $linkColor;
                }
            } elseif ($color && in_array($tag, $textTags, true) && !$this->isInsideLink($element)) {
                $declarations['color'] = $color;
            }

            if ($declarations) {
                $element->setAttribute('style', $this->styleWith(
                    $element->getAttribute('style'),
                    $declarations
                ));
            }
        }

        $result = '';
        foreach ($root->childNodes as $childNode) {
            $result .= $document->saveHTML($childNode);
        }

        return $result ?: $html;
    }

    private function isInsideLink(DOMElement $element): bool
    {
        for ($parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if (strtolower($parent->tagName) === 'a') {
                return true;
            }
        }

        return false;
    }

    /**
     * Set (replacing any existing) declarations on an inline style string.
     *
     * @param array<string, string> $declarations
     */
    private function styleWith(string $style, array $declarations): string
    {
        foreach (array_keys($declarations) as $property) {
            $style = preg_replace(
                '/(^|;)\s*' . preg_quote($property, '/') . '\s*:\s*[^;]+;?/i',
                '$1',
                $style
            ) ?? $style;
        }

        $parts = array_filter(array_map('trim', explode(';', $style)));
        foreach ($declarations as $property => $value) {
            $parts[] = $property . ':' . $value;
        }

        return implode(';', $parts) . ';';
    }

    /**
     * Inline heading style at a brand type-scale level, for headings drawn by
     * block templates (e.g. a card title) rather than a Heading block.
     */
    public function HeadingStyle(int $level = 3): string
    {
        $brand = $this->getRenderBrand();
        $level = max(1, min(6, $level));
        $size = $brand ? $brand->headingSizes()[$level] : [1 => 32, 2 => 26, 3 => 22, 4 => 18, 5 => 16, 6 => 14][$level];

        return sprintf(
            'font-family:%s;font-size:%dpx;font-weight:%d;line-height:1.25;color:%s;mso-line-height-rule:exactly;',
            str_replace('"', "'", $this->EffectiveHeadingFont()),
            $size,
            $brand ? $brand->HeadingWeight() : 700,
            $this->EffectiveHeadingColor()
        );
    }

    /**
     * Inline CSS for the block's outer cell — safe to drop into a style="" attr.
     */
    public function getInlineStyle(): string
    {
        $styles = [
            sprintf(
                'padding:%dpx %dpx %dpx %dpx',
                (int) $this->PaddingTop,
                (int) $this->PaddingRight,
                (int) $this->PaddingBottom,
                (int) $this->PaddingLeft
            ),
        ];

        // Only impose cell alignment where the block uses it; rich-text blocks
        // leave alignment to the content (TinyMCE).
        if ($this->usesBlockAlignment()) {
            $styles[] = 'text-align:' . $this->safeAlignment();
        }

        if ($bg = $this->SurfaceColor()) {
            $styles[] = 'background-color:' . $bg;
        }

        if ($this->safeColor($this->TextColor) || $this->isInverse()) {
            $styles[] = 'color:' . $this->EffectiveTextColor();
        }

        return implode(';', $styles);
    }

    public function safeAlignment(): string
    {
        return in_array($this->Alignment, ['left', 'center', 'right'], true)
            ? $this->Alignment
            : 'left';
    }

    /**
     * Whether this block applies a cell-level text-align from its Appearance tab.
     * Rich-text blocks override this to false so TinyMCE controls alignment.
     */
    public function usesBlockAlignment(): bool
    {
        return true;
    }

    /**
     * Validate a stored colour so it can never break out of a style attribute.
     */
    public function safeColor(?string $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^#?[0-9a-fA-F]{3,6}$/', $value) === 1 ? $value : null;
    }
}
