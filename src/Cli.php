<?php

/**
 * @author Dennis Morhardt <info@dennismorhardt.de>
 * @copyright 2020 Dennis Morhardt
 */

namespace gglnx\ExtractCraftTranslations;

use Gettext\Generator\GeneratorInterface;
use Gettext\Generator\MoGenerator;
use Gettext\Generator\PoGenerator;
use Gettext\Loader\LoaderInterface;
use Gettext\Loader\MoLoader;
use Gettext\Loader\PoLoader;
use gglnx\ExtractCraftTranslations\Generator\PhpArrayGenerator;
use gglnx\ExtractCraftTranslations\Loader\PhpArrayLoader;
use InvalidArgumentException;

/**
 * CLI application
 *
 * @package gglnx\ExtractCraftTranslations
 */
class Cli
{
    public function extract($folderToScan, $outputFile = 'translations.pot', $category = null): bool
    {
        // Extract all translations
        $extractCraftTranslations = new ExtractCraftTranslations();
        $translations = $extractCraftTranslations->extractFromFolder($folderToScan, $category);

        // Set domain
        if ($category) {
            $translations->setDomain($category);
        }

        // Get generator
        $generator = $this->getGenerator($outputFile);

        // Generate output
        return $generator->generateFile($translations, $outputFile);
    }

    public function convert(string $inputFile, string $outputFile): bool
    {
        // Get loader and generator
        $loader = $this->getLoader($inputFile);
        $generator = $this->getGenerator($outputFile);

        // Load input file
        $translations = $loader->loadFile($inputFile);

        // Generate output file
        return $generator->generateFile($translations, $outputFile);
    }

    /**
     * Gets the translation loader by file extension.
     *
     * @param string $filename Filename of the input file.
     * @throws InvalidArgumentException If file extensions is unknown.
     * @return LoaderInterface
     */
    private function getLoader(string $filename): LoaderInterface
    {
        // Get loader
        $type = pathinfo($filename, PATHINFO_EXTENSION);
        switch ($type) {
            case 'po':
            case 'pot':
                return new PoLoader();
            case 'mo':
                return new MoLoader();
            case 'php':
                return new PhpArrayLoader();
        }

        // Nothing found.
        throw new InvalidArgumentException(sprintf('%s is a unknown input type', $type));
    }

    /**
     * Gets the translation generator by file extension.
     *
     * @param string $filename Filename of the input file.
     * @throws InvalidArgumentException If file extensions is unknown.
     * @return LoaderInterface
     */
    private function getGenerator(string $filename): GeneratorInterface
    {
        // Get generator
        $type = pathinfo($filename, PATHINFO_EXTENSION);
        switch ($type) {
            case 'po':
            case 'pot':
                return new PoGenerator();
            case 'mo':
                return new MoGenerator();
            case 'php':
                return new PhpArrayGenerator();
        }

        // Nothing found.
        throw new InvalidArgumentException(sprintf('%s is a unknown output type', $type));
    }
}
