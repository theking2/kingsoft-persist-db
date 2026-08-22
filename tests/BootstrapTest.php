<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Kingsoft\Persist\Db\Bootstrap;

class BootstrapTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/bootstrap_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/**/*.php') ?: []);
        @rmdir($this->tmpDir . '/Test/');
        @rmdir($this->tmpDir);
    }

    public function testConstructorNormalizesNamespace(): void
    {
        $bs = new Bootstrap('test/namespace', $this->tmpDir);
        $this->assertInstanceOf(Bootstrap::class, $bs);
    }

    /** Regression test for #123: nullable generated properties must have = null default */
    public function testNullablePropertyFormatIncludesNullDefault(): void
    {
        $output = sprintf("\tprotected ?%-10s\$%s = null;\n", 'string', 'uuid');
        $this->assertStringContainsString('= null', $output);
        $this->assertStringContainsString('?string', $output);
    }
}
