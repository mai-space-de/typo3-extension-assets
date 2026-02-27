<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\Command;

use Maispace\MaispaceAssets\Registry\FontRegistry;
use Maispace\MaispaceAssets\Registry\SpriteIconRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * CLI command to pre-build the SVG sprite and warm the font registry cache
 * for every configured TYPO3 site.
 *
 * Run at deploy time after cache clearing to eliminate first-request cold-start latency:
 *
 *   php vendor/bin/typo3 maispace:assets:warmup
 *
 * What the command does
 * =====================
 * 1. Triggers `FontRegistry::discover()` by calling `getRegisteredFontKeys()`.
 *    This loads all `Configuration/Fonts.php` files from every loaded extension
 *    into the singleton registry.
 *
 * 2. Calls `SpriteIconRegistry::buildSprite($siteIdentifier)` for each configured
 *    TYPO3 site. This discovers all `Configuration/SpriteIcons.php` files, assembles
 *    the sprite XML for that site, dispatches `AfterSpriteBuiltEvent`, and writes
 *    the result to the `maispace_assets` caching framework cache.
 *
 * The command is safe to run repeatedly — both registries are idempotent and the cache
 * stores results keyed by content hash, so unchanged sprites are not rebuilt.
 *
 * @see SpriteIconRegistry
 * @see FontRegistry
 */
#[AsCommand(
    name: 'maispace:assets:warmup',
    description: 'Pre-build SVG sprites and discover font registrations for all TYPO3 sites.',
)]
final class WarmupCommand extends Command
{
    public function __construct(
        private readonly SpriteIconRegistry $spriteIconRegistry,
        private readonly FontRegistry $fontRegistry,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            'Run this command at deploy time (after cache clearing) to prime the maispace_assets cache.' . PHP_EOL
            . PHP_EOL
            . 'Each TYPO3 site gets its own pre-built SVG sprite keyed by symbol content.' . PHP_EOL
            . 'Font registrations are discovered from all loaded extensions and written' . PHP_EOL
            . 'into the FontRegistry singleton for the duration of the process.' . PHP_EOL
            . PHP_EOL
            . 'The command is idempotent — running it multiple times is safe.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // ── Font discovery ────────────────────────────────────────────────────
        $fontKeys = $this->fontRegistry->getRegisteredFontKeys();
        $io->writeln(sprintf(
            '<info>Font registry:</info> %d font(s) discovered across all extensions.',
            count($fontKeys),
        ));

        if ($fontKeys !== []) {
            foreach ($fontKeys as $key) {
                $io->writeln('  · ' . $key);
            }
        }

        $io->newLine();

        // ── SVG sprite per site ───────────────────────────────────────────────
        $sites = $this->siteFinder->getAllSites();

        if ($sites === []) {
            $io->warning('No TYPO3 sites found — skipping sprite build. Configure at least one site in the TYPO3 backend.');

            return Command::SUCCESS;
        }

        $io->section(sprintf('Building SVG sprite for %d site(s)', count($sites)));

        $errors = 0;

        foreach ($sites as $site) {
            $siteIdentifier = $site->getIdentifier();

            try {
                $sprite = $this->spriteIconRegistry->buildSprite($siteIdentifier);
                $symbolCount = $sprite !== '' ? substr_count($sprite, '<symbol') : 0;

                $io->writeln(sprintf(
                    '  <info>✓</info>  <comment>%s</comment> — %d symbol(s) cached',
                    $siteIdentifier,
                    $symbolCount,
                ));
            } catch (\Throwable $e) {
                $io->writeln(sprintf(
                    '  <error>✗</error>  <comment>%s</comment> — %s',
                    $siteIdentifier,
                    $e->getMessage(),
                ));
                ++$errors;
            }
        }

        $io->newLine();

        if ($errors > 0) {
            $io->warning(sprintf('%d site(s) failed during sprite build. Check TYPO3 logs for details.', $errors));

            return Command::FAILURE;
        }

        $io->success('All sites warmed up successfully.');

        return Command::SUCCESS;
    }
}
