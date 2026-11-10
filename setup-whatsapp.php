<?php
// One-time setup for WhatsApp order alerts (CallMeBot, free). Saves the shop's API key next to
// order.php (blocked from the web by .htaccess), sends a test message, then locks itself.
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
const KEY_HASH = '7a84bcc15f8c0997d8e7b340235f4c43491bd642ea5d42cedddf5d8cd7302d5d';
const SHOP_WA = '256755105402';
$cfgFile = __DIR__ . '/.whatsapp-alert.php'; $lock = __DIR__ . '/.wa-setup-done';
function page($title, $body) {
  echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>WhatsApp alerts</title><style>body{font:16px system-ui,sans-serif;background:#FBF3F4;color:#12382A;margin:0;padding:32px 16px}main{max-width:460px;margin:0 auto;background:#fff;border:1px solid #F0D9DF;border-radius:20px;padding:24px}h1{font-size:24px;margin:0 0 12px}input{width:100%;box-sizing:border-box;font-size:16px;padding:12px;border:1.5px solid #F0D9DF;border-radius:12px;margin:6px 0 14px}button{background:#0B3324;color:#fff;border:0;border-radius:999px;padding:12px 22px;font-weight:700;font-size:16px}.ok{color:#2c7a4b;font-weight:700}.bad{color:#a33a4a;font-weight:700}li{margin:6px 0}</style></head><body><main><h1>' . $title . '</h1>' . $body . '</main></body></html>';
  exit;
}
function wa_send($phone, $apikey, $text) {
  $url = 'https://api.callmebot.com/whatsapp.php?phone=' . rawurlencode('+' . $phone) . '&text=' . rawurlencode($text) . '&apikey=' . rawurlencode($apikey);
  $c = curl_init($url); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
  $body = (string)curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
  return $code === 200 && stripos($body, 'error') === false && stripos($body, 'invalid') === false;
}
if (is_file($lock)) page('Already set up', '<p class="ok">WhatsApp order alerts are on for +' . SHOP_WA . '.</p><p>This page is locked. To change the key, ask your web developer.</p>');
$tries = sys_get_temp_dir() . '/bbb_wa_tries'; $t = array_filter(json_decode(@file_get_contents($tries) ?: '[]', true) ?: [], fn($x) => $x > time() - 3600);
$key = (string)($_POST['key'] ?? $_GET['key'] ?? '');
if (!hash_equals(KEY_HASH, hash('sha256', $key))) page('Link not valid', '<p class="bad">This setup link is missing its key or has expired.</p>');
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (count($t) >= 5) page('Too many tries', '<p class="bad">Please wait an hour and try again.</p>');
  $t[] = time(); @file_put_contents($tries, json_encode(array_values($t)));
  $api = preg_replace('/\D/', '', (string)($_POST['apikey'] ?? ''));
  if ($api === '' || strlen($api) > 20) { $msg = '<p class="bad">Enter the API key CallMeBot sent you (numbers only).</p>'; }
  elseif (!wa_send(SHOP_WA, $api, '✅ Baya By Baker: WhatsApp order alerts are now ON. New website orders will arrive here.')) { $msg = '<p class="bad">CallMeBot did not accept that key. Check the number in its WhatsApp reply and try again.</p>'; }
  elseif (@file_put_contents($cfgFile, "<?php return " . var_export(['phone' => SHOP_WA, 'apikey' => $api], true) . ";\n", LOCK_EX) !== false) {
    @chmod($cfgFile, 0600); @file_put_contents($lock, gmdate('c'));
    page('All set ✓', '<p class="ok">A test message was just sent to WhatsApp +' . SHOP_WA . '.</p><p>From now on every website order also arrives there automatically.</p>');
  } else { $msg = '<p class="bad">Could not save the settings file on the server.</p>'; }
}
page('WhatsApp order alerts', $msg . '<ol><li>On the shop phone (+' . SHOP_WA . '), add the CallMeBot number shown on <a href="https://www.callmebot.com/blog/free-api-whatsapp-messages/" target="_blank" rel="noopener">callmebot.com</a> to contacts.</li><li>Send it this WhatsApp message: <b>I allow callmebot to send me messages</b></li><li>Paste the API key from its reply below.</li></ol><form method="post" autocomplete="off"><input type="hidden" name="key" value="' . htmlspecialchars($key) . '"><label>CallMeBot API key<input name="apikey" inputmode="numeric" required></label><button type="submit">Save and send a test</button></form>');
