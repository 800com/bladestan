<?php

declare(strict_types=1);

namespace Bladestan;

use Bladestan\Compiler\BladeToPHPCompiler;
use Bladestan\ErrorReporting\Blade\TemplateErrorsFactory;
use Bladestan\NodeAnalyzer\TemplateFilePathResolver;
use Bladestan\TemplateCompiler\ErrorFilter;
use Bladestan\TemplateCompiler\PHPStan\FileAnalyserProvider;
use Bladestan\TemplateCompiler\ValueObject\RenderTemplateWithParameters;
use Bladestan\ValueObject\CompiledTemplate;
use InvalidArgumentException;
use PhpParser\Node\Expr\CallLike;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Registry;
use PHPStan\DependencyInjection\MissingServiceException;
use PHPStan\Rules\IdentifierRuleError;

final class ViewRuleHelper
{
    public function __construct(
        private readonly FileAnalyserProvider $fileAnalyserProvider,
        private readonly TemplateErrorsFactory $templateErrorsFactory,
        private readonly BladeToPHPCompiler $bladeToPhpCompiler,
        private readonly TemplateFilePathResolver $templateFilePathResolver,
        private readonly ErrorFilter $errorFilter,
    ) {
    }

    /**
     * The templates are read on their own, so the result cache only re-analyses the calling file
     * when they change if they are tracked as its dependencies.
     *
     * @param Scope&DependencyTracker $scope
     * @return list<IdentifierRuleError>
     *
     * @throws InvalidArgumentException
     * @throws MissingServiceException
     */
    public function processNode(
        CallLike $callLike,
        Scope $scope,
        RenderTemplateWithParameters $renderTemplateWithParameters
    ): array {
        try {
            $compiledTemplate = $this->compileToPhp(
                $renderTemplateWithParameters,
                $scope->getFile(),
                $callLike->getLine()
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->trackViewDirectories($scope);

            throw $invalidArgumentException;
        }

        if (! $compiledTemplate instanceof CompiledTemplate) {
            return [];
        }

        $phpFileContentsWithLineMap = $compiledTemplate->phpFileContentsWithLineMap;
        foreach ($phpFileContentsWithLineMap->templateFilePaths as $templateFilePath) {
            $scope->trackFileDependency($templateFilePath);
        }

        if ($phpFileContentsWithLineMap->hasMissingViews) {
            $this->trackViewDirectories($scope);
        }

        return $this->processTemplateFilePath($compiledTemplate);
    }

    /**
     * A missing view is fixed by creating it, which only a change in the view directories shows.
     *
     * @param Scope&DependencyTracker $scope
     */
    private function trackViewDirectories(Scope $scope): void
    {
        foreach ($this->templateFilePathResolver->resolveViewDirectories() as $viewDirectory) {
            $scope->trackDirectoryDependency($viewDirectory, '*.php');
        }
    }

    /**
     * @return list<IdentifierRuleError>
     *
     * @throws MissingServiceException
     */
    private function processTemplateFilePath(CompiledTemplate $compiledTemplate): array
    {
        $fileAnalyser = $this->fileAnalyserProvider->provide();
        $templateRulesRegistry = $this->fileAnalyserProvider->getRules();

        /** @phpstan-ignore phpstanApi.constructor */
        $collectorsRegistry = new Registry([]);

        /** @phpstan-ignore phpstanApi.method */
        $fileAnalyserResult = $fileAnalyser->analyseFile(
            $compiledTemplate->phpFilePath,
            [],
            $templateRulesRegistry,
            $collectorsRegistry,
            null
        );

        /** @phpstan-ignore phpstanApi.method */
        $ruleErrors = $fileAnalyserResult->getErrors();

        $usefulRuleErrors = $this->errorFilter->filterErrors($ruleErrors);

        return $this->templateErrorsFactory->createErrors(
            $usefulRuleErrors,
            $compiledTemplate->phpLine,
            $compiledTemplate->bladeFilePath,
            $compiledTemplate->phpFileContentsWithLineMap,
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    private function compileToPhp(
        RenderTemplateWithParameters $renderTemplateWithParameters,
        string $filePath,
        int $phpLine
    ): ?CompiledTemplate {
        $resolvedTemplateFilePath = $this->templateFilePathResolver->resolveExistingFilePath(
            $renderTemplateWithParameters->templateName
        );
        $fileContents = file_get_contents($resolvedTemplateFilePath);
        if ($fileContents === false) {
            return null;
        }

        $phpFileContentsWithLineMap = $this->bladeToPhpCompiler->compileContent(
            $resolvedTemplateFilePath,
            $renderTemplateWithParameters->templateName,
            $fileContents,
            $renderTemplateWithParameters->parametersArray
        );

        $phpFileContents = $phpFileContentsWithLineMap->phpFileContents;

        $tmpFilePath = sys_get_temp_dir() . '/' . md5($filePath) . '-blade-compiled.php';
        file_put_contents($tmpFilePath, $phpFileContents);

        return new CompiledTemplate($filePath, $tmpFilePath, $phpFileContentsWithLineMap, $phpLine);
    }
}
