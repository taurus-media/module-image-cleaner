<?php
declare(strict_types=1);

namespace Taurus\ImageCleaner\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Zend_Db_Expr;

class UsedImageIndex
{
    private const TABLE_NAME = 'taurus_image_cleaner_used_images';

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {}

    /**
     * Create a temporary table to store used image paths
     *
     * @return void
     * @throws \Zend_Db_Exception
     */
    public function createTemporaryTable(): void
    {
        $connection = $this->getConnection();
        if ($connection->isTableExists(self::TABLE_NAME)) {
            $connection->dropTable(self::TABLE_NAME);
        }

        $table = $connection->newTable(self::TABLE_NAME)
            ->addColumn(
                'image_path',
                \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                255,
                ['nullable' => false, 'primary' => true],
                'Normalized Image Path'
            )
            ->addIndex(
                $connection->getIndexName(self::TABLE_NAME, ['image_path']),
                ['image_path']
            )
            ->setComment('Temporary table for used images');

        $connection->createTable($table);
    }

    /**
     * Insert a batch of image paths into the temporary table
     *
     * @param array $paths
     * @return void
     */
    public function insertBatch(array $paths): void
    {
        if (empty($paths)) {
            return;
        }

        $data = [];
        foreach ($paths as $path) {
            $data[] = ['image_path' => $path];
        }

        $this->getConnection()->insertOnDuplicate(
            self::TABLE_NAME,
            $data,
            ['image_path']
        );
    }

    /**
     * Check if an image path exists in the used images index
     *
     * @param string $path
     * @return bool
     */
    public function isImageUsed(string $path): bool
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from(self::TABLE_NAME, 'image_path')
            ->where('image_path = ?', $path);

        return (bool)$connection->fetchOne($select);
    }

    /**
     * Drop the temporary table
     *
     * @return void
     */
    public function dropTemporaryTable(): void
    {
        $connection = $this->getConnection();
        if ($connection->isTableExists(self::TABLE_NAME)) {
            $connection->dropTable(self::TABLE_NAME);
        }
    }

    /**
     * Get database connection
     *
     * @return AdapterInterface
     */
    private function getConnection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    /**
     * Collect the list of images in the media gallery that are assigned to any product
     *
     * @param int $batchSize
     * @param int $offset
     * @return array
     */
    public function getUsedImagesFromMediaGallery(int $batchSize, int $offset): array
    {
        $connection = $this->getConnection();

        $valueSelect = $connection->select()
            ->from(
                $this->resource->getTableName('catalog_product_entity_media_gallery_value'),
                ['value_id', 'entity_id' => new Zend_Db_Expr('GROUP_CONCAT(entity_id)')]
            )
            ->group('value_id');

        $valueToEntitySelect = $connection->select()
            ->from(
                $this->resource->getTableName('catalog_product_entity_media_gallery_value_to_entity'),
                ['value_id', 'entity_id' => new Zend_Db_Expr('GROUP_CONCAT(entity_id)')]
            )
            ->group('value_id');

        $select = $connection->select()
            ->from(
                ['g' => $this->resource->getTableName('catalog_product_entity_media_gallery')],
                ['value']
            )
            ->joinLeft(
                ['gv' => $valueSelect],
                'gv.value_id = g.value_id',
                []
            )
            ->joinLeft(
                ['gve' => $valueToEntitySelect],
                'gve.value_id = g.value_id',
                []
            )
            ->where('gv.entity_id IS NOT NULL')
            ->orWhere('gve.entity_id IS NOT NULL')
            ->limit($batchSize, $offset);

        return $connection->fetchCol($select);
    }

    /**
     * Get used images from product varchar attributes
     *
     * @param int $batchSize
     * @param int $offset
     * @param array $attributeIds
     * @return array
     */
    public function getUsedImagesFromVarchar(int $batchSize, int $offset, array $attributeIds): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('catalog_product_entity_varchar'), ['value'])
            ->where('attribute_id IN (?)', $attributeIds)
            ->where('value IS NOT NULL')
            ->where("value != 'no_selection'")
            ->limit($batchSize, $offset);

        return $connection->fetchCol($select);
    }

    /**
     * Get attribute IDs by frontend input type
     *
     * @param array $inputs
     * @return array
     */
    public function getAttributeIdsByFrontendInput(array $inputs): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('eav_attribute'), ['attribute_id'])
            ->where('entity_type_id = ?', $this->getEntityTypeId('catalog_product'))
            ->where('frontend_input IN (?)', $inputs);

        return $connection->fetchCol($select);
    }

    /**
     * Get entity type ID by code
     *
     * @param string $code
     * @return int
     */
    private function getEntityTypeId(string $code): int
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('eav_entity_type'), ['entity_type_id'])
            ->where('entity_type_code = ?', $code);

        return (int)$connection->fetchOne($select);
    }
}
