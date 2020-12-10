<?php

/**
 * @author Dennis Morhardt <info@dennismorhardt.de>
 * @copyright 2020 Dennis Morhardt
 */

namespace gglnx\ExtractCraftTranslations\PrettyPrinter;

use PhpParser\Node\Expr\Array_;
use PhpParser\PrettyPrinter\Standard;

/**
 * Prints arrays as multiline arrays
 *
 * @package gglnx\ExtractCraftTranslations
 */
class MultiLineArrayPrettyPrinter extends Standard
{
    /**
     * @inheritdoc
     */
    protected function pExpr_Array(Array_ $node)
    {
        return '[' . $this->pCommaSeparatedMultiline($node->items, true) . $this->nl . ']';
    }
}
