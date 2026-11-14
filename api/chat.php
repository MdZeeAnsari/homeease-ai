<?php
// Premium AI chat. Gemini answers when a key is configured and reachable;
// otherwise the built-in HomeEase AI answers, so the chat never breaks.
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

function out(array $a): void { echo json_encode($a); exit; }

$u = user();
if (!$u || $u['role'] !== 'customer') { http_response_code(401); out(['error' => 'Please log in as a customer.']); }
if (!is_premium($u)) { http_response_code(403); out(['error' => 'AI chat is a Premium feature.', 'upgrade' => true]); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? null)) { http_response_code(400); out(['error' => 'Invalid request. Refresh the page.']); }

if (($_POST['action'] ?? '') === 'reset') { $_SESSION['chat'] = []; out(['ok' => true]); }

$msg = trim(mb_substr($_POST['message'] ?? '', 0, 600));
if ($msg === '') out(['error' => 'Type a message first.']);

// simple per-session limit so a demo cannot burn the free Gemini quota
$now = time();
$_SESSION['chat_times'] = array_values(array_filter($_SESSION['chat_times'] ?? [], fn($t) => $t > $now - 600));
if (count($_SESSION['chat_times']) >= 25) out(['reply' => 'You are sending messages very fast. Please wait a minute and try again.', 'source' => 'limit']);
$_SESSION['chat_times'][] = $now;

// ---- context for the model: catalogue, prices, this customer's bookings ----
$area = $u['area'] ?? 'Koramangala';
$catalogue = [];
foreach (services() as $s) {
    $p = estimate_price((int)$s['id'], 'normal', date('Y-m-d H:i'), $area);
    $catalogue[] = "- {$s['name']}: {$s['description']}. Fair range today in $area: ₹{$p['min']}–₹{$p['max']} (same day +25%, emergency +50%).";
}
$st = db()->prepare("SELECT b.id, s.name service, b.description, b.status, b.escrow_status, b.scheduled_at, b.price_total, tu.name tech
    FROM bookings b JOIN services s ON s.id = b.service_id LEFT JOIN technicians t ON t.id = b.technician_id LEFT JOIN users tu ON tu.id = t.user_id
    WHERE b.customer_id = ? ORDER BY b.created_at DESC LIMIT 6");
$st->bind_param('i', $u['id']);
$st->execute();
$mine = [];
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $b) {
    $mine[] = "- #{$b['id']} {$b['service']} \"{$b['description']}\": " . (STATUS_LABEL[$b['status']] ?? $b['status']) . ", payment " . ESCROW_LABEL[$b['escrow_status']][0]
        . ", " . date('D d M g:i A', strtotime($b['scheduled_at'])) . ", ₹{$b['price_total']}" . ($b['tech'] ? ", technician {$b['tech']}" : '');
}
$first = strtok($u['name'], ' ');
$system = "You are HomeEase AI, the Premium home-services assistant for $first in $area, Bengaluru.
Help with home repair problems: explain the likely cause, give safe quick checks the customer can do, and recommend which HomeEase service to book.
Rules:
- Reply in the customer's language and style (English, Hindi or Hinglish). Keep replies under 90 words, friendly, no long lists.
- Only recommend HomeEase services from the catalogue below and quote only the fair ranges given. Never invent other prices, discounts or technicians.
- Never tell the customer to do risky electrical, gas or plumbing work themselves.
- For gas smell, sparks, smoke, electric shock or flooding: tell them to stay safe and press the red SOS button in the app immediately.
- If asked about their bookings, answer only from the bookings list below.
- If the question is unrelated to home services, politely steer back.
Services:
" . implode("\n", $catalogue) . "
$first's recent bookings:
" . ($mine ? implode("\n", $mine) : '- none yet') . "
Today is " . date('l, d M Y, g:i A') . ".";

// built-in AI always runs too: it powers the Book button and the fallback reply
$ai = classify_problem($msg);
$suggest = null;
if (!empty($ai['found'])) {
    $suggest = ['service_id' => $ai['service_id'], 'service' => $ai['service'], 'urgency' => $ai['urgency'], 'min' => $ai['min'], 'max' => $ai['max'], 'hazard' => $ai['hazard']];
}

$history = $_SESSION['chat'] ?? [];
$reply = null;
$source = 'gemini';
$why = null;

// release the session lock so the user's other pages keep working while Gemini thinks
session_write_close();

if (GEMINI_API_KEY !== '' && function_exists('curl_init')) {
    $contents = [];
    foreach (array_slice($history, -10) as $h) $contents[] = ['role' => $h['role'], 'parts' => [['text' => $h['text']]]];
    $contents[] = ['role' => 'user', 'parts' => [['text' => $msg]]];
    $body = json_encode([
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents' => $contents,
        'generationConfig' => ['maxOutputTokens' => 400, 'temperature' => 0.6],
    ]);
    $ch = curl_init((getenv('HE_GEMINI_BASE') ?: 'https://generativelanguage.googleapis.com') . '/v1beta/models/' . rawurlencode(GEMINI_MODEL) . ':generateContent');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . GEMINI_API_KEY],
        CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 15,
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res !== false && $code === 200) {
        $j = json_decode($res, true);
        $parts = $j['candidates'][0]['content']['parts'] ?? [];
        $reply = trim(implode('', array_map(fn($p) => $p['text'] ?? '', $parts)));
        if ($reply === '') { $reply = null; $why = 'empty reply'; }
    } else {
        $why = $code === 429 ? 'Gemini free limit reached' : ($code ? "Gemini error $code" : 'Gemini not reachable from this server' . ($err ? " ($err)" : ''));
    }
} else {
    $why = GEMINI_API_KEY === '' ? 'no Gemini key configured' : 'cURL not available on this server';
}

if ($reply === null) { // built-in HomeEase AI fallback
    $source = 'builtin';
    $low = mb_strtolower($msg);
    if (preg_match('/\b(booking|bookings|status|otp|technician|when|kab|kaha)\b/u', $low) && $mine) {
        $reply = "Here are your latest bookings, $first:\n" . implode("\n", array_slice($mine, 0, 3)) . "\nOpen Bookings for the live status and OTP.";
    } elseif ($suggest) {
        $reply = ($ai['hazard'] ? "⚠️ This sounds like an emergency. " . ($ai['safety'] ?? '') . " Press the red SOS button now.\n\n" : '')
            . "This looks like " . (preg_match('/^[AEIOU]/i', $ai['service']) ? 'an' : 'a') . " **{$ai['service']}** job" . ($ai['urgency'] !== 'normal' ? ' and it sounds urgent' : '') . ". "
            . "The fair price in $area is about ₹{$ai['min']}–₹{$ai['max']}. Book a verified pro below, and you only pay through escrow after the job is done.";
    } else {
        $reply = "Hi $first! Tell me what's wrong at home, like \"bathroom tap leaking\" or \"AC not cooling\", and I'll suggest the right service and a fair price.";
    }
}

session_start();
$history = $_SESSION['chat'] ?? [];
$history[] = ['role' => 'user', 'text' => $msg];
$history[] = ['role' => 'model', 'text' => $reply];
$_SESSION['chat'] = array_slice($history, -16);

out(['reply' => $reply, 'source' => $source, 'note' => $source === 'builtin' ? $why : null, 'suggest' => $suggest]);
