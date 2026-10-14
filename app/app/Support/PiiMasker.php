<?php

namespace App\Support;

/**
 * Masks personal data in a ticket before the text is stored or sent anywhere: emails, IBANs, card numbers,
 * phone numbers and any other run of 6+ digits (order numbers). It errs on the side of masking too much.
 */
class PiiMasker
{
    public static function mask(string $text): string
    {
        $text = preg_replace('/[\w.%+\-]+@[\w\-]+(?:\.[\w\-]+)+/u', '[email]', $text);

        $text = preg_replace_callback('/\b[A-Za-z]{2}\d{2}(?: ?[A-Za-z0-9]{1,4}){3,8}\b/u', function ($m) {
            $length = strlen(preg_replace('/ /', '', $m[0]));

            return $length >= 15 && $length <= 34 && preg_match('/\d/', substr($m[0], 4)) ? '[iban]' : $m[0];
        }, $text);

        $text = preg_replace_callback('/(?<![\d\w])(?:\d[ -]?){12,18}\d(?![\d\w])/u', function ($m) {
            return strlen(preg_replace('/\D/', '', $m[0])) >= 13 ? '[card]' : $m[0];
        }, $text);

        $text = preg_replace_callback('/(?<![\w])\(?\+?\d[\d ().\-]{6,}\d(?![\w])/u', function ($m) {
            $digits = strlen(preg_replace('/\D/', '', $m[0]));
            $date = preg_match('/^\(?(?:\d{4}-\d{1,2}-\d{1,2}|\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4})/', $m[0]);

            return $digits >= 9 && $digits <= 15 && ! $date ? '[phone]' : $m[0];
        }, $text);

        return preg_replace('/\d{6,}/', '[number]', $text);
    }
}
