<?php

declare(strict_types=1);

namespace FeedForge\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;

/**
 * Base controller for Feed Forge admin controllers.
 * Ensures PS8 + PS9 compatibility by overriding methods that rely on
 * 'prestashop.adapter.legacy.context' (not available in PS8's service locator).
 */
class BaseController extends FrameworkBundleAdminController
{
    public static function getSubscribedServices(): array
    {
        return parent::getSubscribedServices() + [
            'translator' => '?Symfony\\Contracts\\Translation\\TranslatorInterface',
        ];
    }

    /**
     * PS8 safe: use global Context directly instead of service locator.
     */
    protected function getContext()
    {
        return \Context::getContext();
    }

    /**
     * PS8 safe: avoid $this->get('prestashop.adapter.legacy.context') call.
     */
    protected function generateSidebarLink($section, $title = false)
    {
        if (empty($title)) {
            $title = $this->trans('Help', 'Admin.Global');
        }

        $urls = $this->getContext()->smarty->getTemplateVars('help_page_urls') ?? [];

        return $urls[$section] ?? '';
    }
}
