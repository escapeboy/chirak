<?php

namespace App\Support;

/**
 * PHP port of the worker's student prompt (worker/chirak_worker/prompts.py: build_student_system_prompt) and its
 * strict answer parser (parsing.py: parse_student_answer), so the Claude judge sees exactly what the student sees.
 */
class StudentPrompt
{
    public static function system(array $spec): string
    {
        $parts = [
            self::strip($spec['description'] ?? ''),
            'Answer with exactly one compact JSON object and nothing else. It must have exactly these keys, in this order:',
            implode("\n", array_map([self::class, 'fieldLine'], $spec['fields'])),
        ];
        $guidelines = self::strip($spec['guidelines'] ?? '');
        if ($guidelines !== '') {
            $parts[] = "Labelling rules:\n".$guidelines;
        }

        return implode("\n\n", $parts);
    }

    /** The judge's raw answer to a label, or null where the worker's strict parser would refuse it. */
    public static function parse(string $raw, array $spec): ?array
    {
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', self::strip($raw));
        $obj = json_decode(self::strip($raw));
        if (! $obj instanceof \stdClass) {
            return null;
        }
        $obj = (array) $obj;

        $names = array_column($spec['fields'], 'name');
        if (array_diff($names, array_keys($obj)) || array_diff(array_keys($obj), $names)) {
            return null;
        }
        $out = [];
        foreach ($spec['fields'] as $field) {
            $value = $obj[$field['name']];
            $ok = $field['type'] === 'bool' ? is_bool($value) : (is_string($value) && in_array($value, $field['values'] ?? [], true));
            if (! $ok) {
                return null;
            }
            $out[$field['name']] = $value;
        }

        return $out;
    }

    private static function fieldLine(array $field): string
    {
        if ($field['type'] === 'enum') {
            $values = array_map(fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $field['values']);

            return '- "'.$field['name'].'": one of ['.implode(', ', $values).']';
        }

        return '- "'.$field['name'].'": true or false';
    }

    /** Python's str.strip(): all Unicode whitespace. */
    private static function strip(string $s): string
    {
        return preg_replace('/^\s+|\s+$/u', '', $s);
    }
}
