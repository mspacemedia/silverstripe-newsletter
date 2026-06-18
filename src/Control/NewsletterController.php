<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Control;

use MSpaceMedia\Newsletter\Model\NewsletterIssue;
use MSpaceMedia\Newsletter\Model\NewsletterSendRecord;
use MSpaceMedia\Newsletter\Model\NewsletterSubscriber;
use MSpaceMedia\Newsletter\Service\NewsletterRenderService;
use MSpaceMedia\Newsletter\Service\NewsletterSubscriptionManager;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;

/**
 * Public endpoints: "view online" (renders an issue from its URLToken) and
 * one-click unsubscribe (flips a subscriber to Unsubscribed by token).
 */
class NewsletterController extends Controller
{
    private static array $allowed_actions = [
        'view',
        'unsubscribe',
        'open',
        'click',
        'preview',
    ];

    /**
     * View an issue in the browser.
     */
    public function view(HTTPRequest $request): HTTPResponse
    {
        $token = (string) $request->param('Token');

        return Versioned::withVersionedMode(function () use ($token) {
            Versioned::set_stage(Versioned::DRAFT);

            $issue = NewsletterIssue::get()->filter('URLToken', $token)->first();

            if (!$issue) {
                return $this->httpError(404, 'Newsletter not found.');
            }

            $response = HTTPResponse::create(NewsletterRenderService::create()->renderWeb($issue));
            $response->addHeader('Content-Type', 'text/html; charset=utf-8');

            return $response;
        });
    }

    /**
     * Admin-only live preview of an issue by ID (current saved state, incl. draft).
     * Used by the docked preview panel in the CMS editor.
     */
    public function preview(HTTPRequest $request)
    {
        if (!Permission::check('MANAGE_NEWSLETTERS')) {
            return $this->httpError(403);
        }

        $id = (int) $request->param('Token');

        return Versioned::withVersionedMode(function () use ($id) {
            Versioned::set_stage(Versioned::DRAFT);

            $issue = NewsletterIssue::get()->byID($id);

            if (!$issue) {
                return $this->httpError(404, 'Newsletter not found.');
            }

            $response = HTTPResponse::create(NewsletterRenderService::create()->renderWeb($issue));
            $response->addHeader('Content-Type', 'text/html; charset=utf-8');

            return $response;
        });
    }

    /**
     * One-click unsubscribe. Suppression is global per subscriber.
     */
    public function unsubscribe(HTTPRequest $request)
    {
        $token = (string) $request->param('Token');
        $subscriber = NewsletterSubscriber::get()->filter('UnsubscribeToken', $token)->first();

        if (!$subscriber) {
            return $this->httpError(404, 'Unknown unsubscribe link.');
        }

        NewsletterSubscriptionManager::create()->unsubscribe($subscriber->Email);

        return $this
            ->customise(['Email' => $subscriber->Email])
            ->renderWith(['MSpaceMedia\\Newsletter\\UnsubscribeConfirmation']);
    }

    /**
     * Open-tracking pixel. URL carries the per-send token (with a .png suffix);
     * always returns a 1×1 transparent GIF regardless of match.
     */
    public function open(HTTPRequest $request): HTTPResponse
    {
        $token = preg_replace('/\.[a-z0-9]+$/i', '', (string) $request->param('Token'));

        if ($token) {
            $record = NewsletterSendRecord::get()->filter('Token', $token)->first();
            $record?->recordOpen();
        }

        // 1×1 transparent GIF.
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        $response = HTTPResponse::create($gif);
        $response->addHeader('Content-Type', 'image/gif');
        $response->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->addHeader('Pragma', 'no-cache');

        return $response;
    }

    /**
     * Click redirector. Records the click then 302s to the original URL (?u=).
     */
    public function click(HTTPRequest $request): HTTPResponse
    {
        $token = (string) $request->param('Token');
        $url = (string) $request->getVar('u');

        if ($token) {
            $record = NewsletterSendRecord::get()->filter('Token', $token)->first();
            $record?->recordClick();
        }

        // Only ever redirect to absolute http(s) URLs (no open redirect to JS etc.).
        if (!preg_match('#^https?://#i', $url)) {
            $url = Director::absoluteBaseURL();
        }

        return $this->redirect($url);
    }
}
