<?php

/**
 * @author Dennis Morhardt <info@dennismorhardt.de>
 * @copyright 2024 Dennis Morhardt
 */

namespace gglnx\ExtractCraftTranslations;

use Gettext\Generator\GeneratorInterface;
use Gettext\Generator\MoGenerator;
use Gettext\Generator\PoGenerator;
use Gettext\Loader\LoaderInterface;
use Gettext\Loader\MoLoader;
use Gettext\Loader\PoLoader;
use Gettext\Merge;
use gglnx\ExtractCraftTranslations\Generator\CsvGenerator;
use gglnx\ExtractCraftTranslations\Generator\PhpArrayGenerator;
use gglnx\ExtractCraftTranslations\Loader\PhpArrayLoader;
use InvalidArgumentException;
use Symfony\Component\Finder\Finder;

/**
 * CLI application
 *
 * @package gglnx\ExtractCraftTranslations
 */
class Cli
{
    /**
     * Extracts translations
     *
     * @param string $source
     * @param string $outputFile
     * @param string|null $category
     * @return bool
     * @throws InvalidArgumentException
     */
    public function extract(
        string $source,
        string $outputFile = 'translations.pot',
        ?string $category = null,
    ): bool {
        // Extract all translations
        $baseReferencePath = is_dir($source) ? $source : dirname($source);
        $extractCraftTranslations = new ExtractCraftTranslations(
            baseReferencePath: $baseReferencePath,
            projectConfigPath: $this->getProjectConfigPath($baseReferencePath),
        );

        if (is_dir($source)) {
            $translations = $extractCraftTranslations->extractFromFolder($source, $category);
        } elseif (is_file($source)) {
            $translations = $extractCraftTranslations->extractFromFile($source, $category);
        } else {
            throw new Exception();
        }

        // Set domain
        if ($category) {
            $translations->setDomain($category);
        }

        // Get generator
        $format = $this->getFormatFromFilename($outputFile);
        $generator = $this->getGenerator($format);

        // Sort translations
        $translations->sort();

        // Generate output
        return $generator->generateFile($translations, $outputFile);
    }

    /**
     * Updates an existing translation
     *
     * @param string $inputOutputFile
     * @param string $source
     * @param string|null $category
     * @return bool
     * @throws InvalidArgumentException
     */
    public function update(
        string $inputOutputFile,
        string $source,
        ?string $category = null,
    ): bool {
        // Get loader and generator
        $inputFormat = $this->getFormatFromFilename($inputOutputFile);
        $loader = $this->getLoader($inputFormat);

        // Load input file
        $sourceTranslations = $loader->loadFile($inputOutputFile);

        // Extract all translations
        $baseReferencePath = is_dir($source) ? $source : dirname($source);
        $extractCraftTranslations = new ExtractCraftTranslations(
            baseReferencePath: $baseReferencePath,
            projectConfigPath: $this->getProjectConfigPath($baseReferencePath),
        );

        if (is_dir($source)) {
            $translations = $extractCraftTranslations->extractFromFolder($source, $category, [$inputOutputFile]);
        } elseif (is_file($source)) {
            $translations = $extractCraftTranslations->extractFromFile($source, $category);
        } else {
            throw new Exception();
        }

        // Set domain
        if ($category) {
            $translations->setDomain($category);
        }

        // Get generator
        $format = $this->getFormatFromFilename($inputOutputFile);
        $generator = $this->getGenerator($format);

        // Get merged translations
        $translations = $translations->mergeWith(
            $sourceTranslations,
            Merge::TRANSLATIONS_OURS | Merge::TRANSLATIONS_OVERRIDE,
        );

        // Sort translations
        $translations->sort();

        // Generate output
        return $generator->generateFile($translations, $inputOutputFile);
    }

    /**
     * Converts between two formats
     *
     * @param string $inputFile
     * @param string $outputFile
     * @return bool
     * @throws InvalidArgumentException
     */
    public function convert(string $inputFile, string $outputFile): bool
    {
        // Get loader and generator
        $inputFormat = $this->getFormatFromFilename($inputFile);
        $loader = $this->getLoader($inputFormat);

        // Load input file
        $translations = $loader->loadFile($inputFile);

        // Generate output file
        $format = $this->getFormatFromFilename($outputFile);
        $generator = $this->getGenerator($format);

        return $generator->generateFile($translations, $outputFile);
    }

    /**
     * Merges translation files
     *
     * @param array<string> $inputFiles
     * @param string|null $outputFile
     * @param string $format
     * @param string|null $category
     * @return bool
     * @throws InvalidArgumentException
     */
    public function merge(
        array $inputFiles,
        ?string $outputFile = null,
        string $format = 'po',
        ?string $category = null,
    ): bool {
        // Get merged translations
        $translations = array_reduce(
            $inputFiles,
            function (SortableTranslations $translations, string $inputFile) {
                if (!is_file($inputFile)) {
                    return $translations;
                }

                $inputFormat = $this->getFormatFromFilename($inputFile);
                $loader = $this->getLoader($inputFormat);

                return $translations->mergeWith($loader->loadFile($inputFile));
            },
            SortableTranslations::create($category),
        );

        // Get generator
        $outputFormat = $outputFile ? $this->getFormatFromFilename($outputFile, $format) : $format;
        $generator = $this->getGenerator($outputFormat);

        // Sort translations
        $translations->sort();

        // Save to file
        if ($outputFile) {
            return $generator->generateFile($translations, $outputFile);
        }

        // Return string
        $output = fopen('php://output', 'w') ?: throw new Exception();
        fputs($output, $generator->generateString($translations) . PHP_EOL);
        fclose($output);

        return true;
    }

    /**
     * Returns format for an file extension
     *
     * @param string $filename
     * @param string $fallback
     * @return string
     */
    private function getFormatFromFilename(string $filename, string $fallback = 'po'): string
    {
        $format = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        switch ($format) {
            case 'pot':
                return 'po';
            case 'po':
            case 'mo':
            case 'php':
            case 'csv':
                return $format;
        }

        return $fallback;
    }

    /**
     * Gets the translation loader by file extension.
     *
     * @param string $format Loader type.
     * @throws InvalidArgumentException If file extensions is unknown.
     * @return LoaderInterface
     */
    private function getLoader(string $format): LoaderInterface
    {
        // Get loader
        switch ($format) {
            case 'po':
            case 'pot':
                return new PoLoader();
            case 'mo':
                return new MoLoader();
            case 'php':
                return new PhpArrayLoader();
        }

        // Nothing found.
        throw new InvalidArgumentException(sprintf('%s is an invalid format', $format));
    }

    /**
     * Gets the translation generator by file extension.
     *
     * @param string $format Loader type.
     * @throws InvalidArgumentException If file extensions is unknown.
     * @return GeneratorInterface
     */
    private function getGenerator(string $format): GeneratorInterface
    {
        // Get generator
        switch ($format) {
            case 'po':
            case 'pot':
                return new PoGenerator();
            case 'mo':
                return new MoGenerator();
            case 'php':
                return new PhpArrayGenerator();
            case 'csv':
                return new CsvGenerator();
        }

        // Nothing found.
        throw new InvalidArgumentException(sprintf('%s is an invalid format', $format));
    }

    private function getProjectConfigPath(string $path): ?string
    {
        $finder = (new Finder())->files()->in($path)->name('project.yaml')->path('project');

        foreach ($finder as $file) {
            return $file->getPath();
        }

        return null;
    }
}
