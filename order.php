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

// Signed sending: log in to the real mailbox over SMTP so mail passes SPF/DKIM and lands in the inbox.
// The login lives OUTSIDE public_html in ../smtp-config.php (never in this repo):
//   <?php return ['host'=>'smtp.hostinger.com','port'=>465,'user'=>'orders@bayabybaker.com','pass'=>'...'];
function smtp_config() {
    $f = dirname(__DIR__) . '/smtp-config.php';
    if (!is_file($f)) return null;
    $c = include $f;
    return (is_array($c) && !empty($c['user']) && !empty($c['pass'])) ? $c : null;
}
function smtp_send($cfg, $to, $subjectEnc, $body, $replyTo) {
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client(($cfg['transport'] ?? 'ssl') . '://' . ($cfg['host'] ?? 'smtp.hostinger.com') . ':' . ($cfg['port'] ?? 465), $en, $es, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return false;
    stream_set_timeout($fp, 15);
    $read = function () use ($fp) { $d = ''; while (($l = fgets($fp, 515)) !== false) { $d .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $d; };
    $cmd = function ($c, $code) use ($fp, $read) { if ($c !== null) fwrite($fp, $c . "\r\n"); return strpos($read(), (string)$code) === 0; };
    $from = $cfg['user'];
    $ok = $cmd(null, 220) && $cmd('EHLO bayabybaker.com', 250) && $cmd('AUTH LOGIN', 334)
        && $cmd(base64_encode($from), 334) && $cmd(base64_encode($cfg['pass']), 235)
        && $cmd('MAIL FROM:<' . $from . '>', 250) && $cmd('RCPT TO:<' . $to . '>', 250) && $cmd('DATA', 354);
    if ($ok) {
        $h = [
            'Date: ' . date('r'),
            'From: =?UTF-8?B?' . base64_encode('Baya By Baker Website') . '?= <' . $from . '>',
            'To: <' . $to . '>',
            'Reply-To: ' . $replyTo,
            'Subject: ' . $subjectEnc,
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@bayabybaker.com>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        fwrite($fp, implode("\r\n", $h) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n")) . "\r\n.\r\n");
        $ok = strpos($read(), '250') === 0;
    }
    @fwrite($fp, "QUIT\r\n"); @fclose($fp);
    return $ok;
}
function send_shop_mail($subjectEnc, $body, $replyTo) {
    $cfg = smtp_config();
    if ($cfg && smtp_send($cfg, SHOP_EMAIL, $subjectEnc, $body, $replyTo)) return true;
    $headers = implode("\r\n", ['From: Baya By Baker Website <' . SHOP_EMAIL . '>', 'Reply-To: ' . $replyTo, 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit']);
    return @mail(SHOP_EMAIL, $subjectEnc, $body, $headers, '-f' . SHOP_EMAIL);
}

// Status check for setup (reveals nothing secret)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['status'])) {
    reply(true, 200, smtp_config() ? 'signed sending ready' : 'signed sending not set up yet');
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
function wa_number($p) { $d = preg_replace('/\D/', '', $p); if (strlen($d) === 10 && $d[0] === '0') $d = '256' . substr($d, 1); return $d; }
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
        $phone ? 'Reply on WhatsApp: https://wa.me/' . wa_number($phone) : null,
        'Sent from www.bayabybaker.com',
    ], fn($l) => $l !== null));
    $subject = '=?UTF-8?B?' . base64_encode('Website message: ' . $topic . ' (' . $name . ')') . '?=';
    $ok = send_shop_mail($subject, $body, $email ?: SHOP_EMAIL);
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
    'Gift: ' . (oneline(field($d, 'Gift', 400)) ?: 'No'),
    '',
    'Items:',
    field($d, 'Items', 6000),
    '',
    'Subtotal: ' . oneline(field($d, 'Subtotal', 60)),
    'Discount: ' . oneline(field($d, 'Discount', 80)),
    'Total: ' . oneline(field($d, 'Total', 80)),
    '',
    'Reply to the client on WhatsApp: https://wa.me/' . wa_number($phone),
    'Sent from www.bayabybaker.com',
];
$body = implode("\n", $lines);
$subject = '=?UTF-8?B?' . base64_encode('New order ' . $ref . ' from ' . $name) . '?=';
$ok = send_shop_mail($subject, $body, SHOP_EMAIL);
reply($ok, $ok ? 200 : 500, $ok ? 'sent' : 'mail failed');
