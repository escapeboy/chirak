<?php

namespace Tests\Unit;

use App\Support\TicketParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TicketParserTest extends TestCase
{
    private function lines(int $n, string $prefix = 'ticket'): array
    {
        return array_map(fn ($i) => "$prefix $i", range(1, $n));
    }

    public function test_csv_uses_the_text_column_or_the_first(): void
    {
        $withHeader = "id,Text\n".implode("\n", array_map(fn ($t) => "7,\"$t, with comma\"", $this->lines(100)));
        $parsed = TicketParser::parse($withHeader);
        $this->assertCount(100, $parsed);
        $this->assertSame('ticket 1, with comma', $parsed[0]);

        $first = TicketParser::parse(implode("\n", $this->lines(100))."\n\n");
        $this->assertCount(100, $first);
        $this->assertSame('ticket 1', $first[0]);
    }

    public function test_jsonl_takes_strings_or_objects_with_text(): void
    {
        $rows = array_map(fn ($t, $i) => $i % 2 ? json_encode($t, JSON_UNESCAPED_UNICODE) : json_encode(['text' => $t, 'x' => 1], JSON_UNESCAPED_UNICODE), $this->lines(100, 'билет'), range(0, 99));

        $parsed = TicketParser::parse(implode("\n", $rows));

        $this->assertCount(100, $parsed);
        $this->assertSame('билет 2', $parsed[1]);
    }

    public function test_jsonl_with_a_bad_row_is_refused(): void
    {
        $this->expectExceptionMessage('Line 2');

        TicketParser::parse("{\"text\":\"a\"}\n{\"body\":\"b\"}\n");
    }

    public function test_the_count_must_be_between_100_and_1000(): void
    {
        foreach ([99, 1001] as $n) {
            try {
                TicketParser::parse(implode("\n", $this->lines($n)));
                $this->fail("$n tickets accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString("found $n", $e->getMessage());
            }
        }
        $this->assertCount(1000, TicketParser::parse(implode("\n", $this->lines(1000))));
    }

    public function test_long_tickets_are_cut(): void
    {
        $rows = [...$this->lines(99), str_repeat('я', 5000)];

        $this->assertSame(TicketParser::MAX_CHARS, mb_strlen(TicketParser::parse(implode("\n", $rows))[99]));
    }
}
