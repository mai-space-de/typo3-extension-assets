<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Asset;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Event\BeforeAssetInjectionEvent;
use Maispace\MaiAssets\Processing\MinificationProcessor;
use Maispace\MaiAssets\Processing\ScssProcessor;
use Maispace\MaiAssets\Service\CompiledAssetPublisher;
use Maispace\MaiAssets\Service\SriHashService;
use Maispace\MaiAssets\Traits\FileResolutionTrait;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

final class CssViewHelper extends AbstractViewHelper
{
    use FileResolutionTrait;

    protected $escapeOutput = false;

    public function __construct(
        private readonly ScssProcessor $scssProcessor,
        private readonly MinificationProcessor $minificationProcessor,
        private readonly CompiledAssetPublisher $compiledAssetPublisher,
        private readonly SriHashService $sriHashService,
        private readonly AssetCollector $assetCollector,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    public function initializeArguments(): void
    {
        $this->registerArgument(
            "identifier",
            "string",
            "Deduplication key",
            true,
        );
        $this->registerArgument(
            "src",
            "string",
            "EXT: path or absolute path to CSS/SCSS file",
            true,
        );
        $this->registerArgument(
            "priority",
            "bool",
            "Render in <head> before page CSS",
            false,
            false,
        );
        $this->registerArgument(
            "minify",
            "bool",
            "Override per-call minification",
            false,
            null,
        );
        $this->registerArgument(
            "inline",
            "bool",
            "Embed as <style> block",
            false,
            false,
        );
        $this->registerArgument(
            "media",
            "string",
            "CSS media attribute",
            false,
            "all",
        );
        $this->registerArgument(
            "nonce",
            "string",
            "CSP nonce for inline blocks",
            false,
            "",
        );
        $this->registerArgument(
            "integrity",
            "string",
            "Explicit SRI hash; auto-computed if empty and file is local",
            false,
            "",
        );
        $this->registerArgument(
            "crossorigin",
            "string",
            "CORS attribute",
            false,
            "",
        );
    }

    public function render(): string
    {
        $src = (string) $this->arguments["src"];
        $identifier = (string) $this->arguments["identifier"];
        $inline = (bool) $this->arguments["inline"];
        $priority = (bool) $this->arguments["priority"];
        $media = (string) $this->arguments["media"];
        $nonce = (string) $this->arguments["nonce"];
        $integrity = (string) $this->arguments["integrity"];
        $crossorigin = (string) $this->arguments["crossorigin"];
        $minify =
            $this->arguments["minify"] !== null
                ? (bool) $this->arguments["minify"]
                : $this->extensionConfiguration->isEnableMinification();

        $resolvedPath = $this->requireFile($src);
        $ext = strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION));

        if ($inline) {
            $content = (string) file_get_contents($resolvedPath);

            if (
                $ext === "scss" &&
                $this->extensionConfiguration->isEnableScssProcessing()
            ) {
                $content = $this->scssProcessor->process(
                    $content,
                    $resolvedPath,
                );
            }

            if ($minify) {
                $content = $this->minificationProcessor->process(
                    $content,
                    $resolvedPath,
                );
            }

            $event = new BeforeAssetInjectionEvent(
                $content,
                "css",
                $resolvedPath,
            );
            $this->eventDispatcher->dispatch($event);
            $content = $event->getContent();

            $nonceAttr =
                $nonce !== ""
                    ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"'
                    : "";
            return "<style" . $nonceAttr . ">" . $content . "</style>";
        }

        $resolvedPath = $this->compiledAssetPublisher->publishStylesheet(
            $resolvedPath,
            $minify,
        );
        $publicPath = PathUtility::getAbsoluteWebPath($resolvedPath);

        if ($integrity === "") {
            try {
                $integrity = $this->sriHashService->computeForFile(
                    $resolvedPath,
                );
            } catch (\Exception) {
                $integrity = "";
            }
        }

        $tagAttributes = ["media" => $media];
        if ($integrity !== "") {
            $tagAttributes["integrity"] = $integrity;
        }
        if ($crossorigin !== "") {
            $tagAttributes["crossorigin"] = $crossorigin;
        }

        $options = ["priority" => $priority];

        $this->assetCollector->addStyleSheet(
            $identifier,
            $publicPath,
            $tagAttributes,
            $options,
        );

        return "";
    }
}
