<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\Exception;

/**
 * Thrown when a processed asset cannot be written to the public temp directory.
 *
 * Typical causes:
 *  - Missing write permissions on typo3temp/assets/
 *  - Disk full
 *  - Filesystem errors reported by GeneralUtility::writeFile()
 *
 * The exception message includes the identifier and target path so the
 * file-system issue can be diagnosed quickly.
 */
class AssetWriteException extends AssetException
{
}
