<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EventListener;

use TYPO3\CMS\Core\Localization\Event\ModifyLanguagePacksEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

#[AsEventListener(identifier: 'mai-assets/exclude-from-language-update')]
final readonly class ExcludeExtensionFromLanguageUpdateListener
{
    public function __invoke(ModifyLanguagePacksEvent|\TYPO3\CMS\Install\Service\Event\ModifyLanguagePacksEvent $event): void
    {
        $event->removeExtension('mai_assets');
    }
}
