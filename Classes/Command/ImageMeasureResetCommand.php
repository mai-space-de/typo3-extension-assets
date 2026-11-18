<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Command;

use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementKey;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementPageRefresher;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'maispace:assets:image-measure:reset',
    description: 'Deletes image measurements (all, or those of one content element / slot) so they are learned again.',
)]
final class ImageMeasureResetCommand extends Command
{
    public function __construct(
        private readonly ImageMeasurementRepository $repository,
        private readonly ImageMeasurementPageRefresher $pageRefresher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::OPTIONAL, 'Content element uid (e.g. 123) or slot key (e.g. e123.r45, s.textmedia-left)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Delete the measurements of every image slot');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $target = (string) $input->getArgument('target');

        // Pages are looked up before deleting: they still render the old srcset/sizes
        // and fall back to the configured breakpoints once they are re-rendered.
        if ((bool) $input->getOption('all')) {
            $pages = $this->pageRefresher->allPages();

            return $this->finish($io, $this->repository->deleteAll(), $pages);
        }

        if ($target !== '' && ctype_digit($target)) {
            $pages = $this->pageRefresher->pagesOfElement((int) $target);

            return $this->finish($io, $this->repository->deleteForElement((int) $target), $pages);
        }

        if ($target !== '' && ImageMeasurementKey::isValid($target)) {
            $pages = $this->pageRefresher->pagesOfSlot($target);

            return $this->finish($io, $this->repository->deleteForSlot($target), $pages);
        }

        $io->error('Pass --all, a content element uid or a slot key like e123.r45 or s.textmedia-left.');

        return Command::INVALID;
    }

    /**
     * @param list<int> $pages
     */
    private function finish(SymfonyStyle $io, int $deletedRows, array $pages): int
    {
        $refreshed = $deletedRows > 0 ? $this->pageRefresher->refresh($pages) : 0;
        $io->success(sprintf('Deleted %d rows, refreshed %d pages.', $deletedRows, $refreshed));

        return Command::SUCCESS;
    }
}
