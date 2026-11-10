<?php
// One-time setup: connects the website to the "Item names" Google Sheet through a private Apps Script.
// Shows the script (with a fresh secret), tests the web app URL, saves it next to order.php (blocked by .htaccess), then locks.
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
const KEY_HASH = '7a84bcc15f8c0997d8e7b340235f4c43491bd642ea5d42cedddf5d8cd7302d5d';
$cfgFile = __DIR__ . '/.sheets-hook.php'; $pending = __DIR__ . '/.sheets-pending'; $lock = __DIR__ . '/.sheets-setup-done';
function page($title, $body) {
  echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Google Sheet setup</title><style>body{font:16px system-ui,sans-serif;background:#FBF3F4;color:#12382A;margin:0;padding:32px 16px}main{max-width:640px;margin:0 auto;background:#fff;border:1px solid #F0D9DF;border-radius:20px;padding:24px}h1{font-size:24px;margin:0 0 12px}input{width:100%;box-sizing:border-box;font-size:16px;padding:12px;border:1.5px solid #F0D9DF;border-radius:12px;margin:6px 0 14px}textarea{width:100%;box-sizing:border-box;height:180px;font:12px ui-monospace,monospace;border:1.5px solid #F0D9DF;border-radius:12px;padding:10px}button{background:#0B3324;color:#fff;border:0;border-radius:999px;padding:12px 22px;font-weight:700;font-size:16px;cursor:pointer}.ok{color:#2c7a4b;font-weight:700}.bad{color:#a33a4a;font-weight:700}li{margin:8px 0}code{background:#FBF3F4;padding:1px 6px;border-radius:6px}</style></head><body><main><h1>' . $title . '</h1>' . $body . '</main></body></html>';
  exit;
}
function hook_get($url, $token) {
  $c = curl_init($url . '?t=' . rawurlencode($token)); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30]);
  $b = curl_exec($c); curl_close($c); $j = json_decode((string)$b, true);
  return is_array($j) ? $j : null;
}
if (is_file($lock)) page('Already connected', '<p class="ok">The website is connected to the Google Sheet.</p><p>This page is locked. To change it, ask your web developer.</p>');
$key = (string)($_POST['key'] ?? $_GET['key'] ?? '');
if (!hash_equals(KEY_HASH, hash('sha256', $key))) page('Link not valid', '<p class="bad">This setup link is missing its key or has expired.</p>');
$token = is_file($pending) ? trim((string)@file_get_contents($pending)) : '';
if (!preg_match('/^[a-f0-9]{40}$/', $token)) { $token = bin2hex(random_bytes(20)); @file_put_contents($pending, $token, LOCK_EX); @chmod($pending, 0600); }
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $url = trim((string)($_POST['url'] ?? ''));
  if (!preg_match('#^https://script\.google\.com/macros/s/[A-Za-z0-9_-]{20,}/exec$#', $url)) { $msg = '<p class="bad">That does not look like a Web app URL. It should start with https://script.google.com/macros/s/ and end with /exec</p>'; }
  else {
    $j = hook_get($url, $token);
    if (!$j || empty($j['ok'])) { $msg = '<p class="bad">The script did not answer correctly. Check that you pasted the whole script, deployed it as a <b>Web app</b> with access <b>Anyone</b>, and copied the URL ending in /exec.</p>'; }
    elseif (@file_put_contents($cfgFile, "<?php return " . var_export(['url' => $url, 'token' => $token], true) . ";\n", LOCK_EX) !== false) {
      @chmod($cfgFile, 0600); @file_put_contents($lock, gmdate('c')); @unlink($pending);
      foreach (glob(sys_get_temp_dir() . '/bbb_stock_*.json') ?: [] as $f) @unlink($f);
      $rows = ''; foreach (($j['rows'] ?? []) as $r) $rows .= '<li>' . htmlspecialchars($r['name']) . ': <b>' . htmlspecialchars((string)$r['stock']) . '</b> in stock</li>';
      page('Connected ✓', '<p class="ok">The website now reads stock from the sheet, takes sold pieces off automatically and logs every order and signup.</p><p>Your sheet now has <b>Orders</b> and <b>Subscribers</b> tabs and two new columns, <b>Sold online</b> and <b>In stock now</b>.</p><ul>' . $rows . '</ul>');
    } else { $msg = '<p class="bad">Could not save the settings file on the server.</p>'; }
  }
}
$script = <<<'SCRIPT_END'
// Baya By Baker – website connector (free). Lives inside the "Item names" Google Sheet.
// It logs website orders and email signups, and counts pieces sold so stock goes down by itself.
const TOKEN = '__TOKEN__';
const STATUSES = ['New', 'Paid', 'Delivered', 'Cancelled'];

function out_(o) { return ContentService.createTextOutput(JSON.stringify(o)).setMimeType(ContentService.MimeType.JSON); }
function safe_(v) { v = String(v == null ? '' : v); return /^[=+\-@]/.test(v) ? "'" + v : v; }
function letter_(c) { let s = ''; c++; while (c > 0) { const m = (c - 1) % 26; s = String.fromCharCode(65 + m) + s; c = Math.floor((c - 1) / 26); } return s; }
function stockSheet_() { return SpreadsheetApp.getActive().getSheets()[0]; }

function header_(sh) {
  const v = sh.getDataRange().getValues();
  for (let r = 0; r < v.length; r++) for (let c = 0; c < v[r].length; c++) {
    const t = String(v[r][c]).trim().toLowerCase();
    if (t === 'name' || t === 'item' || t === 'item name') {
      const row = v[r].map(x => String(x).trim().toLowerCase());
      const find = f => row.findIndex(f);
      return { r: r, name: c, values: v, row: row,
        price: find(t => t.indexOf('price') >= 0),
        stock: find(t => t.indexOf('stock') >= 0 && t.indexOf('now') < 0),
        sold: find(t => t.indexOf('sold') === 0),
        left: find(t => t.indexOf('in stock now') >= 0) };
    }
  }
  throw new Error('Could not find the "Name" heading in the first sheet.');
}

// Adds the "Sold online" and "In stock now" columns, the hijab rows and the Orders/Subscribers tabs (only once).
function setup() {
  const ss = SpreadsheetApp.getActive(), sh = stockSheet_();
  let h = header_(sh);
  const lastCol = h.row.reduce((a, t, i) => t ? i : a, 0);
  if (h.sold < 0) { h.sold = lastCol + 1; sh.getRange(h.r + 1, h.sold + 1).setValue('Sold online').setFontWeight('bold'); }
  if (h.left < 0) {
    h.left = Math.max(h.sold, lastCol) + 1;
    const r0 = h.r + 2, N = letter_(h.name), S = letter_(h.stock), D = letter_(h.sold);
    sh.getRange(h.r + 1, h.left + 1).setFormula('={"In stock now";ARRAYFORMULA(IF(' + N + r0 + ':' + N + '="",,IF(' + S + r0 + ':' + S + '-' + D + r0 + ':' + D + '<0,0,' + S + r0 + ':' + S + '-' + D + r0 + ':' + D + ')))}').setFontWeight('bold');
    sh.getRange(h.r + 2, h.left + 1, Math.max(1, sh.getMaxRows() - h.r - 1), 1).setBackground('#EAF6EE');
    sh.getRange(h.r + 2, h.sold + 1, Math.max(1, sh.getMaxRows() - h.r - 1), 1).setBackground('#FDF0F3');
  }
  h = header_(sh);
  const names = h.values.slice(h.r + 1).map(x => String(x[h.name]));
  const addRow = (label, price, stock) => {
    let r = h.r + 1; while (r < h.values.length && String(h.values[r][h.name]).trim()) r++;
    sh.getRange(r + 1, h.name + 1).setValue(label);
    if (h.price >= 0) sh.getRange(r + 1, h.price + 1).setValue(price);
    if (h.stock >= 0) sh.getRange(r + 1, h.stock + 1).setValue(stock);
    h = header_(sh);
  };
  if (!names.some(n => /chiffon|shiffon/i.test(n))) addRow('Chiffon hijab', 20, 10);
  if (!names.some(n => /jersey/i.test(n))) addRow('Jersey hijab', 30, 10);
  if (!ss.getSheetByName('Orders')) {
    const o = ss.insertSheet('Orders');
    o.appendRow(['Date', 'Reference', 'Status', 'Name', 'Phone', 'Delivery', 'Items', 'Subtotal', 'Discounts', 'Total', 'Gift', 'Notes', 'WhatsApp', 'Lines (do not edit)']);
    o.getRange(1, 1, 1, 14).setFontWeight('bold').setBackground('#0B3324').setFontColor('#FFFFFF');
    o.setFrozenRows(1); o.hideColumns(14); o.setColumnWidth(7, 320); o.getRange('G:G').setWrap(true);
    o.getRange('C2:C').setDataValidation(SpreadsheetApp.newDataValidation().requireValueInList(STATUSES, true).build());
  }
  if (!ss.getSheetByName('Subscribers')) {
    const s = ss.insertSheet('Subscribers');
    s.appendRow(['Date', 'Email', 'Welcome code', 'Used first order?']);
    s.getRange(1, 1, 1, 4).setFontWeight('bold').setBackground('#0B3324').setFontColor('#FFFFFF');
    s.setFrozenRows(1); s.setColumnWidth(2, 260);
  }
  PropertiesService.getScriptProperties().setProperty('setup', '2');
}
function ensure_() { if (PropertiesService.getScriptProperties().getProperty('setup') !== '2') setup(); }

function stockRows_() {
  const h = header_(stockSheet_()), col = h.left >= 0 ? h.left : h.stock;
  return h.values.slice(h.r + 1).filter(x => String(x[h.name]).trim()).map(x => ({
    name: String(x[h.name]).trim(),
    price: h.price >= 0 && x[h.price] !== '' ? Number(x[h.price]) : null,
    stock: col >= 0 && x[col] !== '' ? Math.max(0, Math.floor(Number(x[col]) || 0)) : null }));
}

// Adds (or with sign -1 removes) website sales to the "Sold online" column.
function applySold_(lines, sign) {
  const sh = stockSheet_(), h = header_(sh);
  (lines || []).forEach(l => {
    if (l.pre || !l.key) return;
    const re = new RegExp(l.key, 'i');
    for (let r = h.r + 1; r < h.values.length; r++) {
      if (re.test(String(h.values[r][h.name]))) {
        const cell = sh.getRange(r + 1, h.sold + 1), cur = Number(cell.getValue()) || 0;
        cell.setValue(Math.max(0, cur + sign * (Number(l.qty) || 0)));
        break;
      }
    }
  });
}

function doGet(e) {
  if ((e.parameter.t || '') !== TOKEN) return out_({ ok: false, message: 'denied' });
  ensure_();
  return out_({ ok: true, updated: new Date().toISOString(), rows: stockRows_() });
}

function doPost(e) {
  let d; try { d = JSON.parse(e.postData.contents); } catch (err) { return out_({ ok: false }); }
  if (d.t !== TOKEN) return out_({ ok: false, message: 'denied' });
  const lock = LockService.getScriptLock(); lock.waitLock(20000);
  try {
    ensure_();
    const ss = SpreadsheetApp.getActive();
    if (d.type === 'order') {
      const lines = (d.lines || []).slice(0, 40);
      ss.getSheetByName('Orders').appendRow([new Date(), safe_(d.ref), 'New', safe_(d.name), "'" + String(d.phone || ''), safe_(d.delivery),
        safe_(d.items), safe_(d.subtotal), safe_(d.discount), safe_(d.total), safe_(d.gift), safe_(d.notes), safe_(d.wa), JSON.stringify(lines)]);
      applySold_(lines, 1);
    } else if (d.type === 'signup') {
      const s = ss.getSheetByName('Subscribers'), em = String(d.email || '').toLowerCase();
      const have = s.getRange(1, 2, Math.max(1, s.getLastRow()), 1).getValues().some(x => String(x[0]).toLowerCase() === em);
      if (em && !have) s.appendRow([new Date(), safe_(em), safe_(d.code), '']);
    }
    return out_({ ok: true });
  } finally { lock.releaseLock(); }
}

// Set an order's Status to "Cancelled" and its pieces go back into stock (and come off again if you change it back).
function onEdit(e) {
  const sh = e.range.getSheet();
  if (sh.getName() !== 'Orders' || e.range.getColumn() !== 3 || e.range.getRow() < 2 || e.range.getNumRows() > 1) return;
  const was = e.oldValue || '', now = e.value || '';
  if (was === now || (was !== 'Cancelled' && now !== 'Cancelled')) return;
  let lines = []; try { lines = JSON.parse(sh.getRange(e.range.getRow(), 14).getValue() || '[]'); } catch (err) {}
  applySold_(lines, now === 'Cancelled' ? -1 : 1);
}

SCRIPT_END;
$script = str_replace('__TOKEN__', $token, $script);
page('Connect the Google Sheet', $msg . '<ol>
<li>Open the <b>Item names</b> Google Sheet. Click <b>Extensions → Apps Script</b>.</li>
<li>Delete what is in the editor, then paste the script below (tap <b>Copy script</b>). Click the 💾 <b>Save</b> icon.</li>
<li>Click <b>Deploy → New deployment</b>. Click the ⚙️ gear next to "Select type" and choose <b>Web app</b>.<br>Set <b>Execute as: Me</b> and <b>Who has access: Anyone</b>. Click <b>Deploy</b>.</li>
<li>Google asks you to authorise: click <b>Authorise access</b>, choose your account. If you see "Google hasn\'t verified this app", click <b>Advanced → Go to (unsafe)</b>. That warning appears because this is your own private script, not a public app. Then click <b>Allow</b>.</li>
<li>Copy the <b>Web app URL</b> (it ends in <code>/exec</code>) and paste it below.</li>
</ol>
<textarea id="gs" readonly>' . htmlspecialchars($script) . '</textarea>
<p><button type="button" onclick="var t=document.getElementById(\'gs\');t.select();navigator.clipboard?navigator.clipboard.writeText(t.value).then(function(){alert(\'Script copied\')}):document.execCommand(\'copy\')">Copy script</button></p>
<form method="post" autocomplete="off"><input type="hidden" name="key" value="' . htmlspecialchars($key) . '"><label>Web app URL<input name="url" inputmode="url" placeholder="https://script.google.com/macros/s/.../exec" required></label><button type="submit">Connect and test</button></form>');
