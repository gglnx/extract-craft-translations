<?php

/**
 * @author Dennis Morhardt <info@dennismorhardt.de>
 * @copyright 2020 Dennis Morhardt
 */

namespace gglnx\ExtractCraftTranslations\Generator;

use Gettext\Generator\Generator;
use Gettext\Translations;
use gglnx\ExtractCraftTranslations\PrettyPrinter\MultiLineArrayPrettyPrinter;
use PhpParser\BuilderFactory;
use PhpParser\Node\Stmt\Return_;

/**
 * PHP array generator for gettext
 *
 * @package gglnx\ExtractCraftTranslations\Generator
 */
final class PhpArrayGenerator extends Generator
{
    /**
     * @inheritdoc
     */
    public function generateString(Translations $translations): string
    {
        // Build array with translations
        $messages = [];
        foreach ($translations as $translation) {
            if ($translation->isDisabled()) {
                continue;
            }

            $original = $translation->getOriginal();
            $messages[$original] = $translation->getTranslation();
        }

        // Convert array to AST
        $factory = new BuilderFactory();
        $arrayWithTranslations = $factory->val($messages);

        // Return node
        $returnNode = new Return_($arrayWithTranslations);

        // Build php file
        $prettyPrinter = new MultiLineArrayPrettyPrinter([
            'shortArraySyntax' => true,
        ]);

        return "<?php\n\n" . $prettyPrinter->prettyPrint([$returnNode]) . "\n";
    }
}
