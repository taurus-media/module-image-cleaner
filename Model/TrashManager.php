<?php
declare(strict_types=1);

namespace Taurus\ImageCleaner\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Psr\Log\LoggerInterface;

class TrashManager
{
    private const TRASH_PATH = 'image-cleaner-trash';
    private const RETENTION_DAYS = 7;

    private WriteInterface $varDirectory;

    /**
     * @param Filesystem $filesystem
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger
    ) {
        $this->varDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
    }

    /**
     * Purge files from trash directory that are older than RETENTION_DAYS.
     *
     * @return int Number of deleted files
     */
    public function purgeOldTrash(): int
    {
        $trashPath = $this->varDirectory->getAbsolutePath(self::TRASH_PATH);
        if (!is_dir($trashPath)) {
            return 0;
        }

        $deletedCount = 0;
        $now = time();
        $retentionSeconds = self::RETENTION_DAYS * 24 * 60 * 60;

        try {
            $directoryIterator = new \RecursiveDirectoryIterator(
                $trashPath,
                \RecursiveDirectoryIterator::SKIP_DOTS
            );
            $iterator = new \RecursiveIteratorIterator(
                $directoryIterator,
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    if (($now - $file->getMTime()) > $retentionSeconds) {
                        if (unlink($file->getPathname())) {
                            $deletedCount++;
                        }
                    }
                } elseif ($file->isDir()) {
                    // Try to remove empty directories
                    @rmdir($file->getPathname());
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error during Taurus_ImageCleaner trash purge: ' . $e->getMessage());
        }

        return $deletedCount;
    }
}
