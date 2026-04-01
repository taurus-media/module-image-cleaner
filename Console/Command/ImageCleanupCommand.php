<?php
declare(strict_types=1);

namespace Taurus\ImageCleaner\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Taurus\ImageCleaner\Model\ImageScanner;
use Taurus\ImageCleaner\Model\UsedImageIndex;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;

class ImageCleanupCommand extends Command
{
    private const OPTION_DELETE = 'delete';
    private const OPTION_BATCH_SIZE = 'batch-size';
    private const OPTION_SLEEP = 'sleep';
    private const OPTION_OUTPUT_FILE = 'output-file';

    /**
     * @param ImageScanner $imageScanner
     * @param UsedImageIndex $usedImageIndex
     * @param Filesystem $filesystem
     * @param string|null $name
     */
    public function __construct(
        private readonly ImageScanner $imageScanner,
        private readonly UsedImageIndex $usedImageIndex,
        private readonly Filesystem $filesystem,
        string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('catalog:image:cleanup')
            ->setDescription('Cleanup unused product images from pub/media/catalog/product')
            ->addOption(
                self::OPTION_DELETE,
                null,
                InputOption::VALUE_NONE,
                'Actually move images to trash'
            )
            ->addOption(
                self::OPTION_BATCH_SIZE,
                null,
                InputOption::VALUE_REQUIRED,
                'Batch size for processing',
                '5000'
            )
            ->addOption(
                self::OPTION_SLEEP,
                null,
                InputOption::VALUE_REQUIRED,
                'Seconds between batches',
                '0'
            )
            ->addOption(
                self::OPTION_OUTPUT_FILE,
                null,
                InputOption::VALUE_REQUIRED,
                'Optional log of unused images'
            );

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $isDelete = $input->getOption(self::OPTION_DELETE);
        $batchSize = (int)$input->getOption(self::OPTION_BATCH_SIZE);
        $sleep = (int)$input->getOption(self::OPTION_SLEEP);
        $outputFilePath = $input->getOption(self::OPTION_OUTPUT_FILE);

        if ($isDelete) {
            $confirmation = $io->confirm('Are you sure you want to MOVE unused images to trash? This can be reverted from var/image-cleaner-trash/.', false);
            if (!$confirmation) {
                $io->warning('Cleanup cancelled.');
                return Command::SUCCESS;
            }
        } else {
            $io->info('Running in DRY-RUN mode. No images will be moved.');
        }

        try {
            $io->info('Building used images index...');
            $this->usedImageIndex->buildIndex();

            $io->info('Scanning filesystem for unused images...');

            $scanned = 0;
            $used = 0;
            $unused = 0;
            $movedToTrash = 0;
            $unusedList = [];

            foreach ($this->imageScanner->getImages() as $imagePath) {
                $scanned++;

                $checkPath = $this->imageScanner->getOriginalPath($imagePath);

                if ($this->usedImageIndex->isImageUsed($checkPath)) {
                    $used++;
                } else {
                    $unused++;
                    $unusedList[] = $imagePath;

                    if ($isDelete) {
                        try {
                            if ($this->imageScanner->moveToTrash($imagePath)) {
                                $movedToTrash++;
                            }
                        } catch (\Exception $e) {
                            $io->error(sprintf('Failed to move %s to trash: %s', $imagePath, $e->getMessage()));
                        }
                    }

                    if (count($unusedList) >= $batchSize) {
                        $this->handleBatch($unusedList, $outputFilePath, $io);
                        $unusedList = [];
                        if ($sleep > 0) {
                            sleep($sleep);
                        }
                    }
                }

                if ($scanned % 1000 === 0) {
                    $output->write('.');
                }
            }

            // Final batch
            $this->handleBatch($unusedList, $outputFilePath, $io);

            $io->newLine(2);
            $io->success('Cleanup process completed.');
            $io->table(
                ['Metric', 'Count'],
                [
                    ['Total Scanned', $scanned],
                    ['Used', $used],
                    ['Unused', $unused],
                    ['Moved to Trash', $movedToTrash]
                ]
            );

            if ($outputFilePath) {
                $io->info(sprintf('Unused images list saved to: %s', $outputFilePath));
            }

        } catch (\Exception $e) {
            $io->error('An error occurred: ' . $e->getMessage());
            return Command::FAILURE;
        } finally {
            $this->usedImageIndex->cleanup();
        }

        return Command::SUCCESS;
    }

    /**
     * Process batch of unused images (logging/sleeping)
     *
     * @param array $unusedList
     * @param string|null $outputFilePath
     * @param SymfonyStyle $io
     * @return void
     */
    private function handleBatch(array $unusedList, ?string $outputFilePath, SymfonyStyle $io): void
    {
        if (empty($unusedList)) {
            return;
        }

        if ($outputFilePath) {
            try {
                $varDir = $this->filesystem->getDirectoryWrite('var');
                $stream = $varDir->openFile($outputFilePath, 'a');
                foreach ($unusedList as $path) {
                    $stream->write($path . PHP_EOL);
                }
            } catch (\Exception $e) {
                $io->error('Failed to write to output file: ' . $e->getMessage());
            }
        }
    }
}
