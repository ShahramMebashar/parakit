<?php
declare(strict_types=1);

return [
    'errors' => [
        'insufficient_funds'    => 'پارەی پێویست نییە.',
        'invalid_phone'         => 'ژمارەی مۆبایل دروست نییە.',
        'user_cancelled'        => 'پارەدانەکە هەڵوەشێنرایەوە.',
        'expired'               => 'کاتی پارەدانەکە بەسەرچوو.',
        'invalid_amount'        => 'بڕەکە دروست نییە.',
        'invalid_credentials'   => 'زانیاری بازرگانی دروست نییە.',
        'gateway_unavailable'   => 'دەروازەی پارەدان لە بەردەست نییە. تکایە دووبارە هەوڵ بدە.',
        'duplicate_transaction' => 'ئەم مامەڵەیە پێشتر ناردراوە.',
        'network_error'         => 'هەڵەی تۆڕ ڕوویدا. تکایە دووبارە هەوڵ بدە.',
        'timeout'               => 'کاتی پارەدانەکە تەواوبوو. دووبارە هەوڵ بدە.',
        'signature_invalid'     => 'سیگناتچەرە دروست نییە.',
        'unknown'               => 'هەڵەیەکی نادیار ڕوویدا.',
    ],
    'statuses' => [
        'pending'            => 'چاوەڕوان',
        'processing'         => 'لە پرۆسەدا',
        'paid'               => 'پارەدراو',
        'failed'             => 'سەرنەکەوت',
        'cancelled'          => 'هەڵوەشێنراوەتەوە',
        'refunded'           => 'گەڕێنراوەتەوە',
        'partially_refunded' => 'بەشێکی گەڕێنراوەتەوە',
        'expired'            => 'بەسەرچوو',
        'disputed'           => 'ناکۆکی لەسەرە',
    ],
    'ui' => [
        'pay_with_fib'      => 'پارە بدە بە FIB',
        'pay_with_zaincash' => 'پارە بدە بە ZainCash',
        'redirecting'       => 'گواستنەوە بۆ دەروازەی پارەدان…',
        'payment_pending'   => 'پارەدانەکەت لە پرۆسەدایە.',
    ],
];
