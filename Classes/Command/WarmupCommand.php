<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Command;

use Maispace\MaiAssets\Collector\SvgSpriteCollector;
use Maispace\MaiAssets\Configuration\ExtensionConfigurationDiscovery;
use Maispace\MaiAssets\Exception\AssetException;
use Maispace\MaiAssets\Processing\MinificationProcessor;
use Maispace\MaiAssets\Processing\ScssProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(
    name: 'maispace:assets:warmup',
    description: 'Pre-warms asset caches (SVG sprites, SCSS compilation) at deploy time.',
)]
final class WarmupCommand extends Command
{
    public function __construct(
        private readonly ExtensionConfigurationDiscovery $extensionConfigurationDiscovery,
        private readonly SvgSpriteCollector $svgSpriteCollector,
        private readonly ScssProcessor $scssProcessor,
        private readonly MinificationProcessor $minificationProcessor,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('mai_assets cache warmup');

        $processed = 0;
        $cacheHits = 0;
        $errors = 0;

        // 1. Prime SVG sprite cache by triggering build()
        $io->section('SVG sprite icons');
        try {
            $icons = $this->extensionConfigurationDiscovery->discoverSpriteIcons();
            $io->text(sprintf('Discovered %d icon(s).', count($icons)));

            $sprite = $this->svgSpriteCollector->build();
            if ($sprite !== '') {
                $io->text('SVG sprite built successfully.');
                $processed++;
            } else {
                $io->text('No icons registered — sprite is empty.');
            }
        } catch (AssetException $e) {
            $io->error('SVG sprite warmup failed: ' . $e->getMessage());
            $errors++;
        }

        // 2. Warm up SCSS/CSS assets from TypoScript warmup.assets setting
        $io->section('CSS/SCSS assets');
        $warmupAssets = $this->resolveWarmupAssets();

        if ($warmupAssets === []) {
            $io->text('No warmup assets configured in plugin.tx_maispace_assets.settings.warmup.assets.');
        }

        foreach ($warmupAssets as $assetPath) {
            $resolved = GeneralUtility::getFileAbsFileName($assetPath);
            if ($resolved === '' || !file_exists($resolved)) {
                $io->warning(sprintf('Asset not found, skipping: "%s"', $assetPath));
                $errors++;
                continue;
            }

            $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
            $content = (string)file_get_contents($resolved);

            try {
                if ($ext === 'scss') {
                    $content = $this->scssProcessor->process($content, $resolved);
                    $io->text(sprintf('[SCSS] Compiled: %s', $assetPath));
                } else {
                    $content = $this->minificationProcessor->process($content, $resolved);
                    $io->text(sprintf('[CSS]  Minified: %s', $assetPath));
                }
                $processed++;
            } catch (AssetException $e) {
                $io->error(sprintf('Failed to process "%s": %s', $assetPath, $e->getMessage()));
                $errors++;
            }
        }

        $io->success(sprintf(
            'Warmup complete. Processed: %d, Errors: %d.',
            $processed,
            $errors
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Read warmup asset paths from TypoScript setup array if available.
     *
     * @return array<int, string>
     */
    private function resolveWarmupAssets(): array
    {
        // TypoScript is not available in CLI context without a full frontend bootstrap.
        // Fall back to reading from TYPO3_CONF_VARS if pre-populated, or return empty.
        $assets = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['mai_assets']['warmup']['assets'] ?? [];
        if (!is_array($assets)) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $assets)));
    }
}
