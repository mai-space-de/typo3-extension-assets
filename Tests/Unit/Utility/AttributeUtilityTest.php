<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Utility;

use Maispace\MaiAssets\Utility\AttributeUtility;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AttributeUtilityTest extends TestCase
{
    #[Test]
    public function normalizeScriptAttributesConvertsBooleanAsync(): void
    {
        $attributes = ['async' => true];
        $result = AttributeUtility::normalizeScriptAttributes($attributes);
        
        self::assertSame('async', $result['async']);
    }

    #[Test]
    public function normalizeScriptAttributesConvertsBooleanDefer(): void
    {
        $attributes = ['defer' => true];
        $result = AttributeUtility::normalizeScriptAttributes($attributes);
        
        self::assertSame('defer', $result['defer']);
    }

    #[Test]
    public function normalizeScriptAttributesConvertsBooleanNomodule(): void
    {
        $attributes = ['nomodule' => true];
        $result = AttributeUtility::normalizeScriptAttributes($attributes);
        
        self::assertSame('nomodule', $result['nomodule']);
    }

    #[Test]
    public function normalizeScriptAttributesPreservesNonBooleanAttributes(): void
    {
        $attributes = ['type' => 'module', 'src' => 'test.js'];
        $result = AttributeUtility::normalizeScriptAttributes($attributes);
        
        self::assertSame('module', $result['type']);
        self::assertSame('test.js', $result['src']);
    }

    #[Test]
    public function normalizeScriptAttributesRemovesFalseBooleanAttributes(): void
    {
        $attributes = ['async' => false, 'defer' => false];
        $result = AttributeUtility::normalizeScriptAttributes($attributes);
        
        self::assertFalse($result['async']);
        self::assertFalse($result['defer']);
    }

    #[Test]
    public function normalizeCssAttributesConvertsBooleanDisabled(): void
    {
        $attributes = ['disabled' => true];
        $result = AttributeUtility::normalizeCssAttributes($attributes);
        
        self::assertSame('disabled', $result['disabled']);
    }

    #[Test]
    public function normalizeCssAttributesPreservesNonBooleanAttributes(): void
    {
        $attributes = ['media' => 'screen', 'rel' => 'stylesheet'];
        $result = AttributeUtility::normalizeCssAttributes($attributes);
        
        self::assertSame('screen', $result['media']);
        self::assertSame('stylesheet', $result['rel']);
    }

    #[Test]
    public function normalizeCssAttributesRemovesFalseDisabled(): void
    {
        $attributes = ['disabled' => false];
        $result = AttributeUtility::normalizeCssAttributes($attributes);
        
        self::assertFalse($result['disabled']);
    }

    #[Test]
    public function normalizeScriptAttributesHandlesEmptyArray(): void
    {
        $result = AttributeUtility::normalizeScriptAttributes([]);
        
        self::assertSame([], $result);
    }

    #[Test]
    public function normalizeCssAttributesHandlesEmptyArray(): void
    {
        $result = AttributeUtility::normalizeCssAttributes([]);
        
        self::assertSame([], $result);
    }
}
