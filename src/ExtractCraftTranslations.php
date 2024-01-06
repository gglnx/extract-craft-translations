<?php

/**
 * @author Dennis Morhardt <info@dennismorhardt.de>
 * @copyright 2024 Dennis Morhardt
 */

namespace gglnx\ExtractCraftTranslations;

use Gettext\Translation;
use Peast\Peast;
use Peast\Syntax\Node\CallExpression;
use Peast\Syntax\Node\Identifier as NodeIdentifier;
use Peast\Syntax\Node\MemberExpression;
use Peast\Syntax\Node\Node as PeastNode;
use Peast\Syntax\Node\StringLiteral;
use Peast\Traverser;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;

/**
 * Extracts translations from Craft
 *
 * @package gglnx\ExtractCraftTranslations
 */
class ExtractCraftTranslations
{
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
    public function __construct(
        private string $defaultCategory = 'site',
        private ?string $baseReferencePath = null,
    ) {
    }

    /**
     * Parse translations from a file
     *
     * @param string $file Path to file
     * @param string $category Message category
     * @throws Exception if file don't exists or is a folder
     * @return SortableTranslations All found translations
     */
    public function extractFromFile(string $file, ?string $category = null): SortableTranslations
    {
        // Check if file exists or is a folder
        if (!file_exists($file) || is_dir($file)) {
            throw new Exception(sprintf('%s doesn\'t exists it or is a folder.', $file));
        }

        // Get file extensions
        $fileExtension = pathinfo($file, PATHINFO_EXTENSION);

        // Get contents
        $contents = file_get_contents($file) ?:
            throw new Exception(sprintf('%s couldn\'t be opended.', $file));

        // Extract
        if (in_array($fileExtension, $this->extractors['php'])) {
            return $this->extractFromPhp($contents, $file, $category);
        } elseif (in_array($fileExtension, $this->extractors['js'])) {
            return $this->extractFromJs($contents, $file, $category);
        } elseif (in_array($fileExtension, $this->extractors['twig'])) {
            return $this->extractFromTwig($contents, $file, $category);
        } else {
            // No matching extractor found
            throw new Exception(sprintf('No matching extractor for %s found.', $fileExtension));
        }
    }

    /**
     * Parse translations from a list of files
     *
     * @param array<string> $files Array of file paths
     * @param string $category Message category
     * @return SortableTranslations All translations
     */
    public function extractFromFiles(array $files, ?string $category = null): SortableTranslations
    {
        // Get all translations
        $translationsFromFiles = array_filter(array_map(function ($file) use ($category) {
            return $this->extractFromFile($file, $category);
        }, $files));

        // Flatten
        $translations = array_reduce($translationsFromFiles, function ($allTranslations, $translations) {
            return $allTranslations->mergeWith($translations);
        }, SortableTranslations::create($category));

        return $translations;
    }

    /**
     * Parse translations from a folder
     *
     * @param string $path Path to the folder to scan
     * @param string $category Message category
     * @return SortableTranslations All translations
     */
    public function extractFromFolder(string $path, ?string $category = null): SortableTranslations
    {
        // Get file extensions
        $fileExtensions = implode('|', array_reduce($this->extractors, function ($allExtensions, $extensions) {
            return array_merge($allExtensions, $extensions);
        }, []));

        $finder = new Finder();
        $finder
            ->in($path)
            ->ignoreUnreadableDirs()
            ->files()
            ->ignoreVCSIgnored(true)
            ->name('/^.+\.(' . $fileExtensions . ')$/i');

        // Get all files
        $files = [];
        foreach ($finder as $file) {
            $files[] = $file->getRealPath();
        }

        return $this->extractFromFiles($files, $category);
    }

    /**
     * Extracts translations from a PHP file
     *
     * @param string $contents File contents
     * @param string $file File path
     * @param string $category Message category
     * @return SortableTranslations All translations
     */
    private function extractFromPhp(string $contents, string $file, ?string $category = null): SortableTranslations
    {
        // Get matches per extension
        $translations = SortableTranslations::create($category);

        // Get parser and node finder
        $nodeFinder = new NodeFinder();
        $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
        $ast = $parser->parse($contents) ?? [];

        // Find all translation function calls
        /** @var StaticCall[] $translateFunctionCalls */
        $translateFunctionCalls = $nodeFinder->find($ast, function (Node $node) {
            if ($node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
                return
                    ($node->class->toString() === 'Craft' && (
                        $node->name->toString() === 't' || $node->name->toString() === 'translate')
                    ) ||
                    ($node->class->toString() === 'Translation' && $node->name->toString() === 'prep');
            }

            return false;
        });

        // Convert to translations
        foreach ($translateFunctionCalls as $translateFunctionCall) {
            // Get category
            $messageCategory = $this->defaultCategory;
            if (
                $translateFunctionCall->args[0] instanceof Arg &&
                $translateFunctionCall->args[0]->value instanceof String_
            ) {
                $messageCategory = (string) $translateFunctionCall->args[0]->value->value;
            }

            // Skip if message is not a string
            $messageArg = $translateFunctionCall->args[1];
            if (!($messageArg instanceof Arg) || !($messageArg->value instanceof String_)) {
                continue;
            }

            // Skip if category doesn't matches
            if ($category && $category !== $messageCategory) {
                continue;
            }

            // Get message and line number
            $message = (string) $messageArg->value->value;
            $lineNumber = $translateFunctionCall->class->getStartLine();

            // Find or create translation
            $translation = $translations->find(null, $message) ?? Translation::create(null, $message);

            // Add reference
            if ($this->baseReferencePath) {
                $file = Path::makeRelative($file, $this->baseReferencePath);
            }

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
     * @param string $contents File contents
     * @param string $file File path
     * @param string $category Message category
     * @return SortableTranslations All translations
     */
    private function extractFromJs(string $contents, string $file, ?string $category = null): SortableTranslations
    {
        // Get matches per extension
        $translations = SortableTranslations::create($category);

        // Parse ast
        $ast = Peast::latest($contents)->parse();

        $traverser = new Traverser();
        $traverser->addFunction(function (PeastNode $node) use ($translations, $category, $file) {
            if (!($node instanceof CallExpression)) {
                return;
            }

            $callee = $node->getCallee();

            if (!($callee instanceof MemberExpression)) {
                return;
            }

            $object = $callee->getObject();
            $property = $callee->getProperty();

            if (
                $object instanceof NodeIdentifier &&
                $object->getName() === 'Craft' &&
                $property instanceof NodeIdentifier &&
                $property->getName() === 't'
            ) {
                [$messageCategoryArg, $messageArg] = $node->getArguments();
                $messageCategory = $this->defaultCategory;

                if ($messageCategoryArg instanceof StringLiteral) {
                    $messageCategory = $messageCategoryArg->getValue();
                }

                // Skip if category doesn't matches
                if ($category && $category !== $messageCategory) {
                    return;
                }

                // Skip if message is not a string
                if (!($messageArg instanceof StringLiteral)) {
                    return;
                }

                $message = (string) $messageArg->getValue();
                $lineNumber = $callee->getLocation()->getStart()->getLine();

                // Find or create translation
                $translation = $translations->find(null, $message) ?? Translation::create(null, $message);

                // Add reference
                if ($this->baseReferencePath) {
                    $file = Path::makeRelative($file, $this->baseReferencePath);
                }

                $translation->getReferences()->add($file, $lineNumber);

                // Add to translations
                $translations->add($translation);
            }
        });

        $traverser->traverse($ast);

        // Return translations
        return $translations;
    }

    /**
     * Extracts translations from a Twig file
     *
     * @param string $contents File contents
     * @param string $file File path
     * @param string $category Message category
     * @return SortableTranslations All translations
     */
    private function extractFromTwig(string $contents, string $file, ?string $category = null): SortableTranslations
    {
        // Get matches per extension
        $translations = SortableTranslations::create($category);

        // Matches all |t and |translate filters and Craft.t() calls
        $regexes = [
            // phpcs:ignore Generic.Files.LineLength.TooLong
            '/((?<![\\\])[\'"])(?<message>(?:.(?!(?<![\\\])\1))*.?)\1\s*\|\s*(?:t|translate)(?:\s*\(\s*((?<![\\\])[\'"])(?<category>(?:.(?!(?<![\\\])\3))*.?)\3)?/',
            // phpcs:ignore Generic.Files.LineLength.TooLong
            '/Craft\.t\(\s*((?<![\\\])[\'"])(?<category>(?:.(?!(?<![\\\])\1))*.?)[\'"]\s*,\s*((?<![\\\])[\'"])(?<message>(?:.(?!(?<![\\\])\3))*.?)[\'"]/',
        ];

        $matches = array_reduce($regexes, function (array $matches, string $regex) use ($contents) {
            preg_match_all(
                $regex,
                $contents,
                $newMatches,
                PREG_OFFSET_CAPTURE | PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
            );

            return array_merge($matches, $newMatches);
        }, []);

        // Match translation functions
        foreach ($matches as $match) {
            // Get message, category and line number
            $messageCategory = $match[4][0] ?? $this->defaultCategory;
            $message = stripcslashes((string) $match[2][0]);
            $position = $match[2][1];
            $lineNumber = $this->getLineNumber($contents, $position);

            // Skip if category doesn't matches
            if ($category && $category !== $messageCategory) {
                continue;
            }

            // Find or create translation
            $translation = $translations->find(null, $message) ?? Translation::create(null, $message);

            // Add reference
            if ($this->baseReferencePath) {
                $file = Path::makeRelative($file, $this->baseReferencePath);
            }

            $translation->getReferences()->add($file, $lineNumber);

            // Add to translations
            $translations->add($translation);
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
