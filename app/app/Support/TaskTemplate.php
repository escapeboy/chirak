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
            // v2 (14 Oct 2026): written after the real-text checks, where most errors were shipping vs
            // orders vs complaints and needs_human; see docs/demo-run-log.md.
            'guidelines' => "category: the concrete problem the customer wants solved. Tone does not decide the category: an angry message about a late parcel is shipping.\n"
                ."- orders: placing, changing or cancelling an order and its items before it ships; wrong contents or quantity in the order confirmation.\n"
                ."- shipping: everything after dispatch and about delivery: where is my parcel, delays, tracking, couriers, delivery address or date, parcels marked delivered but not received, delivery options and their cost.\n"
                ."- returns: sending an item back, exchanges, a wrong, damaged or faulty item received, and the refund for a returned item.\n"
                ."- payments: charges, double or wrong charges, failed payments, payment methods, promo codes, prices, invoices, gift cards, refunds not tied to a return.\n"
                ."- product_questions: questions about a product or its availability before buying: specs, sizes, compatibility, stock.\n"
                ."- complaints: the complaint itself is the point: rude staff or couriers, support that does not answer, poor service, threats to report the shop or take legal steps, with no concrete order, delivery, return or payment problem to fix.\n"
                ."- other: account access and settings, data and privacy requests, website problems, partnerships, praise, anything else.\n"
                ."urgency: high when money is lost or wrongly charged, a deadline the customer names is at risk, the account is compromised, or the customer threatens to leave, report or sue; low for questions before buying, praise and general information; normal otherwise.\n"
                ."needs_human: true when a person must decide or act beyond a standard answer: refunds and compensation, wrong or damaged items, wrong charges, complaints, legal or privacy requests, account security. False for status questions, product questions and how-to questions.",
        ];
    }

    public static function seedExamples(): array
    {
        return [
            self::item('Здравейте, поръчка 4821 още не е пристигнала, а куриерът каза, че ще е вчера.', 'shipping', 'normal', false),
            self::item('Can I change the delivery address of order 1177? I typed the wrong street.', 'shipping', 'high', false),
            self::item('Получих счупена чаша. Искам парите си обратно, иначе ще подам жалба.', 'returns', 'high', true),
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
