<?php
// One-time mail setup for Baya By Baker. Saves the orders@ mailbox login next to order.php
// (blocked from the web by .htaccess), checks the login, then locks itself.
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
const KEY_HASH = '7a84bcc15f8c0997d8e7b340235f4c43491bd642ea5d42cedddf5d8cd7302d5d';
$dir = __DIR__; $cfgFile = $dir . '/.smtp-config.php'; $lock = $dir . '/.mail-setup-done';
function page($title, $body) {
  echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Mail setup</title><style>body{font:16px system-ui,sans-serif;background:#FBF3F4;color:#12382A;margin:0;padding:32px 16px}main{max-width:460px;margin:0 auto;background:#fff;border:1px solid #F0D9DF;border-radius:20px;padding:24px}h1{font-size:24px;margin:0 0 12px}input{width:100%;box-sizing:border-box;font-size:16px;padding:12px;border:1.5px solid #F0D9DF;border-radius:12px;margin:6px 0 14px}button{background:#0B3324;color:#fff;border:0;border-radius:999px;padding:12px 22px;font-weight:700;font-size:16px}.ok{color:#2c7a4b;font-weight:700}.bad{color:#a33a4a;font-weight:700}</style></head><body><main><h1>' . $title . '</h1>' . $body . '</main></body></html>';
  exit;
}
function login_test($user, $pass) {
  $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
  $fp = @stream_socket_client('ssl://smtp.hostinger.com:465', $en, $es, 10, STREAM_CLIENT_CONNECT, $ctx);
  if (!$fp) return 'Could not reach the mail server. Try again in a minute.';
  stream_set_timeout($fp, 10);
  $read = function () use ($fp) { $d = ''; while (($l = fgets($fp, 515)) !== false) { $d .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $d; };
  $cmd = function ($c, $code) use ($fp, $read) { if ($c !== null) fwrite($fp, $c . "\r\n"); return strpos($read(), (string)$code) === 0; };
  $ok = $cmd(null, 220) && $cmd('EHLO bayabybaker.com', 250) && $cmd('AUTH LOGIN', 334) && $cmd(base64_encode($user), 334) && $cmd(base64_encode($pass), 235);
  @fwrite($fp, "QUIT\r\n"); @fclose($fp);
  return $ok ? 'ok' : 'The mailbox rejected that password.';
}
if (is_file($lock)) page('Already set up', '<p class="ok">Order emails are set up and sending through orders@bayabybaker.com.</p><p>This page is locked. To change the password, ask your web developer.</p>');
// at most 5 tries per hour
$tries = sys_get_temp_dir() . '/bbb_setup_tries'; $t = array_filter(json_decode(@file_get_contents($tries) ?: '[]', true) ?: [], fn($x) => $x > time() - 3600);
$key = (string)($_POST['key'] ?? $_GET['key'] ?? '');
if (!hash_equals(KEY_HASH, hash('sha256', $key))) page('Link not valid', '<p class="bad">This setup link is missing its key or has expired.</p>');
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (count($t) >= 5) page('Too many tries', '<p class="bad">Please wait an hour and try again.</p>');
  $t[] = time(); @file_put_contents($tries, json_encode(array_values($t)));
  $pass = (string)($_POST['pass'] ?? '');
  if ($pass === '' || strlen($pass) > 200) { $msg = '<p class="bad">Enter the password.</p>'; }
  else {
    $res = login_test('orders@bayabybaker.com', $pass);
    if ($res === 'ok') {
      $cfg = "<?php return " . var_export(['host' => 'smtp.hostinger.com', 'port' => 465, 'user' => 'orders@bayabybaker.com', 'pass' => $pass], true) . ";\n";
      if (@file_put_contents($cfgFile, $cfg, LOCK_EX) !== false) { @chmod($cfgFile, 0600); @file_put_contents($lock, gmdate('c')); @unlink(sys_get_temp_dir() . '/bbb_smtp_status');
        page('All set ✓', '<p class="ok">Password checked and saved. Order emails will now be sent properly signed from orders@bayabybaker.com.</p><p>Place a test order on the website to confirm it lands in the inbox.</p>'); }
      $msg = '<p class="bad">Could not save the settings file on the server.</p>';
    } else { $msg = '<p class="bad">' . htmlspecialchars($res) . '</p>'; }
  }
}
page('Order email setup', $msg . '<p>Enter the password for <b>orders@bayabybaker.com</b>. It is checked with the mail server, saved privately on the hosting, and this page then locks itself.</p><form method="post" autocomplete="off"><input type="hidden" name="key" value="' . htmlspecialchars($key) . '"><label>Mailbox password<input type="password" name="pass" id="pass" required></label><button type="submit" id="save">Save and test</button></form>');
