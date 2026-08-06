<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Configuration;

use Maispace\MaiAssets\Service\CompiledAssetPublisher;
use Maispace\MaiAssets\Traits\FileResolutionTrait;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Configuration\Processor\Placeholder\PlaceholderProcessorInterface;
use TYPO3\CMS\Core\Utility\PathUtility;

#[Autoconfigure(public: true)]
final class AssetPlaceholderProcessor implements PlaceholderProcessorInterface
{
    use FileResolutionTrait;

    public function __construct(
        private readonly CompiledAssetPublisher $compiledAssetPublisher,
    ) {}

    public function canProcess(string $placeholder, array $referenceArray): bool
    {
        return str_starts_with($placeholder, '%mai_asset(');
    }

    public function process(string $value, array $referenceArray): string
    {
        if (!preg_match('/^%mai_asset\([\'"]?([^\'")]+)[\'"]?\)%$/', $value, $matches)) {
            return '';
        }

        $assetPath = $matches[1];

        try {
            $absolutePath = $this->requireFile($assetPath);
        } catch (\Exception) {
            return '';
        }

        $compiledPath = $this->compiledAssetPublisher->publishStylesheet($absolutePath);
        return PathUtility::getAbsoluteWebPath($compiledPath);
    }
}
