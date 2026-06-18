<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Admin;

use MSpaceMedia\Newsletter\Model\NewsletterAudience;
use MSpaceMedia\Newsletter\Model\NewsletterBrand;
use MSpaceMedia\Newsletter\Model\NewsletterIssue;
use MSpaceMedia\Newsletter\Model\NewsletterSendRecord;
use MSpaceMedia\Newsletter\Model\NewsletterSubscriber;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Versioned\VersionedGridFieldItemRequest;

/**
 * CMS section for composing newsletters, managing audiences (with CSV
 * import/export on each audience's subscriber list) and reviewing send records.
 */
class NewsletterAdmin extends ModelAdmin
{
    private static string $url_segment = 'newsletters';

    private static string $menu_title = 'Newsletters';

    private static string $menu_icon_class = 'font-icon-p-mail';

    private static int $menu_priority = 4;

    private static array $managed_models = [
        NewsletterIssue::class,
        NewsletterAudience::class,
        NewsletterSubscriber::class,
        NewsletterBrand::class,
        NewsletterSendRecord::class,
    ];

    private static array $required_permission_codes = ['MANAGE_NEWSLETTERS'];

    public function getEditForm($id = null, $fields = null)
    {
        $form = parent::getEditForm($id, $fields);

        // The newsletter issue is versioned — use the versioned detail form so it
        // gets Save draft / Publish / Unpublish / Archive + the History panel.
        if ($this->modelClass === NewsletterIssue::class) {
            $grid = $form->Fields()->dataFieldByName($this->sanitiseClassName(NewsletterIssue::class));
            if ($grid instanceof GridField) {
                $detail = $grid->getConfig()->getComponentByType(GridFieldDetailForm::class);
                $detail?->setItemRequestClass(VersionedGridFieldItemRequest::class);
            }
        }

        return $form;
    }
}
