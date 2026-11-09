<?php
// Baya By Baker: emails confirmed website orders to the shop.
// The recipient is fixed here, so this script cannot be used to email anyone else.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

const SHOP_EMAIL = 'orders@bayabybaker.com';
const ALLOWED_HOSTS = ['bayabybaker.com', 'www.bayabybaker.com'];

function reply($ok, $code = 200, $msg = '') {
    http_response_code($code);
    echo json_encode(['success' => $ok, 'message' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(false, 405, 'POST only');

// Only accept orders sent from the shop's own pages.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $host = strtolower(parse_url($origin, PHP_URL_HOST) ?? '');
    if (!in_array($host, ALLOWED_HOSTS, true)) reply(false, 403, 'Not allowed');
}

$raw = file_get_contents('php://input', false, null, 0, 20000);
$d = json_decode($raw, true);
if (!is_array($d)) reply(false, 400, 'Bad request');

// Simple flood protection: at most 5 orders per visitor IP every 10 minutes.
$ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
$f = sys_get_temp_dir() . '/bbb_' . md5($ip);
$hits = array_filter(json_decode(@file_get_contents($f) ?: '[]', true) ?: [], fn($t) => $t > time() - 600);
if (count($hits) >= 5) reply(false, 429, 'Too many orders, please use WhatsApp');
$hits[] = time();
@file_put_contents($f, json_encode(array_values($hits)));

function field($d, $k, $max = 2000) {
    $v = isset($d[$k]) ? (string)$d[$k] : '';
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    return trim(mb_substr($v, 0, $max));
}
function oneline($s) { return trim(preg_replace('/[\r\n\t]+/', ' ', $s)); }

// Contact form messages
if (field($d, 'Type', 20) === 'contact') {
    if (field($d, 'Company', 100) !== '') reply(true, 200, 'sent'); // spam trap
    $name  = oneline(field($d, 'Name', 120));
    $phone = oneline(field($d, 'Phone', 40));
    $email = oneline(field($d, 'Email', 160));
    $topic = oneline(field($d, 'Topic', 80));
    $msg   = field($d, 'Message', 3000);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
    if ($name === '' || $msg === '' || ($phone === '' && $email === '')) reply(false, 422, 'Missing details');
    $body = implode("\n", array_filter([
        'New message from the website contact form', '',
        'Name: ' . $name,
        'Phone / WhatsApp: ' . ($phone ?: '-'),
        'Email: ' . ($email ?: '-'),
        'About: ' . ($topic ?: '-'), '',
        $msg, '',
        $phone ? 'Reply on WhatsApp: https://wa.me/' . preg_replace('/\D/', '', $phone) : null,
        'Sent from www.bayabybaker.com',
    ], fn($l) => $l !== null));
    $subject = '=?UTF-8?B?' . base64_encode('Website message: ' . $topic . ' (' . $name . ')') . '?=';
    $headers = implode("\r\n", [
        'From: Baya By Baker Website <' . SHOP_EMAIL . '>',
        'Reply-To: ' . ($email ?: SHOP_EMAIL),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ]);
    $ok = @mail(SHOP_EMAIL, $subject, $body, $headers, '-f' . SHOP_EMAIL);
    reply($ok, $ok ? 200 : 500, $ok ? 'sent' : 'mail failed');
}

$ref   = oneline(field($d, 'Reference', 40));
$name  = oneline(field($d, 'Name', 120));
$phone = oneline(field($d, 'Phone', 40));
if ($ref === '' || $name === '' || $phone === '') reply(false, 422, 'Missing details');

$lines = [
    'New website order ' . $ref,
    '',
    'Name: ' . $name,
    'Phone / WhatsApp: ' . $phone,
    'Delivery: ' . oneline(field($d, 'Delivery', 200)),
    'Notes: ' . (field($d, 'Notes', 1000) ?: '-'),
    '',
    'Items:',
    field($d, 'Items', 6000),
    '',
    'Subtotal: ' . oneline(field($d, 'Subtotal', 60)),
    'Discount: ' . oneline(field($d, 'Discount', 80)),
    'Total: ' . oneline(field($d, 'Total', 80)),
    '',
    'Reply to the client on WhatsApp: https://wa.me/' . preg_replace('/\D/', '', $phone),
    'Sent from www.bayabybaker.com',
];
$body = implode("\n", $lines);
$subject = '=?UTF-8?B?' . base64_encode('New order ' . $ref . ' from ' . $name) . '?=';
$headers = implode("\r\n", [
    'From: Baya By Baker Website <' . SHOP_EMAIL . '>',
    'Reply-To: ' . SHOP_EMAIL,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
]);

$ok = @mail(SHOP_EMAIL, $subject, $body, $headers, '-f' . SHOP_EMAIL);
reply($ok, $ok ? 200 : 500, $ok ? 'sent' : 'mail failed');
