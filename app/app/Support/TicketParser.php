<?php

namespace App\Support;

use InvalidArgumentException;

/** Reads pasted or uploaded tickets, one per row: JSONL (a string or an object with `text`) or CSV (column `text`, else the first). */
class TicketParser
{
    public const MIN = 100;
    public const MAX = 1000;
    public const MAX_CHARS = 2000;

    /**
     * @return string[] the raw ticket texts, empty rows left out
     *
     * @throws InvalidArgumentException when the count is outside MIN..MAX or a row cannot be read
     */
    public static function parse(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $tickets = in_array(ltrim($content)[0] ?? '', ['{', '"'], true) ? self::jsonl($content) : self::csv($content);
        $tickets = array_values(array_filter(array_map(fn ($t) => mb_substr(trim($t), 0, self::MAX_CHARS), $tickets), fn ($t) => $t !== ''));

        if (count($tickets) < self::MIN || count($tickets) > self::MAX) {
            throw new InvalidArgumentException('Upload between '.self::MIN.' and '.self::MAX.' tickets; found '.count($tickets).'.');
        }

        return $tickets;
    }

    private static function jsonl(string $content): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\n|\r/', $content) as $n => $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = json_decode($line, true);
            $text = is_array($row) ? ($row['text'] ?? null) : $row;
            if (! is_string($text)) {
                throw new InvalidArgumentException('Line '.($n + 1).' is not a JSON string or an object with a "text" string.');
            }
            $out[] = $text;
        }

        return $out;
    }

    private static function csv(string $content): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if ($row !== [null]) {
                $rows[] = $row;
            }
        }
        fclose($stream);

        $column = 0;
        if ($rows && ($found = array_search('text', array_map(fn ($c) => strtolower(trim((string) $c)), $rows[0]), true)) !== false) {
            $column = $found;
            array_shift($rows);
        }

        return array_map(fn ($row) => (string) ($row[$column] ?? ''), $rows);
    }
}
