<?php
declare(strict_types=1);

namespace Taurus\ImageCleaner\Cron;

use Taurus\ImageCleaner\Model\TrashManager;
use Psr\Log\LoggerInterface;

class TrashCleanup
{
    /**
     * @param TrashManager $trashManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly TrashManager $trashManager,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Execute trash cleanup
     */
    public function execute(): void
    {
        $this->logger->info('Taurus_ImageCleaner: Starting trash cleanup cron.');
        $deletedCount = $this->trashManager->purgeOldTrash();
        $this->logger->info(sprintf('Taurus_ImageCleaner: Trash cleanup cron completed. Purged %d files.', $deletedCount));
    }
}
