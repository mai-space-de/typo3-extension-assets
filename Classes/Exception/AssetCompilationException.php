<?php

declare(strict_types = 1);

namespace Maispace\MaiAssets\Exception;

/**
 * Thrown when an asset cannot be compiled or parsed.
 *
 * Typical causes:
 *  - Invalid SCSS syntax (wraps the underlying SassException)
 *  - Malformed SVG structure that cannot be parsed into a sprite symbol
 *
 * The previous exception is always chained via $previous so the original
 * compiler or parser error is preserved in the stack trace.
 */
class AssetCompilationException extends AssetException
{
}
