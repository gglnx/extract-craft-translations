<?php

/**
 * @author Dennis Morhardt <info@dennismorhardt.de>
 * @copyright 2020 Dennis Morhardt
 */

namespace gglnx\ExtractCraftTranslations;

use Gettext\Translation;
use Gettext\Translations;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;

/**
 * Extracts translations from Craft
 *
 * @package gglnx\ExtractCraftTranslations
 */
class ExtractCraftTranslations
{
    /**
     * @var string
     */
    private $defaultCategory;

    /**
     * @var string[][]
     */
    private $extractors = [
        'twig' => ['twig', 'html'],
        'js' => ['js', 'jsx'],
        'php' => ['php'],
    ];

    /**
     * Inits the extractor
     *
     * @param string $defaultCategory Default translation category if missing
     */
    public function __construct(string $defaultCategory = 'site')
    {
        $this->defaultCategory = $defaultCategory;
    }

    /**
     * Parse translations from a file
     *
     * @param string $file Path to file
     * @param string $category Message category
     * @throws Exception if file don't exists or is a folder
     * @return Translations All found translations
     */
    public function extractFromFile(string $file, ?string $category = null): Translations
    {
        // Check if file exists or is a folder
        if (!file_exists($file) || is_dir($file)) {
            throw new Exception(sprintf('%s doesn\'t exists it or is a folder.', $file));
        }

        // Get file extensions
        $fileExtension = pathinfo($file, PATHINFO_EXTENSION);

        // Get contents
        $contents = file_get_contents($file);

        // Extract
        if (in_array($fileExtension, $this->extractors['php'])) {
            return $this->extractFromPhp($contents, $file, $category);
        } elseif (in_array($fileExtension, $this->extractors['js'])) {
            return $this->extractFromJs($contents, $file, $category);
        } elseif (in_array($fileExtension, $this->extractors['twig'])) {
            return $this->extractFromTwig($contents, $file, $category);
        } else {
            // No matching extractor found
            throw new Exception(sprintf('No matching extractor for found.', $fileExtension));
        }
    }

    /**
     * Parse translations from a list of files
     *
     * @param array $files Array of file paths
     * @param string $category Message category
     * @return Translations All translations
     */
    public function extractFromFiles(array $files, ?string $category = null): Translations
    {
        // Get all translations
        $translations = array_filter(array_map(function ($file) use ($category) {
            return $this->extractFromFile($file, $category);
        }, $files));

        // Flatten
        $translations = array_reduce($translations, function ($allTranslations, $translations) {
            return $allTranslations->mergeWith($translations);
        }, Translations::create($category));

        return $translations;
    }

    /**
     * Parse translations from a folder
     *
     * @param string $path Path to the folder to scan
     * @param string $category Message category
     * @return Translations All translations
     */
    public function extractFromFolder(string $path, ?string $category = null): Translations
    {
        // Get file extensions
        $fileExtensions = implode('|', array_reduce($this->extractors, function ($allExtensions, $extensions) {
            return array_merge($allExtensions, $extensions);
        }, []));

        // Get all files
        $directory = new RecursiveDirectoryIterator($path);
        $iterator = new RecursiveIteratorIterator($directory);
        $files = array_keys(iterator_to_array(new RegexIterator(
            $iterator,
            '/^.+\.(' . $fileExtensions . ')$/i',
            RecursiveRegexIterator::GET_MATCH
        )));

        return $this->extractFromFiles($files, $category);
    }

    /**
     * Extracts translations from a PHP file
     *
     * @param string $content File contents
     * @param string $file File path
     * @param string $category Message category
     * @return Translations All translations
     */
    private function extractFromPhp(string $contents, string $file, ?string $category = null): Translations
    {
        // Get matches per extension
        $translations = Translations::create($category);

        // Get parser and node finder
        $nodeFinder = new NodeFinder();
        $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
        $ast = $parser->parse($contents);

        // Find all translation function calls
        /** @var StaticCall[] $translateFunctionCalls */
        $translateFunctionCalls = $nodeFinder->find($ast, function (Node $node) use ($file) {
            return $node instanceof StaticCall
                && $node->class instanceof Name
                && $node->class->toString() === 'Craft'
                && ($node->name->toString() === 't' || $node->name->toString() === 'translate');
        });

        // Convert to translations
        foreach ($translateFunctionCalls as $translateFunctionCall) {
            // Get category
            $messageCategory = $this->defaultCategory;
            if ($translateFunctionCall->args[0]->value instanceof String_) {
                $messageCategory = (string) $translateFunctionCall->args[0]->value->value;
            }

            // Skip if message is not a string
            if (!($translateFunctionCall->args[1]->value instanceof String_)) {
                continue;
            }

            // Skip if category doesn't matches
            if ($category && $category !== $messageCategory) {
                continue;
            }

            // Get message and line number
            $message = (string) $translateFunctionCall->args[1]->value->value;
            $lineNumber = $translateFunctionCall->class->getStartLine();

            // Find or create translation
            $translation = $translations->find(null, $message) ?? Translation::create(null, $message);

            // Add reference
            $translation->getReferences()->add($file, $lineNumber);

            // Add to translations
            $translations->add($translation);
        }

        // Return translations
        return $translations;
    }

    /**
     * Extracts translations from a JavaScript file
     *
     * @param string $content File contents
     * @param string $file File path
     * @param string $category Message category
     * @return Translations All translations
     */
    private function extractFromJs(string $contents, string $file, ?string $category = null): Translations
    {
        // Get matches per extension
        $translations = Translations::create($category);

        // Return translations
        return $translations;
    }

    /**
     * Extracts translations from a Twig file
     *
     * @param string $content File contents
     * @param string $file File path
     * @param string $category Message category
     * @return Translations All translations
     */
    private function extractFromTwig(string $contents, string $file, ?string $category = null): Translations
    {
        // Get matches per extension
        $translations = Translations::create($category);

        // Regex and flags
        $regex = '/((?<![\\\])[\'"])((?:.(?!(?<![\\\])\1))*.?)\1\s*\|\s*(?:t|translate)' .
            '(?:\s*\(\s*((?<![\\\])[\'"])((?:.(?!(?<![\\\])\3))*.?)\3)?/';
        $flags = PREG_OFFSET_CAPTURE | PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL;

        // Match translation functions
        if (preg_match_all($regex, $contents, $matches, $flags)) {
            foreach ($matches as $match) {
                // Get message, category and line number
                $messageCategory = $match[4][0] ?? $this->defaultCategory;
                $message = stripslashes($match[2][0]);
                $position = $match[2][1];
                $lineNumber = $this->getLineNumber($contents, $position);

                // Skip if category doesn't matches
                if ($category && $category !== $messageCategory) {
                    continue;
                }

                // Find or create translation
                $translation = $translations->find(null, $message) ?? Translation::create(null, $message);

                // Add reference
                $translation->getReferences()->add($file, $lineNumber);

                // Add to translations
                $translations->add($translation);
            }
        }

        // Return translations
        return $translations;
    }

    /**
     * Get line number from a string and offset position
     *
     * @param string $contents Source string
     * @param int $position Position in string
     * @return int Line number
     */
    private function getLineNumber(string $contents, int $position): int
    {
        return substr_count(mb_substr($contents, 0, $position), PHP_EOL) + 1;
    }
}
