# Taurus_ImageCleaner

A performance-optimized Magento 2 module to identify and remove unused product images.

## Features

- **Safe for Large Catalogs**: Uses a temporary database table to index used images, ensuring constant-time lookups.
- **Memory Efficient**: Uses PHP Generators and Iterators to stream files without loading them all into memory.
- **Batch Processing**: Supports batching and sleep intervals to minimize system load.
- **Trash-first Policy**: Unused images are moved to `var/image-cleaner-trash/` instead of being deleted immediately.
- **Automatic Cleanup**: A daily cron job automatically purges files from the trash that are older than 7 days.
- **Dry-run by Default**: Safety first—no images are moved unless the `--delete` flag is explicitly provided and confirmed.

## Installation

Place the module code in `app/code/Taurus/ImageCleaner`.

```bash
bin/magento module:enable Taurus_ImageCleaner
bin/magento setup:upgrade
```

## Usage

### Commands

**Basic Dry-Run:**
```bash
bin/magento catalog:image:cleanup
```

**Actually Move Unused Images to Trash:**
```bash
bin/magento catalog:image:cleanup --delete
```

**Advanced Usage:**
```bash
bin/magento catalog:image:cleanup --delete --batch-size=1000 --sleep=1 --output-file=unused_images.log
```

### Options

- `--dry-run`: (Default) Scans and reports unused images without moving them.
- `--delete`: Enables moving unused images to trash (`var/image-cleaner-trash/`). Requires manual confirmation.
- `--batch-size=<int>`: Number of images to process before sleeping/logging (default: 5000).
- `--sleep=<int>`: Seconds to sleep between batches (default: 0).
- `--output-file=<path>`: Path (relative to `var/`) to log the list of unused images.

## Trash & Recovery

When running with `--delete`, images are moved to `var/image-cleaner-trash/`. 
If you accidentally move images that should have been kept, you can move them back to `pub/media/catalog/product/`.

A cron job `taurus_image_cleaner_trash_purge` runs daily at 01:00 and deletes files from the trash that are older than 7 days.

## How it Works

1. **Indexing**: The module creates a temporary table `taurus_image_cleaner_used_images`.
2. **Collection**: It populates this table with image paths from:
   - `catalog_product_entity_media_gallery` (Media gallery entries)
   - `catalog_product_entity_varchar` (Main image, small image, thumbnail, and swatch image attributes)
3. **Scanning**: It uses `RecursiveDirectoryIterator` to scan `pub/media/catalog/product/`.
4. **Validation**: For each file, it performs a fast indexed lookup against the temporary table.
5. **Action**: If an image is not found in the index, it's marked as unused and optionally moved to trash.
6. **Cleanup**: The temporary database table is dropped after the process completes.
7. **Maintenance**: A cron job cleans up the trash folder periodically.

## Safety & Warnings

- **Backup Recommended**: Always back up your `pub/media/catalog/product` directory and your database before running with the `--delete` flag in a production environment.
- **Excluded Directories**: The module automatically ignores `watermark`, `tmp`, `placeholder`, and other temporary directories.
- **Cache Scanning**: The module now also scans the `pub/media/catalog/product/cache` directory. Cached images are considered unused if their corresponding original product image is not found in the database.
- **Normalized Paths**: Only images following the Magento standard structure `pub/media/catalog/product/[a-z0-9]/[a-z0-9]/...` are processed.

## Requirements

- Magento 2.4+
- PHP 8.1+
