<?php
// ============================================================
// India Market Panel — Database Config
// Shared DB, separate table prefix: india_trades
// Currency: Indian Rupees (₹)
// ============================================================
require_once dirname(__DIR__, 2) . '/config/db.php'; // reuse main DB connection

// India-specific overrides
define('INDIA_APP_NAME',    'DisciplineOS — India');
define('INDIA_CURRENCY',    '₹');
define('INDIA_CURRENCY_CODE','INR');
define('INDIA_DEFAULT_USER', DEFAULT_USER_ID);

// Format ₹ with Indian number system (e.g. ₹1,23,456.78)
function formatINR($value): string {
    $val = floatval($value);
    $neg = $val < 0;
    $abs = abs($val);
    // Indian number formatting
    $formatted = number_format($abs, 2);
    return ($neg ? '-' : '') . '₹' . $formatted;
}

function formatINR_PL($value): string {
    $val = floatval($value);
    $prefix = $val >= 0 ? '+₹' : '-₹';
    return $prefix . number_format(abs($val), 2);
}
