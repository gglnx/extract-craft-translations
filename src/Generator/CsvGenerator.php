<?php

/**
 * @author Dennis Morhardt <info@dennismorhardt.de>
 * @copyright 2024 Dennis Morhardt
 */

namespace gglnx\ExtractCraftTranslations\Generator;

use Gettext\Generator\Generator;
use Gettext\Translations;
use gglnx\ExtractCraftTranslations\Exception;

/**
 * CSV generator for gettext
 *
 * @package gglnx\ExtractCraftTranslations\Generator
 */
final class CsvGenerator extends Generator
{
    /**
     * @inheritdoc
     */
    public function generateString(Translations $translations): string
    {
        // Catch all output
        ob_start();

        // Return all translation strings
        $stream = fopen('php://output', 'w') ?: throw new Exception();

        foreach ($translations as $translation) {
            if ($translation->isDisabled()) {
                continue;
            }

            fputcsv($stream, [
                $translation->getOriginal(),
                $translation->getTranslation(),
            ]);
        }

        // Close stream and return output
        fclose($stream);

        return (string) ob_get_clean();
    }
}
