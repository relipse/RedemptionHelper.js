<?php
/**
 * SearchEngine - Multi-aspect email search with synonym expansion.
 */

require_once __DIR__ . '/MailParser.php';
require_once __DIR__ . '/SynonymEngine.php';

class SearchEngine
{
    private SynonymEngine $synonymEngine;

    /** @var array Cached parsed emails */
    private array $emails = [];

    /** @var array Source file paths that have been loaded */
    private array $loadedFiles = [];

    public function __construct(?SynonymEngine $synonymEngine = null)
    {
        $this->synonymEngine = $synonymEngine ?? new SynonymEngine();
    }

    /**
     * Load mail files from a directory.
     */
    public function loadDirectory(string $dir): int
    {
        $files = MailParser::scanDirectory($dir);
        $count = 0;
        foreach ($files as $file) {
            $count += $this->loadFile($file);
        }
        return $count;
    }

    /**
     * Load a single mail file.
     */
    public function loadFile(string $filePath): int
    {
        if (in_array($filePath, $this->loadedFiles, true)) {
            return 0;
        }

        $parsed = MailParser::parseFile($filePath);
        $this->loadedFiles[] = $filePath;

        foreach ($parsed as $email) {
            $this->emails[] = $email;
        }

        return count($parsed);
    }

    /**
     * Get count of loaded emails.
     */
    public function getEmailCount(): int
    {
        return count($this->emails);
    }

    /**
     * Get all loaded emails.
     */
    public function getAllEmails(): array
    {
        return $this->emails;
    }

    /**
     * Get the synonym engine.
     */
    public function getSynonymEngine(): SynonymEngine
    {
        return $this->synonymEngine;
    }

    /**
     * Search emails with multiple criteria.
     *
     * @param array $criteria {
     *     @type string $query       Main search query
     *     @type bool   $useSynonyms Whether to expand query with synonyms
     *     @type array  $fields      Fields to search: 'from', 'to', 'cc', 'bcc', 'subject', 'body', 'attachments', 'headers_raw', 'date'
     *     @type string $dateFrom    Start date (Y-m-d)
     *     @type string $dateTo      End date (Y-m-d)
     *     @type bool   $caseSensitive  Whether search is case-sensitive
     *     @type bool   $regex       Whether query is a regex pattern
     *     @type bool   $wholeWord   Whether to match whole words only
     * }
     * @return array Search results with match details
     */
    public function search(array $criteria): array
    {
        $query         = trim($criteria['query'] ?? '');
        $useSynonyms   = (bool)($criteria['useSynonyms'] ?? false);
        $fields        = $criteria['fields'] ?? ['from', 'to', 'cc', 'subject', 'body'];
        $dateFrom      = $criteria['dateFrom'] ?? '';
        $dateTo        = $criteria['dateTo'] ?? '';
        $caseSensitive = (bool)($criteria['caseSensitive'] ?? false);
        $regex         = (bool)($criteria['regex'] ?? false);
        $wholeWord     = (bool)($criteria['wholeWord'] ?? false);

        if (empty($query) && empty($dateFrom) && empty($dateTo)) {
            return ['results' => $this->emails, 'terms' => [], 'totalEmails' => count($this->emails)];
        }

        // Build search terms
        $searchTerms = [];
        if (!empty($query)) {
            if ($useSynonyms && !$regex) {
                $searchTerms = $this->synonymEngine->expandSearch($query);
            } else {
                $searchTerms = [$query];
            }
        }

        $results = [];

        foreach ($this->emails as $idx => $email) {
            // Date filtering
            if (!empty($dateFrom) || !empty($dateTo)) {
                $emailDate = $this->parseEmailDate($email['date'] ?? '');
                if ($emailDate) {
                    if (!empty($dateFrom) && $emailDate < strtotime($dateFrom)) {
                        continue;
                    }
                    if (!empty($dateTo) && $emailDate > strtotime($dateTo . ' 23:59:59')) {
                        continue;
                    }
                } elseif (!empty($dateFrom) || !empty($dateTo)) {
                    // If we can't parse the date and date filter is active, skip
                    continue;
                }
            }

            // Text search
            if (!empty($searchTerms)) {
                $matchInfo = $this->matchEmail($email, $searchTerms, $fields, $caseSensitive, $regex, $wholeWord);
                if ($matchInfo['matched']) {
                    $email['_matchInfo'] = $matchInfo;
                    $email['_index'] = $idx;
                    $results[] = $email;
                }
            } else {
                // No text query, just date filter matched
                $email['_matchInfo'] = ['matched' => true, 'matchedTerms' => [], 'matchedFields' => [], 'highlights' => []];
                $email['_index'] = $idx;
                $results[] = $email;
            }
        }

        // Sort: results matching more terms/fields first
        usort($results, function ($a, $b) {
            $aScore = count($a['_matchInfo']['matchedTerms']) + count($a['_matchInfo']['matchedFields']);
            $bScore = count($b['_matchInfo']['matchedTerms']) + count($b['_matchInfo']['matchedFields']);
            return $bScore - $aScore;
        });

        return [
            'results'     => $results,
            'terms'       => $searchTerms,
            'totalEmails' => count($this->emails),
        ];
    }

    /**
     * Check if an email matches the search terms.
     */
    private function matchEmail(array $email, array $terms, array $fields, bool $caseSensitive, bool $regex, bool $wholeWord): array
    {
        $matchedTerms = [];
        $matchedFields = [];
        $highlights = [];

        foreach ($terms as $term) {
            foreach ($fields as $field) {
                $value = '';
                if ($field === 'attachments') {
                    $value = implode(' ', $email['attachments'] ?? []);
                } else {
                    $value = $email[$field] ?? '';
                }

                if (empty($value)) {
                    continue;
                }

                if ($this->textMatches($value, $term, $caseSensitive, $regex, $wholeWord)) {
                    $matchedTerms[$term] = true;
                    $matchedFields[$field] = true;

                    // Create highlight snippet
                    $snippet = $this->createSnippet($value, $term, $caseSensitive, $regex);
                    if ($snippet) {
                        $highlights[] = [
                            'field'   => $field,
                            'term'    => $term,
                            'snippet' => $snippet,
                        ];
                    }
                }
            }
        }

        return [
            'matched'       => !empty($matchedTerms),
            'matchedTerms'  => array_keys($matchedTerms),
            'matchedFields' => array_keys($matchedFields),
            'highlights'    => $highlights,
        ];
    }

    /**
     * Check if text contains a search term.
     */
    private function textMatches(string $text, string $term, bool $caseSensitive, bool $regex, bool $wholeWord): bool
    {
        if ($regex) {
            $pattern = '/' . $term . '/' . ($caseSensitive ? '' : 'i');
            return @preg_match($pattern, $text) === 1;
        }

        if ($wholeWord) {
            $escaped = preg_quote($term, '/');
            $pattern = '/\b' . $escaped . '\b/' . ($caseSensitive ? '' : 'i');
            return preg_match($pattern, $text) === 1;
        }

        if ($caseSensitive) {
            return strpos($text, $term) !== false;
        }

        return stripos($text, $term) !== false;
    }

    /**
     * Create a context snippet around the matched term.
     */
    private function createSnippet(string $text, string $term, bool $caseSensitive, bool $regex, int $contextChars = 80): string
    {
        if ($regex) {
            $pattern = '/' . $term . '/' . ($caseSensitive ? '' : 'i');
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1];
                $matchLen = strlen($m[0][0]);
            } else {
                return '';
            }
        } else {
            $pos = $caseSensitive ? strpos($text, $term) : stripos($text, $term);
            $matchLen = strlen($term);
        }

        if ($pos === false) {
            return '';
        }

        $start = max(0, $pos - $contextChars);
        $end = min(strlen($text), $pos + $matchLen + $contextChars);

        $snippet = '';
        if ($start > 0) $snippet .= '...';
        $snippet .= substr($text, $start, $end - $start);
        if ($end < strlen($text)) $snippet .= '...';

        return $snippet;
    }

    /**
     * Try to parse various email date formats into a Unix timestamp.
     */
    private function parseEmailDate(string $dateStr): ?int
    {
        if (empty($dateStr)) {
            return null;
        }

        $ts = strtotime($dateStr);
        if ($ts !== false) {
            return $ts;
        }

        // Try removing timezone abbreviations in parentheses
        $cleaned = preg_replace('/\s*\([^)]+\)\s*$/', '', $dateStr);
        $ts = strtotime($cleaned);
        if ($ts !== false) {
            return $ts;
        }

        return null;
    }

    /**
     * Get statistics about loaded emails.
     */
    public function getStats(): array
    {
        $stats = [
            'totalEmails'  => count($this->emails),
            'totalFiles'   => count($this->loadedFiles),
            'files'        => $this->loadedFiles,
            'senders'      => [],
            'dateRange'    => ['earliest' => null, 'latest' => null],
            'withAttachments' => 0,
        ];

        foreach ($this->emails as $email) {
            // Count senders
            $from = strtolower($email['from'] ?? '');
            if (!empty($from)) {
                $stats['senders'][$from] = ($stats['senders'][$from] ?? 0) + 1;
            }

            // Date range
            $ts = $this->parseEmailDate($email['date'] ?? '');
            if ($ts) {
                if ($stats['dateRange']['earliest'] === null || $ts < $stats['dateRange']['earliest']) {
                    $stats['dateRange']['earliest'] = $ts;
                }
                if ($stats['dateRange']['latest'] === null || $ts > $stats['dateRange']['latest']) {
                    $stats['dateRange']['latest'] = $ts;
                }
            }

            // Attachments
            if (!empty($email['attachments'])) {
                $stats['withAttachments']++;
            }
        }

        arsort($stats['senders']);

        if ($stats['dateRange']['earliest']) {
            $stats['dateRange']['earliest'] = date('Y-m-d H:i:s', $stats['dateRange']['earliest']);
        }
        if ($stats['dateRange']['latest']) {
            $stats['dateRange']['latest'] = date('Y-m-d H:i:s', $stats['dateRange']['latest']);
        }

        return $stats;
    }
}
