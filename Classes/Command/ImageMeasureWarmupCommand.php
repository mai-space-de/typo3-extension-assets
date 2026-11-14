<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Command;

use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementKey;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementProfileResolver;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementRepository;
use Maispace\MaiAssets\Service\ImageVariantService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Resource\ResourceFactory;

#[AsCommand(
    name: 'maispace:assets:image-measure:warmup',
    description: 'Pre-generates the image variants for all measured image slots and prunes stale measurements.',
)]
final class ImageMeasureWarmupCommand extends Command
{
    public function __construct(
        private readonly ImageMeasurementRepository $repository,
        private readonly ImageMeasurementProfileResolver $profileResolver,
        private readonly ImageVariantService $imageVariantService,
        private readonly ResourceFactory $resourceFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'keep-days',
            null,
            InputOption::VALUE_REQUIRED,
            'Delete measurements that were not updated for this many days (0 = never)',
            '180',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('mai_assets image measurement warmup');

        $keepDays = max(0, (int) $input->getOption('keep-days'));
        if ($keepDays > 0) {
            $pruned = $this->repository->deleteOlderThan(time() - $keepDays * 86400);
            $io->writeln(sprintf('Pruned %d stale measurement rows.', $pruned));
        }

        $slots = 0;
        $variants = 0;
        $skipped = 0;

        foreach ($this->repository->getSlotKeys() as $slotKey) {
            if (ImageMeasurementKey::isSharedSlot($slotKey)) {
                // Shared layout slots know no file; their variants are created on first render.
                $skipped++;
                continue;
            }

            $parsed = ImageMeasurementKey::parse($slotKey);
            if ($parsed === null) {
                $this->repository->deleteForSlot($slotKey);
                continue;
            }

            try {
                $file = $parsed['type'] === 'r'
                    ? $this->resourceFactory->getFileReferenceObject($parsed['uid'])
                    : $this->resourceFactory->getFileObject($parsed['uid']);
            } catch (\Throwable) {
                // File or reference is gone: its measurements are useless.
                $this->repository->deleteForSlot($slotKey);
                $skipped++;
                continue;
            }

            $profile = $this->profileResolver->resolve($slotKey, ImageMeasurementKey::originalWidth($file));
            if ($profile === null) {
                $skipped++;
                continue;
            }

            $result = $this->imageVariantService->processVariants($file, $profile->breakpoints());
            $variants += count($result);
            $slots++;
            $io->writeln(sprintf('%s: %s', $slotKey, implode(', ', $profile->widths)), OutputInterface::VERBOSITY_VERBOSE);
        }

        $io->success(sprintf('Warmed %d image slots (%d width variants), skipped %d.', $slots, $variants, $skipped));

        return Command::SUCCESS;
    }
}
