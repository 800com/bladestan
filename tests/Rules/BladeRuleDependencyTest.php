<?php

declare(strict_types=1);

namespace Bladestan\Tests\Rules;

use Bladestan\Rules\BladeRule;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
use PHPStan\Analyser\Analyser;
use PHPStan\Analyser\FileAnalyser;
use PHPStan\Analyser\IgnoreErrorExtension;
use PHPStan\Analyser\LocalIgnoresProcessor;
use PHPStan\Analyser\ResultCache\DirectoryResultCacheValueExtension;
use PHPStan\Analyser\ResultCache\FileResultCacheValueExtension;
use PHPStan\Analyser\RuleErrorTransformer;
use PHPStan\Analyser\ValueDependencyCollector;
use PHPStan\Collectors\Registry as CollectorRegistry;
use PHPStan\Dependency\DependencyResolver;
use PHPStan\Rules\DirectRegistry;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * The rule makes the calling file depend on the templates it renders, so the result cache
 * re-analyses it when only a template changes. Which templates are read is covered by
 * TemplateFilePathsTest; this checks they reach the result cache.
 *
 * RuleTestCase does not expose the tracked dependencies, so the analyser is assembled here from
 * PHPStan internals; phpstan.neon ignores phpstanApi for this file.
 *
 * @extends RuleTestCase<Rule>
 */
final class BladeRuleDependencyTest extends RuleTestCase
{
    public function testRenderedTemplatesAreDependencies(): void
    {
        $tracked = $this->trackedValues(
            __DIR__ . '/Fixture/file-with-recursive-include.php',
            FileResultCacheValueExtension::class
        );

        $this->assertContains($this->view('file_with_recursive_include'), $tracked);
        $this->assertContains($this->view('file_with_include'), $tracked);
        $this->assertContains($this->view('included_view'), $tracked);
    }

    public function testMissingTemplateDependsOnTheViewDirectories(): void
    {
        $tracked = $this->trackedValues(
            __DIR__ . '/Fixture/missing-template.php',
            DirectoryResultCacheValueExtension::class
        );

        foreach ($this->viewPaths() as $viewPath) {
            $this->assertContains(DirectoryResultCacheValueExtension::createKey($viewPath, '*.php'), $tracked);
        }
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/config/configured_extension.neon'];
    }

    /**
     * @return BladeRule
     */
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(BladeRule::class);
    }

    /**
     * @return list<string>
     */
    private function trackedValues(string $file, string $extensionClass): array
    {
        $container = self::getContainer();
        $fileAnalyser = new FileAnalyser(
            self::createScopeFactory($this->createReflectionProvider(), $this->getTypeSpecifier()),
            $this->createNodeScopeResolver(),
            $this->getParser(),
            $container->getExtensionsCollection(IgnoreErrorExtension::class),
            $container->getByType(RuleErrorTransformer::class),
            new LocalIgnoresProcessor(),
            false,
            $container->getByType(ValueDependencyCollector::class),
            $container->getByType(DependencyResolver::class),
        );
        $analyser = new Analyser(
            $fileAnalyser,
            new DirectRegistry([$this->getRule()]),
            new CollectorRegistry([]),
            $this->createNodeScopeResolver(),
            50
        );

        $result = $analyser->analyse([$this->getFileHelper()->normalizePath($file)], null, null, true);

        $tracked = [];
        foreach ($result->getValueDependencies()['values'] ?? [] as [$valueExtension, $key]) {
            if ($valueExtension === $extensionClass) {
                $tracked[] = $key;
            }
        }

        return $tracked;
    }

    private function view(string $name): string
    {
        return $this->getFileHelper()->normalizePath(
            __DIR__ . '/../skeleton/resources/views/' . $name . '.blade.php'
        );
    }

    /**
     * @return list<string>
     */
    private function viewPaths(): array
    {
        $finder = resolve(ViewFactory::class)->getFinder();
        assert($finder instanceof FileViewFinder);

        return array_values(array_map(
            fn (string $path): string => rtrim($this->getFileHelper()->normalizePath($path), '/\\'),
            $finder->getPaths()
        ));
    }
}
