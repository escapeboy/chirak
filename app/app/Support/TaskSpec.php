<?php

namespace App\Support;

class TaskSpec
{
    /** @return string[] */
    public static function fieldErrors(mixed $fields): array
    {
        if (! is_array($fields) || ! array_is_list($fields) || ! $fields) {
            return ['fields must be a JSON list of field objects'];
        }

        $errors = [];
        $primary = 0;

        foreach ($fields as $i => $field) {
            $label = 'field '.($i + 1);
            if (! is_array($field) || ! preg_match('/^[a-z][a-z0-9_]*$/', (string) ($field['name'] ?? ''))) {
                $errors[] = "$label: name must be lower_snake_case";

                continue;
            }

            $type = $field['type'] ?? null;
            if (! in_array($type, ['enum', 'bool'], true)) {
                $errors[] = "$label: type must be enum or bool";
            }
            if ($type === 'enum' && (! is_array($field['values'] ?? null) || count($field['values']) < 2)) {
                $errors[] = "$label: an enum needs at least two values";
            }
            if (! empty($field['primary'])) {
                $primary++;
                if ($type !== 'enum') {
                    $errors[] = "$label: the primary field must be an enum";
                }
            }
        }

        if ($primary !== 1) {
            $errors[] = 'exactly one field must have "primary": true';
        }

        return $errors;
    }
}
