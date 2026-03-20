<?php

declare(strict_types = 1);

namespace Maispace\MaiAssets\Exception;

/**
 * Thrown when a required asset source file cannot be located on disk.
 *
 * Typical causes:
 *  - An EXT: path that resolves to a non-existent file
 *  - A public-relative or absolute path that points to a missing file
 *  - A FAL FileReference UID that no longer exists in the database
 *
 * The exception message contains the original path or identifier that was
 * requested, making it easy to trace the template argument that caused the error.
 */
class AssetFileNotFoundException extends AssetException
{
}
