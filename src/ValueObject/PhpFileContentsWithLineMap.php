<?php

declare(strict_types=1);

namespace Bladestan\ValueObject;

/**
 * @see \Bladestan\TemplateCompiler\ValueObject\PhpFileContentsWithLineMap
 */
final class PhpFileContentsWithLineMap
{
    /**
     * @param array<int, array<string, int>> $phpToTemplateLines
     */
    public function __construct(
        public readonly string $phpFileContents,
        public readonly array $phpToTemplateLines,
        /**
         * @var list<array<int, string>>
         */
        public array $errors,
        /**
         * Every template read to compile this one: itself, its includes and anonymous components.
         *
         * @var list<string>
         */
        public readonly array $templateFilePaths = [],
        /**
         * A view it names was not found; creating it changes the result.
         */
        public readonly bool $hasMissingViews = false,
    ) {
    }
}
