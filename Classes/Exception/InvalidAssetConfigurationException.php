<?php

declare(strict_types = 1);

namespace Maispace\MaiAssets\Exception;

/**
 * Thrown when a Fonts.php or SpriteIcons.php configuration file is invalid.
 *
 * Typical causes:
 *  - The file does not return an array
 *  - A font or sprite entry is missing the required "src" key
 *  - An entry value is not an array
 *  - A font's MIME type cannot be determined and no explicit "type" was provided
 *
 * The exception message identifies the extension key and the specific entry
 * that failed, so the developer can locate and correct the configuration quickly.
 */
class InvalidAssetConfigurationException extends \InvalidArgumentException
{
}
