<?php
declare(strict_types=1);

return [
    'errors' => [
        'insufficient_funds'    => 'الرصيد غير كافٍ.',
        'invalid_phone'         => 'رقم الهاتف غير صالح.',
        'user_cancelled'        => 'تم إلغاء الدفعة.',
        'expired'               => 'انتهت صلاحية الدفعة.',
        'invalid_amount'        => 'المبلغ غير صالح.',
        'invalid_credentials'   => 'بيانات بوابة الدفع غير صحيحة.',
        'gateway_unavailable'   => 'بوابة الدفع غير متاحة. حاول مرة أخرى.',
        'duplicate_transaction' => 'تم إرسال هذه المعاملة مسبقًا.',
        'network_error'         => 'حدث خطأ في الشبكة. حاول مرة أخرى.',
        'timeout'               => 'انتهت مهلة الدفعة. حاول مرة أخرى.',
        'signature_invalid'     => 'التوقيع غير صالح.',
        'unknown'               => 'حدث خطأ غير معروف.',
    ],
    'statuses' => [
        'pending'            => 'قيد الانتظار',
        'processing'         => 'قيد المعالجة',
        'paid'               => 'مدفوع',
        'failed'             => 'فشل',
        'cancelled'          => 'ملغى',
        'refunded'           => 'مرتجع',
        'partially_refunded' => 'مرتجع جزئيًا',
        'expired'            => 'منتهي الصلاحية',
        'disputed'           => 'متنازع عليه',
    ],
    'ui' => [
        'pay_with_fib'      => 'ادفع عبر FIB',
        'pay_with_zaincash' => 'ادفع عبر ZainCash',
        'redirecting'       => 'يتم تحويلك إلى بوابة الدفع…',
        'payment_pending'   => 'تتم معالجة دفعتك.',
    ],
];
