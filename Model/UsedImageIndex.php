<?php
declare(strict_types=1);

namespace Taurus\ImageCleaner\Model;

use Taurus\ImageCleaner\Model\ResourceModel\UsedImageIndex as ResourceModel;
use Psr\Log\LoggerInterface;

class UsedImageIndex
{
    private const BATCH_SIZE = 10000;

    /**
     * @param ResourceModel $resourceModel
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceModel $resourceModel,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Collect all the product images used in DB and add the to the index table
     *
     * @return void
     */
    public function buildIndex(): void
    {
        $this->resourceModel->createTemporaryTable();
        $this->indexMediaGallery();
        $this->indexVarcharAttributes();
    }

    /**
     * @return void
     */
    private function indexMediaGallery(): void
    {
        $offset = 0;
        while (true) {
            $paths = $this->resourceModel->getUsedImagesFromMediaGallery(self::BATCH_SIZE, $offset);
            if (empty($paths)) {
                break;
            }
            $this->resourceModel->insertBatch($this->normalizePaths($paths));
            $offset += self::BATCH_SIZE;
        }
    }

    /**
     * Collect and index image paths from product varchar attributes
     *
     * @return void
     */
    private function indexVarcharAttributes(): void
    {
        $frontendInputs = ['media_image', 'image'];
        $attributeIds = $this->resourceModel->getAttributeIdsByFrontendInput($frontendInputs);

        if (empty($attributeIds)) {
            return;
        }

        $offset = 0;
        while (true) {
            $paths = $this->resourceModel->getUsedImagesFromVarchar(self::BATCH_SIZE, $offset, $attributeIds);
            if (empty($paths)) {
                break;
            }
            $this->resourceModel->insertBatch($this->normalizePaths($paths));
            $offset += self::BATCH_SIZE;
        }
    }

    /**
     * Normalize image paths for consistent database lookup
     *
     * @param array $paths
     * @return array
     */
    private function normalizePaths(array $paths): array
    {
        $normalized = [];
        foreach ($paths as $path) {
            if (!$path || $path === 'no_selection') {
                continue;
            }
            // Ensure path starts with /
            $path = '/' . ltrim((string)$path, '/');
            $normalized[] = $path;
        }
        return array_unique($normalized);
    }

    /**
     * Check if an image is used in any product
     *
     * @param string $path
     * @return bool
     */
    public function isImageUsed(string $path): bool
    {
        return $this->resourceModel->isImageUsed($path);
    }

    /**
     * Cleanup resources after process completion
     *
     * @return void
     */
    public function cleanup(): void
    {
//        $this->resourceModel->dropTemporaryTable();
    }
}
