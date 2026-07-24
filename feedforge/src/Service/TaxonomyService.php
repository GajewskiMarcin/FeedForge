<?php

declare(strict_types=1);

namespace FeedForge\Service;

use FeedForge\Repository\TaxonomyRepository;

/**
 * Manages Google product taxonomy data (categories).
 * Provides FULLTEXT search for category mapping autocomplete.
 */
class TaxonomyService
{
    /** Google taxonomy file URL pattern (uses locale codes like en-US, pl-PL) */
    private const TAXONOMY_URL = 'https://www.google.com/basepages/producttype/taxonomy-with-ids.%s.txt';

    /** Map short lang codes to Google locale codes used in taxonomy file URLs */
    private const LANG_TO_LOCALE = [
        'en' => 'en-US',
        'pl' => 'pl-PL',
        'de' => 'de-DE',
        'fr' => 'fr-FR',
        'es' => 'es-ES',
        'it' => 'it-IT',
        'nl' => 'nl-NL',
        'pt' => 'pt-BR',
        'cs' => 'cs-CZ',
        'sk' => 'sk-SK',
    ];

    /** Supported languages for taxonomy */
    private const SUPPORTED_LANGS = ['en', 'pl', 'de', 'fr', 'es', 'it', 'nl', 'pt', 'cs', 'sk'];

    public function __construct(
        private readonly TaxonomyRepository $taxonomyRepository,
    ) {
    }

    /**
     * Search Google taxonomy by query string.
     *
     * @return array[] Each: ['taxonomy_id' => int, 'value' => string]
     */
    public function search(string $query, string $lang = 'en', int $limit = 20): array
    {
        $query = trim($query);
        if (strlen($query) < 2) {
            return [];
        }

        // Numeric query → look up by taxonomy ID (exact or prefix match)
        if (ctype_digit($query)) {
            return $this->taxonomyRepository->searchById($query, $lang, $limit);
        }

        // Otherwise FULLTEXT search on the path string
        $searchTerms = $this->prepareSearchTerms($query);

        return $this->taxonomyRepository->search($searchTerms, $lang, $limit);
    }

    /**
     * Get a taxonomy entry by its numeric ID.
     */
    public function findById(int $taxonomyId, string $lang = 'en'): ?array
    {
        return $this->taxonomyRepository->findById($taxonomyId, $lang);
    }

    /**
     * Import/refresh taxonomy data from Google's official file.
     *
     * @return int Number of entries imported
     */
    public function importFromGoogle(string $lang = 'en'): int
    {
        if (!in_array($lang, self::SUPPORTED_LANGS, true)) {
            throw new \InvalidArgumentException("Unsupported taxonomy language: {$lang}");
        }

        $locale = self::LANG_TO_LOCALE[$lang] ?? $lang;
        $url = sprintf(self::TAXONOMY_URL, $locale);
        $content = @file_get_contents($url);

        if ($content === false) {
            throw new \RuntimeException("Failed to download taxonomy file from: {$url}");
        }

        $lines = explode("\n", $content);
        $count = 0;

        // Clear existing data for this language
        $this->taxonomyRepository->clearByLang($lang);

        $batch = [];
        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            // Format: "ID - Category > Subcategory > ..."
            $separatorPos = strpos($line, ' - ');
            if ($separatorPos === false) {
                continue;
            }

            $taxonomyId = (int) substr($line, 0, $separatorPos);
            $value = trim(substr($line, $separatorPos + 3));

            if ($taxonomyId <= 0 || empty($value)) {
                continue;
            }

            $batch[] = [
                'taxonomy_id' => $taxonomyId,
                'value' => $value,
                'lang' => $lang,
            ];

            // Insert in batches of 500
            if (count($batch) >= 500) {
                $this->taxonomyRepository->importBatch($batch);
                $count += count($batch);
                $batch = [];
            }
        }

        // Insert remaining
        if (!empty($batch)) {
            $this->taxonomyRepository->importBatch($batch);
            $count += count($batch);
        }

        return $count;
    }

    /**
     * Check if taxonomy data is loaded for a language.
     */
    public function isLoaded(string $lang = 'en'): bool
    {
        $result = $this->search('electronics', $lang, 1);

        return !empty($result);
    }

    /**
     * Prepare search terms for FULLTEXT boolean mode.
     * Adds + prefix for mandatory terms and * suffix for partial matching.
     */
    private function prepareSearchTerms(string $query): string
    {
        $words = preg_split('/\s+/', trim($query));
        $terms = [];

        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) >= 2) {
                // Add + for mandatory and * for prefix matching
                $terms[] = '+' . $word . '*';
            }
        }

        return implode(' ', $terms);
    }
}
