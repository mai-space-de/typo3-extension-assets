<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EventListener;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceKeyword;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;

#[AsEventListener(identifier: 'mai-assets/csp-mutation')]
final readonly class MutateContentSecurityPolicyListener
{
    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function __invoke(PolicyMutatedEvent $event): void
    {
        if (!$this->extensionConfiguration->isUseDevServer()) {
            return;
        }

        $request = $event->request ?? $GLOBALS['TYPO3_REQUEST'] ?? new ServerRequest();
        $devServerUri = $this->extensionConfiguration->getDevServerUri();

        $host = $devServerUri->getHost();
        $port = $devServerUri->getPort();

        $uris = [
            new UriValue('http://' . $host . ':' . $port),
            new UriValue('https://' . $host . ':' . $port),
            new UriValue('wss://' . $host . ':' . $port),
        ];

        $event->getCurrentPolicy()->extend(Directive::ConnectSrc, ...$uris);
        $event->getCurrentPolicy()->extend(Directive::ScriptSrcElem, ...$uris);
        $event->getCurrentPolicy()->extend(Directive::StyleSrcElem, ...$uris);
        $event->getCurrentPolicy()->extend(Directive::FontSrc, ...$uris);
        $event->getCurrentPolicy()->extend(Directive::ImgSrc, ...$uris);

        if (!$event->getCurrentPolicy()->containsDirective(Directive::ScriptSrcElem, SourceKeyword::unsafeInline)) {
            $event->getCurrentPolicy()->extend(Directive::ScriptSrcElem, SourceKeyword::nonceProxy);
        }
        if (!$event->getCurrentPolicy()->containsDirective(Directive::StyleSrcElem, SourceKeyword::unsafeInline)) {
            $event->getCurrentPolicy()->extend(Directive::StyleSrcElem, SourceKeyword::nonceProxy);
        }
    }
}
