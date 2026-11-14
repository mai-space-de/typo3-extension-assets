<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ImageMeasurement;

/**
 * Identifies one image slot: a content element plus the file reference shown
 * in it. The layout width of an image depends on where it is rendered, not on
 * the file, so the slot (and not the file) is what gets measured.
 */
final class ImageMeasurementKey
{
    /** Element slot "e12.r34" or developer defined layout slot "s.textmedia-left". */
    public const string PATTERN = '/^(e\d{1,10}\.[rf]\d{1,10}|s\.[a-z0-9][a-z0-9_-]{0,31})\z/';

    private const string SLOT_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}\z/';

    public static function isValid(string $key): bool
    {
        return preg_match(self::PATTERN, $key) === 1;
    }

    /**
     * Layout slots are shared by all content elements that use the same name.
     */
    public static function isSharedSlot(string $key): bool
    {
        return str_starts_with($key, 's.');
    }

    /**
     * @param string $slot Optional developer defined layout name; elements using the same name share one profile
     */
    public static function fromImage(object $image, int $elementUid, string $slot = ''): ?string
    {
        $slot = strtolower(trim($slot));
        if ($slot !== '' && preg_match(self::SLOT_PATTERN, $slot) === 1) {
            return 's.' . $slot;
        }

        $resource = self::resolveResource($image);
        if ($resource === null) {
            return null;
        }

        $uid = (int) $resource->getUid();
        if ($uid <= 0) {
            return null;
        }

        // Core FileReference exposes its original file; a plain File does not.
        $type = method_exists($resource, 'getOriginalFile') ? 'r' : 'f';

        return 'e' . max(0, $elementUid) . '.' . $type . $uid;
    }

    /**
     * Pixel width of the original file, 0 when unknown.
     */
    public static function originalWidth(object $image): int
    {
        $resource = self::resolveResource($image);
        if ($resource === null || !method_exists($resource, 'getProperty')) {
            return 0;
        }

        try {
            return max(0, (int) $resource->getProperty('width'));
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array{type: string, uid: int}|null
     */
    public static function parse(string $key): ?array
    {
        if (!self::isValid($key)) {
            return null;
        }
        if (preg_match('/\.([rf])(\d+)\z/', $key, $matches) !== 1) {
            return null;
        }

        return ['type' => $matches[1], 'uid' => (int) $matches[2]];
    }

    private static function resolveResource(object $image): ?object
    {
        $resource = method_exists($image, 'getOriginalResource') ? $image->getOriginalResource() : $image;

        return is_object($resource) && method_exists($resource, 'getUid') ? $resource : null;
    }
}
