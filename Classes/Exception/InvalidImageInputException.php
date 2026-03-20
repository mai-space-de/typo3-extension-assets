<?php

declare(strict_types = 1);

namespace Maispace\MaiAssets\Exception;

/**
 * Thrown when an unsupported value is passed as the `image` argument.
 *
 * Accepted image inputs are:
 *  - int or numeric string  → sys_file_reference UID
 *  - \TYPO3\CMS\Core\Resource\File
 *  - \TYPO3\CMS\Core\Resource\FileReference
 *  - non-empty string path  → EXT: notation or public-relative path
 *
 * Anything else — null, object of unknown type, empty string — triggers this
 * exception with a message that includes the actual PHP type received.
 */
class InvalidImageInputException extends \InvalidArgumentException
{
}
