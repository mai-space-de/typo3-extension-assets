<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Service;

use Maispace\MaiAssets\Service\ScssDependencyHasher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScssDependencyHasherTest extends TestCase
{
    private string $tempDir;

    private ScssDependencyHasher $subject;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/mai_assets_scss_hash_' . uniqid('', true);
        mkdir($this->tempDir . '/partials', 0777, true);
        $this->subject = new ScssDependencyHasher();
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $file->isDir() ? rmdir($path) : unlink($path);
        }
        rmdir($this->tempDir);
    }

    #[Test]
    public function hashChangesWhenImportedPartialChanges(): void
    {
        $partial = $this->tempDir . '/partials/_grid.scss';
        $entry = $this->tempDir . '/bundle.scss';
        file_put_contents($partial, ".grid{display:block}\n");
        file_put_contents($entry, "@import \"partials/grid\";\n");

        $before = $this->subject->hash($entry);

        file_put_contents($partial, ".grid{display:grid}\n");
        $after = $this->subject->hash($entry);

        self::assertNotSame($before, $after);
    }

    #[Test]
    public function hashIgnoresCommentedImports(): void
    {
        $partial = $this->tempDir . '/partials/_grid.scss';
        $entry = $this->tempDir . '/bundle.scss';
        file_put_contents($partial, ".grid{display:grid}\n");
        file_put_contents($entry, "// @import \"partials/grid\";\n.foo{color:red}\n");

        $before = $this->subject->hash($entry);
        file_put_contents($partial, ".grid{display:flex}\n");
        $after = $this->subject->hash($entry);

        self::assertSame($before, $after);
    }

    #[Test]
    public function hashStableWhenTreeUnchanged(): void
    {
        $partial = $this->tempDir . '/partials/_grid.scss';
        $entry = $this->tempDir . '/bundle.scss';
        file_put_contents($partial, ".grid{display:grid}\n");
        file_put_contents($entry, "@use \"partials/grid\";\n");

        self::assertSame($this->subject->hash($entry), $this->subject->hash($entry));
    }
}
