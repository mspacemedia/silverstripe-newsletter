<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Service;

use MSpaceMedia\Newsletter\Elements\NewsletterBlockElemental;
use MSpaceMedia\Newsletter\Model\NewsletterBrand;
use MSpaceMedia\Newsletter\Model\NewsletterIssue;
use MSpaceMedia\Newsletter\Model\NewsletterSubscriber;
use Pelago\Emogrifier\CssInliner;
use Pelago\Emogrifier\HtmlProcessor\HtmlPruner;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
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
        $html = $issue->hasSentSnapshot()
            ? (string) $issue->SentHTML
            : $this->renderSnapshot($issue);

        return $this->finaliseSnapshot($html, $issue, $subscriber, $trackingToken);
    }

    /**
     * Render the issue for the public view-online page (no personalisation).
     */
    public function renderWeb(NewsletterIssue $issue): string
    {
        $html = $issue->hasSentSnapshot()
            ? (string) $issue->SentHTML
            : $this->renderSnapshot($issue);

        return $this->finaliseSnapshot($html, $issue);
    }

    /**
     * Render the locked, pre-merge HTML. This freezes block content/layout while
     * leaving MailChimp-style merge tags to be resolved per recipient later.
     */
    public function renderSnapshot(NewsletterIssue $issue): string
    {
        $brand = $issue->EffectiveBrand();
        $body = $this->renderBlocks($issue, $brand, '*|VIEWONLINE|*', '*|UNSUB|*');
        $html = (string) SSViewer::create(self::WRAPPER_TEMPLATE)->process($issue->customise([
            'Body' => $body,
            'Brand' => $brand,
            'ViewOnlineLink' => '*|VIEWONLINE|*',
            'TypographyCSS' => DBHTMLText::create()->setValue($this->typographyCss($brand)),
            'ResponsiveCSS' => DBHTMLText::create()->setValue($this->responsiveCss($brand)),
            'TrackingPixel' => DBHTMLText::create()->setValue(''),
        ]));

        return $this->inlineCss($html);
    }

    public function finaliseSnapshot(
        string $html,
        NewsletterIssue $issue,
        ?NewsletterSubscriber $subscriber = null,
        ?string $trackingToken = null
    ): string {
        $viewOnline = $trackingToken
            ? Director::absoluteURL('newsletter/viewrecord/' . $trackingToken)
            : $issue->Link();
        $unsubscribe = $subscriber
            ? Director::absoluteURL('newsletter/unsubscribe/' . $subscriber->UnsubscribeToken)
            : '#';

        $html = $this->resolveMergeTags($html, $subscriber, $viewOnline, $unsubscribe);
        // Resolve the {{ … }} computed-field engine per recipient, after the
        // legacy *|…|* tags. Kept out of renderSnapshot() so the locked SentHTML
        // stays generic and personalises at delivery time.
        $html = MergeFieldService::create()->render($html, $subscriber);
        if ($trackingToken) {
            $html = $this->injectTrackingPixel($html, $trackingToken);
            $html = $this->rewriteTrackedLinks($html, $trackingToken);
        }

        return $html;
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
        $weight = $brand->HeadingWeight();
        $css .= "h1,h2,h3,h4,h5,h6{font-family:$heading;color:$headingColor;line-height:1.25;margin:0 0 12px;font-weight:$weight;}";
        for ($i = 1; $i <= 6; $i++) {
            $css .= "h$i{font-size:{$sizes[$i]}px;}";
        }
        $css .= "p{font-size:{$sizes[0]}px;line-height:1.5;margin:0 0 12px;}";
        $css .= "a{color:$link;}";
        $css .= 'ul,ol{margin:0 0 12px;padding-left:24px;}li{margin:0 0 4px;}';

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
     * Rules for the wrapper's small-screen media query: large headings step
     * down so a long H1 doesn't break word-by-word on a phone.
     */
    private function responsiveCss(NewsletterBrand $brand): string
    {
        $sizes = $brand->headingSizes();
        $css = '';
        foreach ([1 => 0.8, 2 => 0.85, 3 => 0.9] as $level => $factor) {
            $size = max(16, (int) round($sizes[$level] * $factor));
            $css .= ".nl-h$level{font-size:{$size}px !important;}";
        }

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

    private function injectTrackingPixel(string $html, string $token): string
    {
        $pixel = $this->trackingPixel($token);
        $withPixel = preg_replace('/(<\/body>)/i', $pixel . '$1', $html, 1);

        return $withPixel ?? $html . $pixel;
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
                ->removeRedundantClassesAfterCssInlined($inliner);

            return $inliner->render();
        } catch (\Throwable $e) {
            // Still send, but make the failure visible: un-inlined CSS is lost
            // by clients that strip <style> (Outlook, many webmail apps).
            Injector::inst()->get(LoggerInterface::class)->warning(
                'Newsletter CSS inlining failed; sending un-inlined HTML: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return $html;
        }
    }
}
