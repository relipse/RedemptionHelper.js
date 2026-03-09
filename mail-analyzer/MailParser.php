<?php
/**
 * MailParser - Parses EML, MBOX, and MSG mail files into structured data.
 */
class MailParser
{
    /**
     * Parse a single mail file based on its extension.
     * Returns an array of parsed email arrays.
     */
    public static function parseFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        switch ($ext) {
            case 'eml':
                $parsed = self::parseEml($filePath);
                return $parsed ? [$parsed] : [];
            case 'mbox':
                return self::parseMbox($filePath);
            case 'msg':
                $parsed = self::parseMsg($filePath);
                return $parsed ? [$parsed] : [];
            default:
                // Try to parse as EML by default
                $parsed = self::parseEml($filePath);
                return $parsed ? [$parsed] : [];
        }
    }

    /**
     * Scan a directory recursively for mail files.
     */
    public static function scanDirectory(string $dir, array $extensions = ['eml', 'mbox', 'msg', 'mst']): array
    {
        $files = [];
        if (!is_dir($dir)) {
            return $files;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, $extensions)) {
                    $files[] = $file->getRealPath();
                }
            }
        }

        return $files;
    }

    /**
     * Parse a .eml file.
     */
    public static function parseEml(string $filePath): ?array
    {
        $raw = file_get_contents($filePath);
        if ($raw === false) {
            return null;
        }

        return self::parseRawEmail($raw, $filePath);
    }

    /**
     * Parse a .mbox file (contains multiple emails separated by "From " lines).
     */
    public static function parseMbox(string $filePath): array
    {
        $raw = file_get_contents($filePath);
        if ($raw === false) {
            return [];
        }

        $emails = [];
        // Split on lines starting with "From " (mbox separator)
        $parts = preg_split('/^From /m', $raw);

        foreach ($parts as $i => $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }
            // Re-add "From " for proper parsing (except it's the envelope from, skip first line)
            if ($i > 0) {
                // The first line after "From " is the envelope sender + date, skip it
                $nlPos = strpos($part, "\n");
                if ($nlPos !== false) {
                    $part = substr($part, $nlPos + 1);
                }
            }

            $parsed = self::parseRawEmail($part, $filePath . " [message #$i]");
            if ($parsed) {
                $emails[] = $parsed;
            }
        }

        return $emails;
    }

    /**
     * Parse a .msg file (simplified binary parsing for Outlook MSG).
     * Full MSG parsing requires OLE/COM support; this extracts what it can from raw bytes.
     */
    public static function parseMsg(string $filePath): ?array
    {
        $raw = file_get_contents($filePath);
        if ($raw === false) {
            return null;
        }

        $result = [
            'file'        => $filePath,
            'from'        => '',
            'to'          => '',
            'cc'          => '',
            'bcc'         => '',
            'subject'     => '',
            'date'        => '',
            'body'        => '',
            'headers_raw' => '',
            'attachments' => [],
        ];

        // Try to extract readable strings from the binary MSG format
        // Extract subject - often appears as Unicode (UTF-16LE) in MSG
        $strings = self::extractMsgStrings($raw);

        // Try to find common fields from extracted strings
        foreach ($strings as $str) {
            $str = trim($str);
            if (empty($str)) continue;

            if (preg_match('/^Subject:\s*(.+)/i', $str, $m)) {
                $result['subject'] = trim($m[1]);
            } elseif (preg_match('/^From:\s*(.+)/i', $str, $m)) {
                $result['from'] = trim($m[1]);
            } elseif (preg_match('/^To:\s*(.+)/i', $str, $m)) {
                $result['to'] = trim($m[1]);
            } elseif (preg_match('/^Date:\s*(.+)/i', $str, $m)) {
                $result['date'] = trim($m[1]);
            } elseif (preg_match('/^CC:\s*(.+)/i', $str, $m)) {
                $result['cc'] = trim($m[1]);
            }
        }

        // Extract body: grab the longest readable text block
        $textBlocks = [];
        // Find ASCII/UTF8 text runs of at least 40 chars
        if (preg_match_all('/[\x20-\x7E\r\n\t]{40,}/', $raw, $matches)) {
            $textBlocks = $matches[0];
        }
        // Also try UTF-16LE text
        $utf16Text = self::extractUtf16Text($raw);
        if (strlen($utf16Text) > 40) {
            $textBlocks[] = $utf16Text;
        }

        if (!empty($textBlocks)) {
            usort($textBlocks, function ($a, $b) {
                return strlen($b) - strlen($a);
            });
            $result['body'] = trim($textBlocks[0]);
        }

        // If subject is empty, try extracting from body or text blocks
        if (empty($result['subject']) && count($textBlocks) > 1) {
            // Use a short early text block as a fallback subject hint
            foreach ($textBlocks as $block) {
                $block = trim($block);
                if (strlen($block) > 5 && strlen($block) < 200 && $block !== $result['body']) {
                    $result['subject'] = $block;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Parse raw email text (RFC 2822 format).
     */
    private static function parseRawEmail(string $raw, string $sourceFile): ?array
    {
        $result = [
            'file'        => $sourceFile,
            'from'        => '',
            'to'          => '',
            'cc'          => '',
            'bcc'         => '',
            'subject'     => '',
            'date'        => '',
            'body'        => '',
            'headers_raw' => '',
            'attachments' => [],
        ];

        // Split headers from body
        $headerEnd = strpos($raw, "\r\n\r\n");
        if ($headerEnd === false) {
            $headerEnd = strpos($raw, "\n\n");
        }

        if ($headerEnd === false) {
            // No clear separation - treat entire content as body
            $result['body'] = $raw;
            return $result;
        }

        $headerSection = substr($raw, 0, $headerEnd);
        $bodySection = substr($raw, $headerEnd);
        $bodySection = ltrim($bodySection, "\r\n");

        $result['headers_raw'] = $headerSection;

        // Parse headers (unfold continuation lines)
        $headerSection = preg_replace('/\r?\n[ \t]+/', ' ', $headerSection);
        $headers = [];
        foreach (explode("\n", $headerSection) as $line) {
            $line = trim($line, "\r");
            if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m)) {
                $name = strtolower($m[1]);
                $value = $m[2];
                $headers[$name] = $value;
            }
        }

        $result['from']    = self::decodeHeader($headers['from'] ?? '');
        $result['to']      = self::decodeHeader($headers['to'] ?? '');
        $result['cc']      = self::decodeHeader($headers['cc'] ?? '');
        $result['bcc']     = self::decodeHeader($headers['bcc'] ?? '');
        $result['subject'] = self::decodeHeader($headers['subject'] ?? '');
        $result['date']    = $headers['date'] ?? '';

        // Determine content type and decode body
        $contentType = $headers['content-type'] ?? 'text/plain';
        $transferEncoding = strtolower($headers['content-transfer-encoding'] ?? '7bit');

        if (stripos($contentType, 'multipart/') !== false) {
            $parsed = self::parseMultipart($bodySection, $contentType);
            $result['body'] = $parsed['body'];
            $result['attachments'] = $parsed['attachments'];
        } else {
            $result['body'] = self::decodeBody($bodySection, $transferEncoding, $contentType);
        }

        return $result;
    }

    /**
     * Parse multipart MIME body.
     */
    private static function parseMultipart(string $body, string $contentType): array
    {
        $result = ['body' => '', 'attachments' => []];

        // Extract boundary
        if (!preg_match('/boundary="?([^";\s]+)"?/i', $contentType, $m)) {
            $result['body'] = $body;
            return $result;
        }
        $boundary = $m[1];

        $parts = explode("--$boundary", $body);
        $textParts = [];
        $htmlParts = [];

        foreach ($parts as $part) {
            $part = trim($part, "\r\n");
            if (empty($part) || $part === '--') {
                continue;
            }

            // Split part headers from part body
            $partHeaderEnd = strpos($part, "\r\n\r\n");
            if ($partHeaderEnd === false) {
                $partHeaderEnd = strpos($part, "\n\n");
            }
            if ($partHeaderEnd === false) {
                continue;
            }

            $partHeaders = substr($part, 0, $partHeaderEnd);
            $partBody = ltrim(substr($part, $partHeaderEnd), "\r\n");

            // Parse part content type
            $partCt = 'text/plain';
            if (preg_match('/Content-Type:\s*([^;\r\n]+)/i', $partHeaders, $ctm)) {
                $partCt = strtolower(trim($ctm[1]));
            }
            $partTe = '7bit';
            if (preg_match('/Content-Transfer-Encoding:\s*(\S+)/i', $partHeaders, $tem)) {
                $partTe = strtolower(trim($tem[1]));
            }

            // Check for nested multipart
            if (strpos($partCt, 'multipart/') !== false) {
                $nested = self::parseMultipart($partBody, $partCt);
                if (!empty($nested['body'])) {
                    $textParts[] = $nested['body'];
                }
                $result['attachments'] = array_merge($result['attachments'], $nested['attachments']);
                continue;
            }

            // Check if attachment
            if (preg_match('/Content-Disposition:\s*attachment/i', $partHeaders)) {
                $filename = 'unknown';
                if (preg_match('/filename="?([^";\r\n]+)"?/i', $partHeaders, $fnm)) {
                    $filename = trim($fnm[1]);
                }
                $result['attachments'][] = $filename;
                continue;
            }

            $decoded = self::decodeBody($partBody, $partTe, $partCt);

            if (strpos($partCt, 'text/plain') !== false) {
                $textParts[] = $decoded;
            } elseif (strpos($partCt, 'text/html') !== false) {
                $htmlParts[] = $decoded;
            }
        }

        // Prefer plain text, fall back to HTML stripped of tags
        if (!empty($textParts)) {
            $result['body'] = implode("\n", $textParts);
        } elseif (!empty($htmlParts)) {
            $result['body'] = strip_tags(implode("\n", $htmlParts));
        }

        return $result;
    }

    /**
     * Decode email body based on transfer encoding.
     */
    private static function decodeBody(string $body, string $encoding, string $contentType): string
    {
        switch ($encoding) {
            case 'base64':
                $body = base64_decode($body);
                break;
            case 'quoted-printable':
                $body = quoted_printable_decode($body);
                break;
        }

        // Convert charset if specified
        if (preg_match('/charset="?([^";\s]+)"?/i', $contentType, $m)) {
            $charset = strtoupper(trim($m[1]));
            if ($charset !== 'UTF-8' && $charset !== 'US-ASCII') {
                $converted = @iconv($charset, 'UTF-8//IGNORE', $body);
                if ($converted !== false) {
                    $body = $converted;
                }
            }
        }

        // Strip HTML tags if HTML content
        if (stripos($contentType, 'text/html') !== false) {
            $body = strip_tags($body);
        }

        return trim($body);
    }

    /**
     * Decode MIME encoded header values (RFC 2047).
     */
    private static function decodeHeader(string $value): string
    {
        if (empty($value)) return '';

        // Decode =?charset?encoding?text?= sequences
        $decoded = preg_replace_callback(
            '/=\?([^?]+)\?([BQ])\?([^?]+)\?=/i',
            function ($m) {
                $charset = $m[1];
                $encoding = strtoupper($m[2]);
                $text = $m[3];

                if ($encoding === 'B') {
                    $text = base64_decode($text);
                } elseif ($encoding === 'Q') {
                    $text = str_replace('_', ' ', $text);
                    $text = quoted_printable_decode($text);
                }

                if (strtoupper($charset) !== 'UTF-8') {
                    $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
                    if ($converted !== false) {
                        $text = $converted;
                    }
                }

                return $text;
            },
            $value
        );

        return trim($decoded);
    }

    /**
     * Extract readable strings from MSG binary data.
     */
    private static function extractMsgStrings(string $data): array
    {
        $strings = [];
        // Extract ASCII strings of 5+ chars
        if (preg_match_all('/[\x20-\x7E]{5,}/', $data, $matches)) {
            $strings = array_merge($strings, $matches[0]);
        }
        return $strings;
    }

    /**
     * Extract UTF-16LE text from binary data.
     */
    private static function extractUtf16Text(string $data): string
    {
        // Find runs of UTF-16LE printable characters (ASCII char followed by null byte)
        $text = '';
        $len = strlen($data);
        $run = '';

        for ($i = 0; $i < $len - 1; $i += 2) {
            $lo = ord($data[$i]);
            $hi = ord($data[$i + 1]);

            if ($hi === 0 && $lo >= 0x20 && $lo <= 0x7E) {
                $run .= chr($lo);
            } elseif ($hi === 0 && ($lo === 0x0A || $lo === 0x0D)) {
                $run .= chr($lo);
            } else {
                if (strlen($run) > strlen($text)) {
                    $text = $run;
                }
                $run = '';
            }
        }

        if (strlen($run) > strlen($text)) {
            $text = $run;
        }

        return $text;
    }
}
