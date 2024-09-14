<?php
function getVoucherPrefix($planName) {
    // Array prefix voucher berdasarkan nama plan
    $VOUCHER_PREFIX = [
        '1HARI5MB' => 'D',
        '7HARI5MB' => 'W',
        '30HARI5MB' => 'M',
        '60HARI5MB' => 'M'
    ];

    // Jika planName ditemukan, return prefix-nya; jika tidak, return default prefix
    return isset($VOUCHER_PREFIX[$planName]) ? $VOUCHER_PREFIX[$planName] : 'DEFAULT_PREFIX';
}
?>
