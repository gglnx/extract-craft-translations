<?php

/**
 * @author Dennis Morhardt <info@dennismorhardt.de>
 * @copyright 2020 Dennis Morhardt
 */

namespace gglnx\ExtractCraftTranslations\Loader;

use Exception;
use Gettext\Loader\Loader;
use Gettext\Translations;
use ParseError;

/**
 * Loads translations from a file returning an PHP array
 *
 * @package gglnx\ExtractCraftTranslations\Loader
 */
final class PhpArrayLoader extends Loader
{
    /**
     * @inheritdoc
     * @throws Exception If parsing of string failed.
     */
    public function loadString(string $string, Translations $translations = null): Translations
    {
        try {
            $messages = eval($string);

            if (!is_array($messages)) {
                $messages = [];
            }
        } catch (ParseError $e) {
            throw new Exception('Not a PHP array translation file. Parser error: ' . $e->getMessage());
        }

        return $this->convertMessagesToTranslations($messages, $translations);
    }

    /**
     * @inheritdoc
     */
    public function loadFile(string $filename, ?Translations $translations = null): Translations
    {
        $messages = [];
        if (is_file($filename)) {
            $messages = include $filename;
            if (!is_array($messages)) {
                $messages = [];
            }
        }

        return $this->convertMessagesToTranslations($messages, $translations);
    }

    /**
     * Converts a message array into translations.
     *
     * @param array $messages Input array with translated messages.
     * @param Translations|null $translations Base translation object.
     * @return Translations
     */
    private function convertMessagesToTranslations(
        array $messages = [],
        Translations $translations = null
    ): Translations {
        $translations = $translations ?: $this->createTranslations();
        foreach ($messages as $originalString => $translationString) {
            $translation = $this->createTranslation(null, $originalString);
            $translation->translate($translationString);
            $translations->add($translation);
        }

        return $translations;
    }
}
