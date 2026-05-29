<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Command;

use Maispace\MaiAssets\Exception\AssetException;
use Maispace\MaiAssets\Service\CriticalCssRegressionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(
    name: 'maispace:assets:check-critical-css-regression',
    description: 'Check critical CSS byte size against baseline for Core Web Vitals compliance.',
)]
final class CheckCriticalCssRegressionCommand extends Command
{
    public function __construct(
        private readonly CriticalCssRegressionService $regressionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'update-baseline',
            null,
            InputOption::VALUE_NONE,
            'Update the baseline with current measurements (instead of checking against it)'
        );
        $this->addOption(
            'file',
            'f',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Specific files to check (syntax: identifier:path). Can be used multiple times. Example: mai-theme-main:EXT:mai_theme/Resources/Public/Scss/main.scss'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Critical CSS Regression Check (Core Web Vitals)');

        $updateBaseline = (bool)$input->getOption('update-baseline');
        $fileSpecs = (array)$input->getOption('file');

        // Default files to check if none specified
        if ($fileSpecs === []) {
            $fileSpecs = [
                'mai-theme-main:EXT:mai_theme/Resources/Public/Scss/main.scss',
                'mai-theme-bundle:EXT:mai_theme/Resources/Private/StyleSheets/bundle.scss',
            ];
        }

        $measurements = [];
        $failures = [];

        foreach ($fileSpecs as $spec) {
            [$identifier, $path] = $this->parseFileSpec($spec);
            if ($identifier === null || $path === null) {
                $io->warning(sprintf('Invalid file specification: %s', $spec));
                continue;
            }

            $absolutePath = GeneralUtility::getFileAbsFileName($path);
            if ($absolutePath === '' || !file_exists($absolutePath)) {
                $io->warning(sprintf('File not found: %s', $path));
                continue;
            }

            try {
                $size = $this->regressionService->measureCriticalCssSize($absolutePath);
                $measurements[$identifier] = $size;

                if ($updateBaseline) {
                    $this->regressionService->setBaselineEntry($identifier, $size);
                    $io->text(sprintf(
                        '[%s] Baseline updated: %d bytes',
                        $identifier,
                        $size
                    ));
                } else {
                    $result = $this->regressionService->checkRegressionForFile($identifier, $absolutePath);
                    if (!$result['passed']) {
                        $failures[$identifier] = $result;
                        $io->error(sprintf(
                            '[%s] REGRESSION DETECTED: %d bytes (baseline: %d, exceeded by %d)',
                            $identifier,
                            $result['actual'],
                            $result['baseline'],
                            $result['exceeded_by']
                        ));
                    } else {
                        $baseline = $result['baseline'];
                        if ($baseline !== null) {
                            $io->text(sprintf(
                                '[%s] OK: %d bytes (baseline: %d, %d bytes under)',
                                $identifier,
                                $result['actual'],
                                $baseline,
                                $baseline - $result['actual']
                            ));
                        } else {
                            $io->text(sprintf(
                                '[%s] OK: %d bytes (no baseline)',
                                $identifier,
                                $result['actual']
                            ));
                        }
                    }
                }
            } catch (AssetException $e) {
                $io->error(sprintf('[%s] Error: %s', $identifier, $e->getMessage()));
                $failures[$identifier] = ['error' => $e->getMessage()];
            }
        }

        if ($updateBaseline) {
            $io->success(sprintf('Baseline updated for %d file(s).', count($measurements)));
            return Command::SUCCESS;
        }

        if ($failures !== []) {
            $io->error(sprintf(
                'Critical CSS regression check FAILED: %d file(s) exceeded baseline',
                count($failures)
            ));
            return Command::FAILURE;
        }

        $io->success(sprintf('All %d critical CSS file(s) passed regression check.', count($measurements)));
        return Command::SUCCESS;
    }

    /**
     * Parse a file specification in the format "identifier:path".
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function parseFileSpec(string $spec): array
    {
        $parts = explode(':', $spec, 2);
        if (count($parts) !== 2) {
            return [null, null];
        }

        [$identifier, $path] = $parts;
        return [trim($identifier) ?: null, trim($path) ?: null];
    }
}
