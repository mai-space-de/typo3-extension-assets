<?php

declare(strict_types = 1);

namespace Maispace\MaiAssets\Exception;

/**
 * Base exception for all maispace_assets runtime failures.
 *
 * Catch this class to handle any asset-processing error in one place,
 * or catch the more specific sub-classes for targeted recovery.
 */
class AssetException extends \RuntimeException
{
}
