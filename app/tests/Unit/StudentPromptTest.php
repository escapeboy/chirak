<?php

namespace Tests\Unit;

use App\Support\StudentPrompt;
use PHPUnit\Framework\TestCase;

class StudentPromptTest extends TestCase
{
    private function spec(string $guidelines = ''): array
    {
        return [
            'name' => 'merchant-ticket-triage', 'description' => " Triage incoming customer-support tickets of an online shop.\n",
            'fields' => [
                ['name' => 'category', 'type' => 'enum', 'primary' => true, 'values' => ['orders', 'shipping', 'returns', 'payments', 'product_questions', 'complaints', 'other']],
                ['name' => 'urgency', 'type' => 'enum', 'values' => ['low', 'normal', 'high']],
                ['name' => 'needs_human', 'type' => 'bool'],
            ],
            'guidelines' => $guidelines,
        ];
    }

    public function test_the_system_prompt_is_what_the_worker_builds(): void
    {
        // Output of worker/chirak_worker/prompts.py build_student_system_prompt for the same spec.
        $expected = <<<'TXT'
Triage incoming customer-support tickets of an online shop.

Answer with exactly one compact JSON object and nothing else. It must have exactly these keys, in this order:

- "category": one of ["orders", "shipping", "returns", "payments", "product_questions", "complaints", "other"]
- "urgency": one of ["low", "normal", "high"]
- "needs_human": true or false

Labelling rules:
category: the main topic.
urgency: high when money is lost.
TXT;

        $this->assertSame($expected, StudentPrompt::system($this->spec("category: the main topic.\nurgency: high when money is lost.\n")));
        $this->assertStringNotContainsString('Labelling rules', StudentPrompt::system($this->spec('  ')));
    }

    public function test_values_keep_unicode_and_slashes_like_python_json_dumps(): void
    {
        $spec = ['description' => 'd', 'fields' => [['name' => 'c', 'type' => 'enum', 'primary' => true, 'values' => ['поръчки', 'a/b']]]];

        $this->assertStringContainsString('- "c": one of ["поръчки", "a/b"]', StudentPrompt::system($spec));
    }

    public function test_parse_is_strict_like_the_workers_parser(): void
    {
        $spec = $this->spec();
        $ok = ['category' => 'returns', 'urgency' => 'high', 'needs_human' => true];

        $this->assertSame($ok, StudentPrompt::parse('{"category":"returns","urgency":"high","needs_human":true}', $spec));
        $this->assertSame($ok, StudentPrompt::parse("  {\"needs_human\":true,\"urgency\":\"high\",\"category\":\"returns\"}\n", $spec), 'key order is normalised to the spec');
        $this->assertSame($ok, StudentPrompt::parse("```json\n{\"category\":\"returns\",\"urgency\":\"high\",\"needs_human\":true}\n```", $spec), 'fences are stripped first');

        foreach ([
            'not json', '[]', '"returns"', '{}',
            '{"category":"returns","urgency":"high"}',                                          // missing field
            '{"category":"returns","urgency":"high","needs_human":true,"why":"x"}',             // extra field
            '{"category":"refunds","urgency":"high","needs_human":true}',                       // value outside the spec
            '{"category":"returns","urgency":"high","needs_human":"true"}',                     // bool as string
            '{"category":"returns","urgency":"high","needs_human":1}',                          // bool as int
            '{"category":["returns"],"urgency":"high","needs_human":true}',                     // not a string
            'Sure! {"category":"returns","urgency":"high","needs_human":true}',                 // prose around it
        ] as $bad) {
            $this->assertNull(StudentPrompt::parse($bad, $spec), $bad);
        }
    }
}
