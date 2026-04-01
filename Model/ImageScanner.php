<?php
declare(strict_types=1);

namespace Taurus\ImageCleaner\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;

class ImageScanner
{
    private const MEDIA_PATH = 'catalog/product';
    private const EXCLUDED_DIRS = [
        'watermark',
        'tmp',
        'placeholder'
    ];
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    private WriteInterface $mediaDirectory;
    private WriteInterface $varDirectory;

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(
        private readonly Filesystem $filesystem
    ) {
        $this->mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $this->varDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
    }

    /**
     * Generator that yields relative paths of images found in catalog/product directory
     *
     * @return \Generator<string>
     */
    public function getImages(): \Generator
    {
        $fullPath = $this->mediaDirectory->getAbsolutePath(self::MEDIA_PATH);
        if (!is_dir($fullPath)) {
            return;
        }

        $directoryIterator = new \RecursiveDirectoryIterator(
            $fullPath,
            \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS
        );

        $iterator = new \RecursiveIteratorIterator(
            $directoryIterator,
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relativePath = $this->getRelativePath($file->getPathname(), $fullPath);

            if ($this->shouldExclude($relativePath)) {
                continue;
            }

            if (!$this->isImage($file)) {
                continue;
            }

            yield $relativePath;
        }
    }

    /**
     * Get relative path from absolute pathname and base path
     *
     * @param string $pathname
     * @param string $basePath
     * @return string
     */
    private function getRelativePath(string $pathname, string $basePath): string
    {
        $path = str_replace($basePath, '', $pathname);
        return '/' . ltrim($path, '/');
    }

    /**
     * Determine if a file path should be excluded from scanning
     *
     * @param string $relativePath
     * @return bool
     */
    private function shouldExclude(string $relativePath): bool
    {
        $parts = explode('/', ltrim($relativePath, '/'));

        // Check if any of the excluded directories are in the path
        foreach ($parts as $part) {
            if (in_array($part, self::EXCLUDED_DIRS)) {
                return true;
            }
        }

        // If it's a cached image, it looks like /cache/hash/a/b/image.jpg
        if ($parts[0] === 'cache') {
            // A valid cache path should have at least 4 parts: cache, hash, a, b, image.jpg
            if (count($parts) < 5) {
                return true;
            }
            // Check if it follows /[a-z0-9]/[a-z0-9]/ pattern after the hash
            $subPath = '/' . implode('/', array_slice($parts, 2));
            if (!preg_match('#^/[a-z0-9]/[a-z0-9]/#i', $subPath)) {
                return true;
            }
            return false;
        }

        // Also check if it matches pub/media/catalog/product/[a-z0-9]/[a-z0-9]/*
        // The relative path starts AFTER catalog/product/
        // So a valid path looks like /a/b/image.jpg
        if (!preg_match('#^/[a-z0-9]/[a-z0-9]/#i', $relativePath)) {
            return true;
        }

        return false;
    }

    /**
     * Check if a file is an image based on its extension
     *
     * @param \SplFileInfo $file
     * @return bool
     */
    private function isImage(\SplFileInfo $file): bool
    {
        $extension = strtolower($file->getExtension());
        return in_array($extension, self::ALLOWED_EXTENSIONS);
    }

    /**
     * Get the original product image path from a cached image path
     *
     * @param string $relativePath
     * @return string
     */
    public function getOriginalPath(string $relativePath): string
    {
        if (str_starts_with(ltrim($relativePath, '/'), 'cache/')) {
            $parts = explode('/', ltrim($relativePath, '/'));
            // Remove 'cache' and the hash
            return '/' . implode('/', array_slice($parts, 2));
        }
        return $relativePath;
    }

    private const TRASH_PATH = 'image-cleaner-trash';

    /**
     * Move an unused image to the trash directory in var/
     *
     * @param string $relativePath
     * @return bool
     */
    public function moveToTrash(string $relativePath): bool
    {
        $sourcePath = self::MEDIA_PATH . $relativePath;
        if (!$this->mediaDirectory->isExist($sourcePath)) {
            return false;
        }

        $targetPath = self::TRASH_PATH . $relativePath;

        // Ensure target directory exists in var/
        $targetDir = dirname($targetPath);
        if (!$this->varDirectory->isDirectory($targetDir)) {
            $this->varDirectory->create($targetDir);
        }

        $absoluteSource = $this->mediaDirectory->getAbsolutePath($sourcePath);
        $absoluteTarget = $this->varDirectory->getAbsolutePath($targetPath);

        return rename($absoluteSource, $absoluteTarget);
    }
}
