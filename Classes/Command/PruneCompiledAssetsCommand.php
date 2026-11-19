<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Command;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\StaticFileCache\StaticFileRemovalService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Deletes compiled CSS/JS files (typo3temp/assets/mai_assets/compiled/) that are
 * older than the given age. Compiled files are content addressed, so every change
 * leaves the previous file behind.
 *
 * Cached pages may still reference a deleted file. When something was deleted,
 * the page caches (group "pages") and the static HTML files are flushed, so every
 * page is rendered again and recreates the files it really uses.
 */
#[AsCommand(
    name: 'maispace:assets:compiled:prune',
    description: 'Deletes old compiled CSS/JS files and flushes the page caches that may reference them.',
)]
final class PruneCompiledAssetsCommand extends Command
{
    public const string COMPILED_DIR = 'typo3temp/assets/mai_assets/compiled/';

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly CacheManager $cacheManager,
        private readonly StaticFileRemovalService $staticFileRemovalService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'older-than',
            null,
            InputOption::VALUE_REQUIRED,
            'Minimum age in seconds (default: extension setting processingCacheLifetime)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $option = $input->getOption('older-than');
        $maxAge = $option !== null ? (int) $option : $this->extensionConfiguration->getProcessingCacheLifetime();
        if ($maxAge <= 0) {
            $io->error('Pass --older-than=<seconds> or set processingCacheLifetime in the extension configuration.');

            return Command::INVALID;
        }

        $deleted = $this->prune(GeneralUtility::getFileAbsFileName(self::COMPILED_DIR), time() - $maxAge);

        if ($deleted > 0) {
            $this->cacheManager->flushCachesInGroup('pages');
            $this->staticFileRemovalService->purgeAll();
        }

        $io->success(sprintf(
            'Deleted %d compiled file(s)%s.',
            $deleted,
            $deleted > 0 ? ', page caches and static files flushed' : '',
        ));

        return Command::SUCCESS;
    }

    private function prune(string $directory, int $deleteBefore): int
    {
        if ($directory === '' || !is_dir($directory)) {
            return 0;
        }

        $deleted = 0;
        foreach ((array) scandir($directory) as $entry) {
            $path = $directory . $entry;
            if (!is_string($entry) || $entry[0] === '.' || !is_file($path)) {
                continue;
            }
            $modified = filemtime($path);
            if ($modified !== false && $modified < $deleteBefore && unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
