<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\Command;

use Maispace\MaispaceAssets\Service\CriticalAssetService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(
                'Visits every page of each configured TYPO3 site using a headless Chromium instance,' . PHP_EOL
                . 'captures CSS coverage at both mobile and desktop viewports, filters to rules that' . PHP_EOL
                . 'apply to elements visible above the fold, and stores the result in the TYPO3' . PHP_EOL
                . 'caching framework cache.' . PHP_EOL
                . PHP_EOL
                . 'The CriticalCssInlineMiddleware reads those cached entries on every request and' . PHP_EOL
                . 'injects them as inline <style>/<script> blocks immediately before </head>.' . PHP_EOL
                . PHP_EOL
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
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $chromiumBin = (string)($input->getOption('chromium-bin') ?? '');
        $siteFilter  = $input->getOption('site');
        $pagesFilter = $input->getOption('pages');
        $connectMs   = (int)($input->getOption('connect-timeout') ?? 5000);
        $loadMs      = (int)($input->getOption('page-timeout') ?? 15000);

        $viewports = [
            'mobile' => [
                'width'  => (int)($input->getOption('mobile-width') ?? 375),
                'height' => (int)($input->getOption('mobile-height') ?? 667),
            ],
            'desktop' => [
                'width'  => (int)($input->getOption('desktop-width') ?? 1440),
                'height' => (int)($input->getOption('desktop-height') ?? 900),
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
        if (is_string($pagesFilter) && $pagesFilter !== '') {
            $pageUidFilter = array_values(array_filter(
                array_map(static fn (string $s): int => (int)trim($s), explode(',', $pagesFilter)),
                static fn (int $uid): bool => $uid > 0,
            ));
        }

        // ── Process sites ─────────────────────────────────────────────────────
        $totalOk = 0;
        $errors  = 0;

        foreach ($sites as $site) {
            $siteId = $site->getIdentifier();
            $io->section("Site: {$siteId}");

            $pages = $this->collectPages($site, $pageUidFilter);

            if ($pages === []) {
                $io->note("No pages found for site '{$siteId}'.");
                continue;
            }

            $io->writeln(sprintf('  Processing %d page(s) × %d viewport(s)…', count($pages), count($viewports)));
            $io->newLine();

            foreach ($pages as ['uid' => $pageUid, 'url' => $pageUrl]) {
                $io->write(sprintf('  [%d] %s … ', $pageUid, $pageUrl));

                try {
                    $this->criticalAssetService->extractForPage(
                        $pageUid,
                        $pageUrl,
                        $chromiumBin,
                        $viewports,
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

        $io->newLine();

        if ($errors > 0) {
            $io->warning(sprintf('%d page(s) failed. Check TYPO3 system logs for details.', $errors));
        }

        $io->success(sprintf(
            'Done. %d page(s) processed successfully across %d viewport(s).',
            $totalOk,
            count($viewports),
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Collect page UID + URL pairs for a site.
     *
     * When $uidFilter is empty, only the site root page (language 0) is included.
     * When $uidFilter is set, only those UIDs are processed (with URLs generated via the site router).
     *
     * @param list<int> $uidFilter
     * @return list<array{uid: int, url: string}>
     */
    private function collectPages(Site $site, array $uidFilter): array
    {
        $pages = [];

        if ($uidFilter === []) {
            // Default: process only the root page of the default language.
            $rootUid = $site->getRootPageId();
            $rootUrl = rtrim((string)$site->getBase(), '/') . '/';
            $pages[] = ['uid' => $rootUid, 'url' => $rootUrl];
        } else {
            foreach ($uidFilter as $uid) {
                try {
                    $router = $site->getRouter();
                    $uri    = $router->generateUri($uid);
                    $pages[] = ['uid' => $uid, 'url' => (string)$uri];
                } catch (\Throwable $e) {
                    // Page UID may not belong to this site — silently skip.
                    continue;
                }
            }
        }

        return $pages;
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
