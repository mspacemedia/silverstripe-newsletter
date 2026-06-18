<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Service;

use MSpaceMedia\Newsletter\Elements\NewsletterBlockElemental;
use MSpaceMedia\Newsletter\Model\NewsletterBrand;
use MSpaceMedia\Newsletter\Model\NewsletterIssue;
use MSpaceMedia\Newsletter\Model\NewsletterSubscriber;
use Pelago\Emogrifier\CssInliner;
use Pelago\Emogrifier\HtmlProcessor\HtmlPruner;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\View\SSViewer;

/**
 * Turns a NewsletterIssue's Elemental blocks into a complete, CSS-inlined HTML
 * email. The same render powers the public "view online" page (an email viewed
 * in a browser is the canonical "view online" representation), so there is a
 * single set of block templates rather than parallel web/email variants.
 */
class NewsletterRenderService
{
    use Injectable;

    private const BLOCK_TEMPLATE_NS = 'MSpaceMedia\\Newsletter\\Email\\Blocks\\';

    private const WRAPPER_TEMPLATE = 'MSpaceMedia\\Newsletter\\Email\\Wrapper';

    /**
     * Render the issue for a specific recipient (personalised links + merge tags).
     * When a $trackingToken is supplied, an open-tracking pixel is embedded and
     * outbound links are rewritten through the click redirector.
     */
    public function renderEmail(
        NewsletterIssue $issue,
        ?NewsletterSubscriber $subscriber = null,
        ?string $trackingToken = null
    ): string {
        $viewOnline = $issue->Link();
        $unsubscribe = $subscriber
            ? Director::absoluteURL('newsletter/unsubscribe/' . $subscriber->UnsubscribeToken)
            : '#';

        $brand = $issue->EffectiveBrand();
        $body = $this->renderBlocks($issue, $brand, $viewOnline, $unsubscribe);

        $html = (string) SSViewer::create(self::WRAPPER_TEMPLATE)->process($issue->customise([
            'Body' => $body,
            'Brand' => $brand,
            'ViewOnlineLink' => $viewOnline,
            'TypographyCSS' => DBHTMLText::create()->setValue($this->typographyCss($brand)),
            'TrackingPixel' => DBHTMLText::create()->setValue(
                $trackingToken ? $this->trackingPixel($trackingToken) : ''
            ),
        ]));

        $html = $this->resolveMergeTags($html, $subscriber, $viewOnline, $unsubscribe);

        if ($trackingToken) {
            $html = $this->rewriteTrackedLinks($html, $trackingToken);
        }

        return $this->inlineCss($html);
    }

    /**
     * Render the issue for the public view-online page (no personalisation).
     */
    public function renderWeb(NewsletterIssue $issue): string
    {
        return $this->renderEmail($issue, null);
    }

    /**
     * Concatenate each block's rendered table-row HTML.
     */
    private function renderBlocks(
        NewsletterIssue $issue,
        NewsletterBrand $brand,
        string $viewOnline,
        string $unsubscribe
    ): string {
        $out = '';

        foreach ($issue->ElementalArea()->Elements() as $element) {
            if (!$element instanceof NewsletterBlockElemental) {
                continue;
            }

            $template = self::BLOCK_TEMPLATE_NS . ClassInfo::shortName($element);

            if (!SSViewer::hasTemplate([$template])) {
                continue;
            }

            $element->setRenderBrand($brand);

            $out .= (string) $element->customise([
                'Brand' => $brand,
                'ViewOnlineLink' => $viewOnline,
                'UnsubscribeLink' => $unsubscribe,
            ])->renderWith([$template]);
        }

        return $out;
    }

    /**
     * Replace MailChimp-style *|TAG|* placeholders with recipient data.
     */
    private function resolveMergeTags(
        string $html,
        ?NewsletterSubscriber $subscriber,
        string $viewOnline,
        string $unsubscribe
    ): string {
        $tags = [
            'FNAME' => $subscriber?->FirstName ?? '',
            'LNAME' => $subscriber?->Surname ?? '',
            'EMAIL' => $subscriber?->Email ?? '',
            'UNSUB' => $unsubscribe,
            'VIEWONLINE' => $viewOnline,
        ];

        if ($subscriber) {
            foreach ($subscriber->getMergeArray() as $key => $value) {
                $tags[strtoupper((string) $key)] = (string) $value;
            }
        }

        $replacements = [];
        foreach ($tags as $key => $value) {
            $replacements['*|' . $key . '|*'] = $value;
        }

        return strtr($html, $replacements);
    }

    /**
     * Build the brand typography CSS that Emogrifier inlines onto h1–h6/p (incl.
     * rich-text content), and which carries the Google-font @import for clients
     * that support web fonts. The @import is first so it stays a valid at-rule.
     */
    private function typographyCss(NewsletterBrand $brand): string
    {
        $sizes = $brand->headingSizes();
        $body = $brand->BodyFontStack();
        $heading = $brand->HeadingFontStack();
        $headingColor = $brand->HeadingColor ?: '#111111';
        $bodyColor = $brand->BodyTextColor ?: '#333333';
        $link = $brand->LinkColor ?: '#1a73e8';

        $css = $brand->GoogleFontImport();
        $css .= "body,td,p{font-family:$body;color:$bodyColor;}";
        $css .= "h1,h2,h3,h4,h5,h6{font-family:$heading;color:$headingColor;line-height:1.25;margin:0 0 12px;font-weight:700;}";
        for ($i = 1; $i <= 6; $i++) {
            $css .= "h$i{font-size:{$sizes[$i]}px;}";
        }
        $css .= "p{font-size:{$sizes[0]}px;line-height:1.5;margin:0 0 12px;}";
        $css .= "a{color:$link;}";

        // SilverStripe's TinyMCE applies alignment as classes, not inline styles.
        // Declaring them here lets Emogrifier inline the alignment onto the aligned
        // elements (incl. justify), so rich-text alignment survives into the email.
        $css .= '.text-left{text-align:left;}.text-center{text-align:center;}'
            . '.text-right{text-align:right;}.text-justify{text-align:justify;}'
            . '.left{text-align:left;}.center{text-align:center;}.right{text-align:right;}'
            . 'img.center,figure.center{margin-left:auto;margin-right:auto;}';

        return $css;
    }

    /**
     * 1×1 open-tracking pixel pointing at the click/open endpoint.
     */
    private function trackingPixel(string $token): string
    {
        $src = Director::absoluteURL('newsletter/open/' . $token . '.png');

        return '<img src="' . $src . '" width="1" height="1" alt="" '
            . 'style="display:block;width:1px;height:1px;border:0;overflow:hidden;" />';
    }

    /**
     * Rewrite external http(s) links through the click redirector. Internal
     * newsletter links (unsubscribe/view/open), mailto and anchors are left alone.
     */
    private function rewriteTrackedLinks(string $html, string $token): string
    {
        $base = Director::absoluteURL('newsletter/click/' . $token);

        $result = preg_replace_callback(
            '/href="([^"]+)"/i',
            static function (array $m) use ($base): string {
                $url = $m[1];

                if (!preg_match('#^https?://#i', $url) || str_contains($url, '/newsletter/')) {
                    return $m[0];
                }

                return 'href="' . $base . '?u=' . rawurlencode($url) . '"';
            },
            $html
        );

        return $result ?? $html;
    }

    /**
     * Inline the <style> block onto elements (Gmail/Outlook strip <style>),
     * keeping @media rules. Degrades to the raw HTML if Emogrifier is missing.
     */
    private function inlineCss(string $html): string
    {
        if (!class_exists(CssInliner::class)) {
            return $html;
        }

        try {
            $inliner = CssInliner::fromHtml($html)->inlineCss();
            $dom = $inliner->getDomDocument();
            HtmlPruner::fromDomDocument($dom)
                ->removeElementsWithDisplayNone()
                ->removeRedundantClassesAfterCssInlined($inliner->getMatchingUninlinableSelectors());

            return $inliner->render();
        } catch (\Throwable $e) {
            return $html;
        }
    }
}
