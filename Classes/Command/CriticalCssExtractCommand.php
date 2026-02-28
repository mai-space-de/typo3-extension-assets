<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\Command;

use Doctrine\DBAL\ParameterType;
use Maispace\MaispaceAssets\Service\CriticalAssetService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * CLI command to extract per-page critical CSS and JS for every TYPO3 site.
 *
 * For each page URL it spawns a headless Chromium instance, captures CSS rule
 * usage at the configured mobile and desktop viewports, and stores the
 * above-fold critical CSS/JS in the TYPO3 caching framework cache.
 *
 * The CriticalCssInlineMiddleware then reads those cache entries on every
 * request and injects them as inline <style>/<script> blocks in <head>.
 *
 * Usage
 * =====
 *
 *   # All sites, auto-detected Chromium:
 *   php vendor/bin/typo3 maispace:assets:critical:extract
 *
 *   # Specific site, explicit binary:
 *   php vendor/bin/typo3 maispace:assets:critical:extract \
 *       --site=main --chromium-bin=/usr/bin/chromium-browser
 *
 *   # Specific pages only:
 *   php vendor/bin/typo3 maispace:assets:critical:extract --pages=1,12,42
 *
 *   # Custom viewports:
 *   php vendor/bin/typo3 maispace:assets:critical:extract \
 *       --mobile-width=390 --mobile-height=844 \
 *       --desktop-width=1920 --desktop-height=1080
 *
 * Run this command after every full-page cache flush or major template change.
 * It is idempotent — running it multiple times is safe.
 *
 * @see CriticalAssetService
 * @see \Maispace\MaispaceAssets\Middleware\CriticalCssInlineMiddleware
 */
#[AsCommand(
    name: 'maispace:assets:critical:extract',
    description: 'Extract above-fold critical CSS and JS per page/viewport and cache for inline <head> injection.',
)]
final class CriticalCssExtractCommand extends Command
{
    public function __construct(
        private readonly CriticalAssetService $criticalAssetService,
        private readonly SiteFinder $siteFinder,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(
                'Visits every page of each configured TYPO3 site using a headless Chromium instance,' . "\n"
                . 'captures CSS coverage at both mobile and desktop viewports, filters to rules that' . "\n"
                . 'apply to elements visible above the fold, and stores the result in the TYPO3' . "\n"
                . 'caching framework cache.' . "\n"
                . "\n"
                . 'The CriticalCssInlineMiddleware reads those cached entries on every request and' . "\n"
                . 'injects them as inline <style>/<script> blocks immediately before </head>.' . "\n"
                . "\n"
                . 'Run after deploy and after every full-page cache flush. Idempotent.',
            )
            ->addOption(
                'site',
                null,
                InputOption::VALUE_OPTIONAL,
                'Limit extraction to a single site identifier (default: all sites)',
            )
            ->addOption(
                'pages',
                null,
                InputOption::VALUE_OPTIONAL,
                'Comma-separated list of page UIDs to process (default: site root per language)',
            )
            ->addOption(
                'chromium-bin',
                null,
                InputOption::VALUE_OPTIONAL,
                'Absolute path to the Chromium/Chrome binary (auto-detected if omitted)',
                $this->detectChromium(),
            )
            ->addOption(
                'mobile-width',
                null,
                InputOption::VALUE_OPTIONAL,
                'Mobile viewport width in CSS pixels',
                375,
            )
            ->addOption(
                'mobile-height',
                null,
                InputOption::VALUE_OPTIONAL,
                'Mobile viewport height in CSS pixels',
                667,
            )
            ->addOption(
                'desktop-width',
                null,
                InputOption::VALUE_OPTIONAL,
                'Desktop viewport width in CSS pixels',
                1440,
            )
            ->addOption(
                'desktop-height',
                null,
                InputOption::VALUE_OPTIONAL,
                'Desktop viewport height in CSS pixels',
                900,
            )
            ->addOption(
                'connect-timeout',
                null,
                InputOption::VALUE_OPTIONAL,
                'Chromium startup timeout in milliseconds',
                5000,
            )
            ->addOption(
                'page-timeout',
                null,
                InputOption::VALUE_OPTIONAL,
                'Per-page navigation and load timeout in milliseconds',
                15000,
            )
            ->addOption(
                'workspace',
                null,
                InputOption::VALUE_OPTIONAL,
                'Limit extraction to a specific workspace ID (default: 0 = Live)',
                0,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $chromiumBin = $input->getOption('chromium-bin');
        $chromiumBin = is_string($chromiumBin) ? $chromiumBin : '';

        $siteFilter = $input->getOption('site');
        $pagesFilter = $input->getOption('pages');

        $connectMs = $input->getOption('connect-timeout');
        $connectMs = is_numeric($connectMs) ? (int)$connectMs : 5000;

        $loadMs = $input->getOption('page-timeout');
        $loadMs = is_numeric($loadMs) ? (int)$loadMs : 15000;

        $workspaceId = $input->getOption('workspace');
        $workspaceId = is_numeric($workspaceId) ? (int)$workspaceId : 0;

        $mobileWidth = $input->getOption('mobile-width');
        $mobileHeight = $input->getOption('mobile-height');
        $desktopWidth = $input->getOption('desktop-width');
        $desktopHeight = $input->getOption('desktop-height');

        $viewports = [
            'mobile' => [
                'width'  => is_numeric($mobileWidth) ? (int)$mobileWidth : 375,
                'height' => is_numeric($mobileHeight) ? (int)$mobileHeight : 667,
            ],
            'desktop' => [
                'width'  => is_numeric($desktopWidth) ? (int)$desktopWidth : 1440,
                'height' => is_numeric($desktopHeight) ? (int)$desktopHeight : 900,
            ],
        ];

        // ── Validate Chromium ─────────────────────────────────────────────────
        if ($chromiumBin === '' || !is_executable($chromiumBin)) {
            $io->error([
                'Chromium binary not found or not executable.',
                'Install Chromium/Chrome and pass the path via --chromium-bin=/path/to/chromium.',
                'Detected path: ' . ($chromiumBin !== '' ? $chromiumBin : '(none found)'),
            ]);

            return Command::FAILURE;
        }

        $io->title('Maispace Critical CSS/JS Extraction');
        $io->table(
            ['Setting', 'Value'],
            [
                ['Chromium binary', $chromiumBin],
                ['Mobile viewport', $viewports['mobile']['width'] . 'x' . $viewports['mobile']['height'] . 'px'],
                ['Desktop viewport', $viewports['desktop']['width'] . 'x' . $viewports['desktop']['height'] . 'px'],
                ['Connect timeout', $connectMs . ' ms'],
                ['Page load timeout', $loadMs . ' ms'],
            ],
        );

        // ── Resolve sites ─────────────────────────────────────────────────────
        $sites = $this->siteFinder->getAllSites();

        if (is_string($siteFilter) && $siteFilter !== '') {
            $sites = array_filter(
                $sites,
                static fn (Site $site): bool => $site->getIdentifier() === $siteFilter,
            );

            if ($sites === []) {
                $io->error("Site '{$siteFilter}' not found. Run 'typo3 site:list' to see available sites.");

                return Command::FAILURE;
            }
        }

        // ── Resolve page UID filter ───────────────────────────────────────────
        /** @var list<int> $pageUidFilter */
        $pageUidFilter = [];
        if (is_string($pagesFilter) && (string)$pagesFilter !== '') {
            /** @var non-empty-string $pagesFilterStr */
            $pagesFilterStr = $pagesFilter;
            $exploded = explode(',', $pagesFilterStr);
            $pageUidFilter = array_values(array_filter(
                array_map(static fn (string $s): int => (int)trim($s), $exploded),
                static fn (int $uid): bool => $uid > 0,
            ));
        }

        // ── Process sites ─────────────────────────────────────────────────────
        $totalOk = 0;
        $errors = 0;

        foreach ($sites as $site) {
            $siteId = $site->getIdentifier();
            $io->section("Site: {$siteId}");

            $pageUids = $this->collectPageUids($site, $pageUidFilter);

            if ($pageUids === []) {
                $io->note("No pages found for site '{$siteId}'.");
                continue;
            }

            $languages = $site->getLanguages();
            $numPages = (int)count($pageUids);
            $numLangs = (int)count($languages);
            $numViewports = (int)count($viewports);
            $io->writeln(sprintf('  Processing %d page(s) × %d language(s) × %d viewport(s)…', $numPages, $numLangs, $numViewports));
            $io->newLine();

            foreach ($pageUids as $pageUid) {
                foreach ($languages as $language) {
                    $langId = $language->getLanguageId();
                    try {
                        $router = $site->getRouter();
                        $pageUrl = (string)$router->generateUri($pageUid, ['_language' => $language]);
                    } catch (\Throwable) {
                        // Skip if URL cannot be generated (e.g., page not available in this language)
                        continue;
                    }

                    $io->write(sprintf('  [%d] L:%d %s … ', $pageUid, $langId, $pageUrl));

                    try {
                        $this->criticalAssetService->extractForPage(
                            $pageUid,
                            $pageUrl,
                            $chromiumBin,
                            $viewports,
                            $langId,
                            $workspaceId,
                            $connectMs,
                            $loadMs,
                        );

                        $io->writeln('<info>✓</info>');
                        ++$totalOk;
                    } catch (\Throwable $e) {
                        $io->writeln('<error>✗  ' . $e->getMessage() . '</error>');
                        ++$errors;
                    }
                }
            }
        }

        $io->newLine();

        if ($errors > 0) {
            $io->warning(sprintf('%d page(s) failed. Check TYPO3 system logs for details.', $errors));
        }

        $numViewports = (int)count($viewports);
        $io->success(sprintf('Done. %d page(s) processed successfully across %d viewport(s).', (int)$totalOk, (int)$numViewports));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Collect all page UIDs for a site.
     *
     * When $uidFilter is empty, all pages within the site root are included recursively.
     * When $uidFilter is set, only those UIDs are included.
     *
     * @param list<int> $uidFilter
     *
     * @return list<int>
     */
    private function collectPageUids(Site $site, array $uidFilter): array
    {
        if ($uidFilter !== []) {
            return $uidFilter;
        }

        $rootPageId = $site->getRootPageId();
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $rows = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($rootPageId, ParameterType::INTEGER)),
                    $queryBuilder->expr()->like('recursive_pid_list', $queryBuilder->createNamedParameter('%,' . $rootPageId . ',%')),
                ),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('doktype', $queryBuilder->createNamedParameter(1, ParameterType::INTEGER)), // Standard page
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        if ($rows === []) {
            // Fallback for TYPO3 versions/configurations where recursive_pid_list is not easily usable or empty.
            // Simplified recursive fetch.
            return $this->fetchChildUidsRecursive([$rootPageId]);
        }

        return array_map(static function (array $row): int {
            $uid = $row['uid'] ?? 0;

            return is_numeric($uid) ? (int)$uid : 0;
        }, $rows);
    }

    /**
     * @param list<int> $pids
     *
     * @return list<int>
     */
    private function fetchChildUidsRecursive(array $pids): array
    {
        $allUids = $pids;
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $rows = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($pids, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('doktype', $queryBuilder->createNamedParameter(1, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        if ($rows !== []) {
            $childUids = array_map(static function (array $row): int {
                $uid = $row['uid'] ?? 0;

                return is_numeric($uid) ? (int)$uid : 0;
            }, $rows);
            $allUids = array_merge($allUids, $this->fetchChildUidsRecursive($childUids));
        }

        return $allUids;
    }

    /**
     * Try to auto-detect a usable Chromium binary from well-known locations.
     * Returns an empty string when nothing is found.
     */
    private function detectChromium(): string
    {
        $candidates = [
            'chromium-browser',
            'chromium',
            'google-chrome',
            'google-chrome-stable',
            '/usr/bin/chromium-browser',
            '/usr/bin/chromium',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Chromium.app/Contents/MacOS/Chromium',
        ];

        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }

            $found = shell_exec('which ' . escapeshellarg($candidate) . ' 2>/dev/null');
            if (is_string($found) && $found !== '') {
                return trim($found);
            }
        }

        return '';
    }
}
