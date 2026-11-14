<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Command;

use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementKey;
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

        if ((bool) $input->getOption('all')) {
            $io->success(sprintf('Deleted %d rows.', $this->repository->deleteAll()));

            return Command::SUCCESS;
        }

        if ($target !== '' && ctype_digit($target)) {
            $io->success(sprintf('Deleted %d rows.', $this->repository->deleteForElement((int) $target)));

            return Command::SUCCESS;
        }

        if ($target !== '' && ImageMeasurementKey::isValid($target)) {
            $io->success(sprintf('Deleted %d rows.', $this->repository->deleteForSlot($target)));

            return Command::SUCCESS;
        }

        $io->error('Pass --all, a content element uid or a slot key like e123.r45 or s.textmedia-left.');

        return Command::INVALID;
    }
}
