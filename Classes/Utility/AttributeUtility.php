<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Utility;

final readonly class AttributeUtility
{
    public static function normalizeScriptAttributes(array $attributes): array
    {
        foreach (['async', 'defer', 'nomodule'] as $attr) {
            if (!empty($attributes[$attr])) {
                $attributes[$attr] = $attr;
            }
        }
        return $attributes;
    }

    public static function normalizeCssAttributes(array $attributes): array
    {
        if (!empty($attributes['disabled'])) {
            $attributes['disabled'] = 'disabled';
        }
        return $attributes;
    }
}
