<?php

namespace App\Support;

/** Parses a JSONL textarea of {input, output} examples and checks every line against the task spec. */
class ExamplesParser
{
    /**
     * @return array{items: array<int, array>, errors: string[]}
     */
    public static function parse(string $jsonl, array $spec): array
    {
        $items = [];
        $errors = [];

        foreach (preg_split('/\r\n|\n|\r/', trim($jsonl)) as $n => $line) {
            if (trim($line) === '') {
                continue;
            }

            $row = json_decode($line, true);
            $lineNo = $n + 1;

            if (! is_array($row) || ! isset($row['input'], $row['output']) || ! is_string($row['input']) || trim($row['input']) === '' || ! is_array($row['output'])) {
                $errors[] = "line $lineNo: needs a non-empty \"input\" string and an \"output\" object";

                continue;
            }

            $problem = self::outputProblem($row['output'], $spec);
            if ($problem) {
                $errors[] = "line $lineNo: $problem";

                continue;
            }

            $items[] = ['input' => $row['input'], 'output' => $row['output']];
        }

        return ['items' => $items, 'errors' => $errors];
    }

    private static function outputProblem(array $output, array $spec): ?string
    {
        $names = array_column($spec['fields'], 'name');
        if (array_diff(array_keys($output), $names) || array_diff($names, array_keys($output))) {
            return 'output must contain exactly the fields '.implode(', ', $names);
        }

        foreach ($spec['fields'] as $field) {
            $value = $output[$field['name']];

            if ($field['type'] === 'enum' && ! in_array($value, $field['values'], true)) {
                return "{$field['name']} must be one of ".implode('/', $field['values']);
            }

            if ($field['type'] === 'bool' && ! is_bool($value)) {
                return "{$field['name']} must be true or false";
            }
        }

        return null;
    }
}
