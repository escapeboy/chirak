<?php

namespace Tests\Unit;

use App\Support\PiiMasker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PiiMaskerTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'email' => ['Пишете ми на ivan.petrov+shop@abv.bg моля', 'Пишете ми на [email] моля'],
            'email upper' => ['Mail: Maria_K@Example.CO.UK.', 'Mail: [email].'],
            'iban spaced' => ['IBAN BG80 BNBG 9661 1020 3456 78 за връщане', 'IBAN [iban] за връщане'],
            'iban compact' => ['върнете на DE89370400440532013000 веднага', 'върнете на [iban] веднага'],
            'card spaced' => ['карта 4111 1111 1111 1111 е таксувана два пъти', 'карта [card] е таксувана два пъти'],
            'card dashed' => ['card 5500-0000-0000-0004 charged twice', 'card [card] charged twice'],
            'phone intl' => ['Обадете се на +359 888 123 456 утре', 'Обадете се на [phone] утре'],
            'phone local' => ['тел 0888 123 456.', 'тел [phone].'],
            'phone compact' => ['call 0888123456 now', 'call [phone] now'],
            'phone parens' => ['call (555) 123-4567 now', 'call [phone] now'],
            'order number' => ['поръчка 4821907 не е пристигнала', 'поръчка [number] не е пристигнала'],
            'short numbers stay' => ['2 броя от поръчка 4412, размер 42', '2 броя от поръчка 4412, размер 42'],
            'dates stay' => ['платих на 14.10.2026 и на 2026-10-14 10:30', 'платих на 14.10.2026 и на 2026-10-14 10:30'],
            'several' => ['a@b.io, 0888 123 456, 99887766', '[email], [phone], [number]'],
            'plain text' => ['Where is my parcel? It was sent last week.', 'Where is my parcel? It was sent last week.'],
        ];
    }

    #[DataProvider('cases')]
    public function test_it_masks(string $input, string $expected): void
    {
        $this->assertSame($expected, PiiMasker::mask($input));
    }

    public function test_no_original_digit_run_survives(): void
    {
        $out = PiiMasker::mask('card 4111111111111111, iban GB29NWBK60161331926819, tel +44 20 7946 0958, order 12345678');

        $this->assertDoesNotMatchRegularExpression('/\d{6}/', $out);
        $this->assertSame('card [card], iban [iban], tel [phone], order [number]', $out);
    }
}
