<?php

namespace App\Support;

class TaskTemplate
{
    public static function merchantTickets(): array
    {
        return [
            'name' => 'merchant-ticket-triage',
            'description' => 'Triage incoming customer-support tickets of an online shop.',
            'languages' => ['bg', 'en'],
            'fields' => [
                ['name' => 'category', 'type' => 'enum', 'primary' => true,
                    'values' => ['orders', 'shipping', 'returns', 'payments', 'product_questions', 'complaints', 'other']],
                ['name' => 'urgency', 'type' => 'enum', 'values' => ['low', 'normal', 'high']],
                ['name' => 'needs_human', 'type' => 'bool'],
            ],
            'guidelines' => "category: the main topic of the ticket. If several topics appear, pick the one the customer wants solved first.\n"
                .'urgency: high when money is lost, a deadline is missed or the customer threatens to leave; low for plain questions. '
                .'needs_human: true when a person must decide (refunds, complaints, anything legal).',
        ];
    }

    public static function seedExamples(): array
    {
        return [
            self::item('Здравейте, поръчка 4821 още не е пристигнала, а куриерът каза, че ще е вчера.', 'shipping', 'normal', false),
            self::item('Can I change the delivery address of order 1177? I typed the wrong street.', 'orders', 'high', false),
            self::item('Получих счупена чаша. Искам парите си обратно, иначе ще подам жалба.', 'complaints', 'high', true),
            self::item('Does the blue jacket come in size XL and is it waterproof?', 'product_questions', 'low', false),
            self::item('Платих два пъти с карта за една и съща поръчка, моля върнете единия път.', 'payments', 'high', true),
            self::item('How do I send back the shoes? They are too small.', 'returns', 'normal', false),
        ];
    }

    public static function holdoutExamples(): array
    {
        return [
            self::item('Искам да върна блузата, но не намирам етикета. Може ли без него?', 'returns', 'normal', false),
            self::item('My package says delivered but nothing is at my door.', 'shipping', 'high', true),
            self::item('Има ли гаранция на тази прахосмукачка и колко години?', 'product_questions', 'low', false),
            self::item('The discount code was not applied at checkout and I paid the full price.', 'payments', 'normal', true),
            self::item('Отказах поръчката преди час, още ли може да се спре?', 'orders', 'high', false),
            self::item('Thanks for the quick delivery, everything was perfect.', 'other', 'low', false),
        ];
    }

    public static function toJsonl(array $items): string
    {
        return collect($items)->map(fn ($i) => json_encode($i, JSON_UNESCAPED_UNICODE))->implode("\n");
    }

    private static function item(string $input, string $category, string $urgency, bool $needsHuman): array
    {
        return ['input' => $input, 'output' => ['category' => $category, 'urgency' => $urgency, 'needs_human' => $needsHuman]];
    }
}
