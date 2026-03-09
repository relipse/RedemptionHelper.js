<?php
/**
 * SynonymEngine - Local synonym/thesaurus engine for expanding search terms.
 *
 * Uses a bundled thesaurus file (synonyms.json) and supports:
 * - Direct synonym lookup
 * - Related phrases
 * - User-defined custom synonym groups
 */
class SynonymEngine
{
    private array $thesaurus = [];
    private array $customGroups = [];
    private string $customFile;

    public function __construct(string $thesaurusFile = '', string $customFile = '')
    {
        $dir = __DIR__;
        if (empty($thesaurusFile)) {
            $thesaurusFile = $dir . '/synonyms.json';
        }
        $this->customFile = $customFile ?: $dir . '/custom_synonyms.json';

        $this->loadThesaurus($thesaurusFile);
        $this->loadCustomSynonyms();
    }

    /**
     * Get all synonyms and related phrases for a word/phrase.
     */
    public function getSynonyms(string $term): array
    {
        $term = strtolower(trim($term));
        if (empty($term)) {
            return [];
        }

        $synonyms = [];

        // Check built-in thesaurus
        if (isset($this->thesaurus[$term])) {
            $synonyms = array_merge($synonyms, $this->thesaurus[$term]);
        }

        // Check reverse lookups - if $term appears as a synonym for another word
        foreach ($this->thesaurus as $key => $syns) {
            if (in_array($term, $syns, true) && $key !== $term) {
                $synonyms[] = $key;
                // Also include sibling synonyms from that group
                foreach ($syns as $s) {
                    if ($s !== $term) {
                        $synonyms[] = $s;
                    }
                }
            }
        }

        // Check custom synonym groups
        foreach ($this->customGroups as $group) {
            $lowerGroup = array_map('strtolower', $group);
            if (in_array($term, $lowerGroup, true)) {
                foreach ($lowerGroup as $word) {
                    if ($word !== $term) {
                        $synonyms[] = $word;
                    }
                }
            }
        }

        $synonyms = array_unique($synonyms);
        // Remove the original term if present
        $synonyms = array_values(array_filter($synonyms, function ($s) use ($term) {
            return strtolower($s) !== $term;
        }));

        sort($synonyms);
        return $synonyms;
    }

    /**
     * Expand a search query into an array of terms (original + synonyms).
     * If the input has multiple words, tries both the full phrase and individual words.
     */
    public function expandSearch(string $query): array
    {
        $query = strtolower(trim($query));
        $terms = [$query];

        // Get synonyms for the full phrase
        $phraseSynonyms = $this->getSynonyms($query);
        $terms = array_merge($terms, $phraseSynonyms);

        // If multi-word, also try individual words
        $words = preg_split('/\s+/', $query);
        if (count($words) > 1) {
            foreach ($words as $word) {
                $word = trim($word);
                if (strlen($word) < 3) continue; // Skip tiny words
                $wordSynonyms = $this->getSynonyms($word);
                $terms = array_merge($terms, $wordSynonyms);
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * Add a custom synonym group.
     */
    public function addCustomGroup(array $words): void
    {
        $words = array_map('trim', $words);
        $words = array_filter($words, function ($w) { return strlen($w) > 0; });
        $words = array_values($words);

        if (count($words) < 2) {
            return;
        }

        $this->customGroups[] = $words;
        $this->saveCustomSynonyms();
    }

    /**
     * Remove a custom synonym group by index.
     */
    public function removeCustomGroup(int $index): void
    {
        if (isset($this->customGroups[$index])) {
            array_splice($this->customGroups, $index, 1);
            $this->saveCustomSynonyms();
        }
    }

    /**
     * Get all custom synonym groups.
     */
    public function getCustomGroups(): array
    {
        return $this->customGroups;
    }

    /**
     * Get all available thesaurus entries (for browsing).
     */
    public function getThesaurusEntries(): array
    {
        return $this->thesaurus;
    }

    private function loadThesaurus(string $file): void
    {
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data)) {
                $this->thesaurus = $data;
            }
        }
    }

    private function loadCustomSynonyms(): void
    {
        if (file_exists($this->customFile)) {
            $data = json_decode(file_get_contents($this->customFile), true);
            if (is_array($data)) {
                $this->customGroups = $data;
            }
        }
    }

    private function saveCustomSynonyms(): void
    {
        file_put_contents(
            $this->customFile,
            json_encode($this->customGroups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}
