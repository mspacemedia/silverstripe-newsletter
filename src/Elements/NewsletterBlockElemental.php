<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Elements;

use DNADesign\Elemental\Models\BaseElement;
use MSpaceMedia\Newsletter\Forms\HexColorField;
use MSpaceMedia\Newsletter\Model\NewsletterBrand;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextField;

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

        $fields->removeByName([
            'PaddingTop',
            'PaddingRight',
            'PaddingBottom',
            'PaddingLeft',
            'Alignment',
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
        $brand = $this->getRenderBrand();

        return $this->safeColor($this->LinkColor) ?: ($brand && $brand->LinkColor ? $brand->LinkColor : '#1a73e8');
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

        if ($bg = $this->safeColor($this->BackgroundColor)) {
            $styles[] = 'background-color:' . $bg;
        }

        if ($color = $this->safeColor($this->TextColor)) {
            $styles[] = 'color:' . $color;
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
