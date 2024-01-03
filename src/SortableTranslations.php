<?php

/**
 * @author Dennis Morhardt <info@dennismorhardt.de>
 * @copyright 2024 Dennis Morhardt
 */

namespace gglnx\ExtractCraftTranslations;

use Gettext\Translation;
use Gettext\Translations;

/**
 * @method static self create(string|null $domain = null, string|null $language = null)
 * @method self mergeWith(Translations $translations, int $strategy = 0)
 * @package gglnx\ExtractCraftTranslations
 */
class SortableTranslations extends Translations
{
    /**
     * Sorts translations alphabetical
     *
     * @return void
     */
    public function sort(): void
    {
        uasort($this->translations, function (Translation $a, Translation $b) {
            return strcasecmp($a->getOriginal(), $b->getOriginal());
        });
    }
}
