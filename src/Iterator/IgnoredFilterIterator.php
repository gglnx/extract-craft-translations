<?php

/**
 * @author Dennis Morhardt <info@dennismorhardt.de>
 * @copyright 2024 Dennis Morhardt
 */

namespace gglnx\ExtractCraftTranslations\Iterator;

use FilterIterator;
use Iterator;
use SplFileInfo;
use Symfony\Component\Finder\Gitignore;

/**
 * @extends FilterIterator<string, SplFileInfo, Iterator<string, SplFileInfo>>
 */
final class IgnoredFilterIterator extends FilterIterator
{
    private string $baseDir;

    /**
     * @var array<string, array{0: string, 1: string}|null>
     */
    private array $ignoreFilesCache = [];

    /**
     * @var array<string, bool>
     */
    private array $ignoredPathsCache = [];

    /**
     * @param Iterator<string, SplFileInfo> $iterator
     */
    public function __construct(Iterator $iterator, string $baseDir)
    {
        $this->baseDir = $this->normalizePath($baseDir);

        parent::__construct($iterator);
    }

    public function accept(): bool
    {
        $file = $this->current();

        $fileRealPath = $this->normalizePath($file->getRealPath());

        return !$this->isIgnored($fileRealPath);
    }

    private function isIgnored(string $fileRealPath): bool
    {
        if (is_dir($fileRealPath) && !str_ends_with($fileRealPath, '/')) {
            $fileRealPath .= '/';
        }

        if (isset($this->ignoredPathsCache[$fileRealPath])) {
            return $this->ignoredPathsCache[$fileRealPath];
        }

        $ignored = false;

        foreach ($this->parentDirectoriesDownwards($fileRealPath) as $parentDirectory) {
            if ($this->isIgnored($parentDirectory)) {
                break;
            }

            $fileRelativePath = substr($fileRealPath, \strlen($parentDirectory) + 1);

            if (null === $regexps = $this->readIgnoreFile("{$parentDirectory}/.translateignore")) {
                continue;
            }

            [$exclusionRegex, $inclusionRegex] = $regexps;

            if (preg_match($exclusionRegex, $fileRelativePath)) {
                $ignored = true;

                continue;
            }

            if (preg_match($inclusionRegex, $fileRelativePath)) {
                $ignored = false;
            }
        }

        return $this->ignoredPathsCache[$fileRealPath] = $ignored;
    }

    /**
     * @return list<string>
     */
    private function parentDirectoriesUpwards(string $from): array
    {
        $parentDirectories = [];

        $parentDirectory = $from;

        while (true) {
            $newParentDirectory = \dirname($parentDirectory);

            if ($newParentDirectory === $parentDirectory) {
                break;
            }

            $parentDirectories[] = $parentDirectory = $newParentDirectory;
        }

        return $parentDirectories;
    }

    /**
     * @return string[]
     */
    private function parentDirectoriesUpTo(string $from, string $upTo): array
    {
        return array_filter(
            $this->parentDirectoriesUpwards($from),
            static fn (string $directory): bool => str_starts_with($directory, $upTo),
        );
    }

    /**
     * @return string[]
     */
    private function parentDirectoriesDownwards(string $fileRealPath): array
    {
        return array_reverse(
            $this->parentDirectoriesUpTo($fileRealPath, $this->baseDir),
        );
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function readIgnoreFile(string $path): ?array
    {
        if (array_key_exists($path, $this->ignoreFilesCache)) {
            return $this->ignoreFilesCache[$path];
        }

        if (!file_exists($path)) {
            return $this->ignoreFilesCache[$path] = null;
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(
                "The \"ignoreIgnored\" option cannot be used by the Finder as the \"{$path}\" file is not readable.",
            );
        }

        $ignoreFileContent = file_get_contents($path);

        if ($ignoreFileContent === false) {
            return $this->ignoreFilesCache[$path] = null;
        }

        return $this->ignoreFilesCache[$path] = [
            Gitignore::toRegex($ignoreFileContent),
            Gitignore::toRegexMatchingNegatedPatterns($ignoreFileContent),
        ];
    }

    private function normalizePath(string $path): string
    {
        if ('\\' === \DIRECTORY_SEPARATOR) {
            return str_replace('\\', '/', $path);
        }

        return $path;
    }
}
