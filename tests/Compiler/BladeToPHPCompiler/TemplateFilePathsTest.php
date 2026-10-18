<?php

declare(strict_types=1);

namespace Bladestan\Tests\Compiler\BladeToPHPCompiler;

use Bladestan\Compiler\BladeToPHPCompiler;
use Bladestan\ValueObject\PhpFileContentsWithLineMap;
use PHPStan\Testing\PHPStanTestCase;

/**
 * The compiler reports every template it reads, so the rule can make the calling file depend on them.
 */
final class TemplateFilePathsTest extends PHPStanTestCase
{
    private BladeToPHPCompiler $bladeToPHPCompiler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bladeToPHPCompiler = self::getContainer()->getByType(BladeToPHPCompiler::class);
    }

    public function testTemplateWithoutIncludesReadsOnlyItself(): void
    {
        $compiled = $this->compile('empty');

        $this->assertSame([$this->view('empty')], $compiled->templateFilePaths);
        $this->assertFalse($compiled->hasMissingViews);
    }

    public function testNestedIncludesAreRead(): void
    {
        $compiled = $this->compile('file_with_recursive_include');

        $this->assertSame([
            $this->view('file_with_recursive_include'),
            $this->view('file_with_include'),
            $this->view('included_view'),
        ], $compiled->templateFilePaths);
        $this->assertFalse($compiled->hasMissingViews);
    }

    public function testAnonymousComponentIsRead(): void
    {
        $compiled = $this->compile('anonymous-component');

        $this->assertContains($this->view('components/component'), $compiled->templateFilePaths);
    }

    public function testMissingIncludeIsReported(): void
    {
        $compiled = $this->compile('missing-include');

        $this->assertSame([$this->view('missing-include')], $compiled->templateFilePaths);
        $this->assertTrue($compiled->hasMissingViews);
    }

    public function testMissingComponentIsReported(): void
    {
        $this->assertTrue($this->compile('missing-component')->hasMissingViews);
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../../config/extension.neon'];
    }

    private function compile(string $viewName): PhpFileContentsWithLineMap
    {
        $templateFilePath = $this->view($viewName);

        return $this->bladeToPHPCompiler->compileContent(
            $templateFilePath,
            $viewName,
            (string) file_get_contents($templateFilePath),
            []
        );
    }

    private function view(string $name): string
    {
        return (string) realpath(__DIR__ . '/../../skeleton/resources/views/' . $name . '.blade.php');
    }
}
