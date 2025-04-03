<?php

/**
 * @author Dennis Morhardt <info@dennismorhardt.de>
 * @copyright 2024 Dennis Morhardt
 */

namespace gglnx\ExtractCraftTranslations;

use Gettext\Translation;
use Gettext\Translations;
use gglnx\ExtractCraftTranslations\Iterator\IgnoredFilterIterator;
use Peast\Peast;
use Peast\Syntax\Node\CallExpression;
use Peast\Syntax\Node\Identifier as NodeIdentifier;
use Peast\Syntax\Node\MemberExpression;
use Peast\Syntax\Node\Node as PeastNode;
use Peast\Syntax\Node\StringLiteral;
use Peast\Traverser;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\VariadicPlaceholder;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Parser;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\Token;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Extracts translations from Craft
 *
 * @package gglnx\ExtractCraftTranslations
 */
class ExtractCraftTranslations
{
    public const UUID_PATTERN = '[A-Za-z0-9]{8}-[A-Za-z0-9]{4}-4[A-Za-z0-9]{3}-[89abAB][A-Za-z0-9]{3}-[A-Za-z0-9]{12}';

    /**
     * @var string[][]
     */
    private $extractors = [
        'twig' => ['twig', 'html'],
        'js' => ['js', 'jsx'],
        'php' => ['php'],
        'yaml' => ['yml', 'yaml'],
    ];

    private array $projectConfigSearchPatterns = [
        '/^elementSources.\S+?.\S+?.heading$/',
        '/fieldLayouts.\S+?.tabs.\S+?.elements.\S+?.label$/',
        '/fieldLayouts.\S+?.tabs.\S+?.elements.\S+?.instructions$/',
        '/fieldLayouts.\S+?.tabs.\S+?.elements.\S+?.warning$/',
        '/fieldLayouts.\S+?.tabs.\S+?.elements.\S+?.tip$/',
        '/fieldLayouts.\S+?.tabs.\S+?.name$/',
        '/^sections.\S+?.name$/',
        '/^entryTypes.\S+?.name$/',
        '/^fields.\S+?.instructions$/',
        '/^fields.\S+?.name$/',
        '/^fields.\S+?.settings.createButtonLabel$/',
        '/^fields.\S+?.settings.addRowLabel$/',
        '/^fields.\S+?.settings.selectionLabel$/',
        '/^fields.\S+?.settings.offLabel$/',
        '/^fields.\S+?.settings.onLabel$/',
        '/^fields.\S+?.settings.placeholder$/',
        '/^formie.stencils.\S+?.name$/',
    ];

    private array $projectConfigSearchPatternsAssoc = [
        '/^fields.\S+?.settings.columns.__assoc__.\S+?.1.__assoc__.\S+?.0$/' => ['heading'],
        '/^fields.\S+?.settings.entryTypes.\S+?.__assoc__.\S+?.0$/' => ['name'],
        '/^fields.\S+?.settings.linkTypes.(.+).__assoc__.\S+?.0?$/' => [
            'label', 'name', 'placeholder', 'instructions', 'warning', 'tip',
        ],
    ];

    private array $projectConfigSearchPatternsTwig = [
        '/^entryTypes.\S+?.titleFormat$/',
    ];

    /**
     * Inits the extractor
     *
     * @param string $defaultCategory Default translation category if missing
     */
    public function __construct(
        private string $defaultCategory = 'site',
        private ?string $baseReferencePath = null,
        private ?string $projectConfigPath = null,
    ) {
    }

    /**
     * Parse translations from a file
     *
     * @param string $file Path to file
     * @param string $category Message category
     * @throws Exception if file don't exists or is a folder
     * @return SortableTranslations|null All found translations
     */
    public function extractFromFile(string $file, ?string $category = null): ?SortableTranslations
    {
        // Check if file exists or is a folder
        if (!file_exists($file) || is_dir($file)) {
            throw new Exception(sprintf('%s doesn\'t exists it or is a folder.', $file));
        }

        // Get file extensions
        $fileExtension = pathinfo($file, PATHINFO_EXTENSION);

        // Extract
        if (in_array($fileExtension, $this->extractors['php'])) {
            return $this->extractFromPhp(file_get_contents($file) ?: '', $file, $category);
        } elseif (in_array($fileExtension, $this->extractors['js'])) {
            return $this->extractFromJs(file_get_contents($file) ?: '', $file, $category);
        } elseif (in_array($fileExtension, $this->extractors['twig'])) {
            return $this->extractFromTwig(file_get_contents($file) ?: '', $file, $category);
        } elseif (in_array($fileExtension, $this->extractors['yaml'])) {
            if (!$this->projectConfigPath || !Path::isBasePath($this->projectConfigPath, $file)) {
                return null;
            }

            return $this->extractFromProjectConfig(file_get_contents($file) ?: '', $file, $category);
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
        return array_reduce(
            array_map(fn (string $file) => $this->extractFromFile($file, $category), $files),
            fn (SortableTranslations $all, ?SortableTranslations $file) => $file ? $all->mergeWith($file) : $all,
            SortableTranslations::create($category),
        );
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
            ->ignoreVCS(true)
            ->ignoreVCSIgnored(true)
            ->name('/^.+\.(' . $fileExtensions . ')$/i');

        $iterator = new IgnoredFilterIterator($finder->getIterator(), $path);

        // Get all files
        $files = [];
        foreach ($iterator as $file) {
            $files[] = $file->getRealPath();
        }

        return $this->extractFromFiles($files, $category);
    }

    private function extractFromProjectConfig(
        string $contents,
        string $file,
        ?string $category = null,
    ): ?SortableTranslations {
        if (!$this->projectConfigPath) {
            return null;
        }

        $relativePath = substr($file, strlen($this->projectConfigPath) + 1);
        $filename = Path::getFilenameWithoutExtension($relativePath);

        if (preg_match('/^\w+--(' . self::UUID_PATTERN . ')$/', $filename, $match)) {
            $basePath = $match[1];
        } else {
            $basePath = $filename;
        }

        if (str_contains($relativePath, DIRECTORY_SEPARATOR)) {
            $configPath = explode(DIRECTORY_SEPARATOR, dirname($relativePath));
            $basePath = implode('.', $configPath) . '.' . $basePath;
        } else {
            $basePath = '';
        }

        $translations = SortableTranslations::create($category);
        $contents = (new Parser())->parse($contents);

        /** @var array<string, mixed> */
        $values = [];

        $this->flattenConfigArray($contents, $basePath, $values);

        foreach ($values as $path => $value) {
            if (!is_string($value) || empty($value)) {
                continue;
            }

            foreach ($this->projectConfigSearchPatterns as $searchPattern) {
                if (preg_match($searchPattern, $path)) {
                    $this->addTranslation(
                        translations: $translations,
                        message: $value,
                        file: $file,
                    );
                }
            }

            foreach ($this->projectConfigSearchPatternsAssoc as $searchPattern => $keys) {
                if (preg_match($searchPattern, $path)) {
                    foreach ($keys as $key) {
                        if ($key === $value) {
                            $assocValuePath = preg_replace('/0$/', '1', $path);
                            $assocValue = $values[$assocValuePath] ?? null;

                            if ($assocValue) {
                                $this->addTranslation(
                                    translations: $translations,
                                    message: $assocValue,
                                    file: $file,
                                );
                            }
                        }
                    }
                }
            }

            foreach ($this->projectConfigSearchPatternsTwig as $searchPattern) {
                if (preg_match($searchPattern, $path)) {
                    $twigTranslations = $this->extractFromTwig($value, $file, $category);
                    $translations->mergeWith($twigTranslations);
                }
            }
        }

        return $translations;
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

            // Get message
            $messageArg = $translateFunctionCall->args[1];

            if ($messageArg instanceof VariadicPlaceholder) {
                continue;
            }

            $message = null;

            if ($messageArg->value instanceof String_) {
                $message = $messageArg->value->value;
            }

            if ($messageArg->value instanceof Concat) {
                $message = $this->resolveConcatedStrings($messageArg->value);
            }

            // Skip if message is empty or category doesn't matches
            if (!$message || $category && $category !== $messageCategory) {
                continue;
            }

            $this->addTranslation(
                translations: $translations,
                message: $message,
                file: $file,
                lineNumber: $translateFunctionCall->class->getStartLine(),
            );
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
        $ast = Peast::latest($contents, [
            'sourceType' => Peast::SOURCE_TYPE_MODULE,
        ])->parse();

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

                $this->addTranslation(
                    translations: $translations,
                    message: (string) $messageArg->getValue(),
                    file: $file,
                    lineNumber: $callee->getLocation()->getStart()->getLine(),
                );
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

        // Prepare twig environment
        $twig = new Environment(new ArrayLoader());
        $twig->registerUndefinedFilterCallback(fn (string $name) => new TwigFilter($name));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name));

        // Tokenize input
        $stream = $twig->tokenize(new Source($contents, basename($file), dirname($file)));

        // Parse template
        while (!$stream->isEOF()) {
            $token = $stream->next();

            // Get registerTranslations values
            if ($token->test(Token::NAME_TYPE, 'registerTranslations')) {
                // Open function
                $stream->expect(Token::PUNCTUATION_TYPE, '(');

                // Check for category
                $categoryToken = $stream->next();
                if ($category && !$categoryToken->test(Token::STRING_TYPE, $category)) {
                    continue;
                }

                // Open array
                $stream->expect(Token::PUNCTUATION_TYPE, ',');
                $stream->expect(Token::PUNCTUATION_TYPE, '[');

                /** @var bool $first */
                $first = true;
                while (!$stream->test(Token::PUNCTUATION_TYPE, ']')) {
                    // Check for array correctness
                    if (!$first) {
                        $stream->expect(Token::PUNCTUATION_TYPE, ',');

                        if ($stream->getCurrent()->test(Token::PUNCTUATION_TYPE, ']')) {
                            break;
                        }
                    }

                    $first = false;

                    // Find or create translation
                    $messageToken = $stream->expect(Token::STRING_TYPE);
                    $message = $messageToken->getValue();
                    $translation = $translations->find(null, $message) ?? Translation::create(null, $message);

                    // Add reference
                    if ($this->baseReferencePath) {
                        $file = Path::makeRelative($file, $this->baseReferencePath);
                    }

                    $translation->getReferences()->add($file, $messageToken->getLine());

                    // Add to translations
                    $translations->add($translation);
                }

                $stream->expect(Token::PUNCTUATION_TYPE, ']', 'An opened array is not properly closed');
            }
        }

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

            // Skip if category doesn't matches
            if ($category && $category !== $messageCategory) {
                continue;
            }

            $this->addTranslation(
                translations: $translations,
                message: $message,
                file: $file,
                lineNumber: $this->getLineNumber($contents, $position),
            );
        }

        // Return translations
        return $translations;
    }

    private function addTranslation(
        Translations $translations,
        string $message,
        string $file,
        ?int $lineNumber = null,
    ): void {
        $translation = $translations->find(null, $message) ?? Translation::create(null, $message);

        if ($this->baseReferencePath) {
            $file = Path::makeRelative($file, $this->baseReferencePath);
        }

        $translation->getReferences()->add($file, $lineNumber);

        $translations->add($translation);
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

    /**
     * Resolves concacted strings
     *
     * @param Concat $node
     * @return null|string
     */
    private function resolveConcatedStrings(Concat $node): ?string
    {
        $result = '';

        if ($node->left instanceof String_) {
            $result .= $node->left->value;
        } elseif ($node->left instanceof Concat) {
            $result .= $this->resolveConcatedStrings($node->left);
        } else {
            return null;
        }

        if ($node->right instanceof String_) {
            $result .= $node->right->value;
        } elseif ($node->right instanceof Concat) {
            $result .= $this->resolveConcatedStrings($node->right);
        } else {
            return null;
        }

        return $result;
    }

    private function flattenConfigArray(array $array, string $path, array &$result): void
    {
        foreach ($array as $key => $value) {
            $thisPath = ltrim($path . '.' . $key, '.');

            if (is_array($value)) {
                $this->flattenConfigArray($value, $thisPath, $result);
            } else {
                $result[$thisPath] = $value;
            }
        }
    }
}
