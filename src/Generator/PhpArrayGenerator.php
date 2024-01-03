<?php

/**
 * @author Dennis Morhardt <info@dennismorhardt.de>
 * @copyright 2024 Dennis Morhardt
 */

namespace gglnx\ExtractCraftTranslations\Generator;

use Gettext\Generator\Generator;
use Gettext\Translations;
use PhpParser\BuilderHelpers;
use PhpParser\Comment;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\PrettyPrinter\Standard;

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

            /** @var String_ $original */
            $original = BuilderHelpers::normalizeValue($translation->getOriginal());
            if (strpos($translation->getOriginal(), "'") !== false) {
                $original->setAttribute('kind', String_::KIND_DOUBLE_QUOTED);
            }

            /** @var String_ $translated */
            if ($translationString = $translation->getTranslation()) {
                $translated = BuilderHelpers::normalizeValue($translationString);

                if (strpos($translationString, "'") !== false) {
                    $translated->setAttribute('kind', String_::KIND_DOUBLE_QUOTED);
                }
            } else {
                $translated = BuilderHelpers::normalizeValue(null);
            }

            $comments = [];
            foreach ($translation->getReferences() as $filename => $lineNumbers) {
                if (empty($lineNumbers)) {
                    $comments[] = new Comment(sprintf('#: %s', $filename));
                    continue;
                }

                foreach ($lineNumbers as $number) {
                    $comments[] = new Comment(sprintf('#: %s:%d', $filename, $number));
                }
            }

            $messages[] = new ArrayItem($translated, $original, attributes: ['comments' => $comments]);
        }

        // Build php file
        $prettyPrinter = new Standard([
            'shortArraySyntax' => true,
        ]);

        return "<?php\n\n" . $prettyPrinter->prettyPrint([
            new Return_(
                new Array_($messages),
            )
        ]) . "\n";
    }
}
