<?php
// AI problem assistant endpoint.
// POST text=...            -> NLP classification
// POST slug=...&conf=...   -> service details for a photo-diagnosis result
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

// add the two best nearby technicians for the customer's area
function with_nearby(array $r): array {
    if (empty($r['found'])) return $r;
    $area = user()['area'] ?? 'Koramangala';
    $r['area'] = $area;
    $r['nearby'] = array_map(fn($t) => ['name' => $t['name'], 'rating' => number_format((float)$t['rating'], 1), 'km' => $t['km'], 'eta' => $t['eta'],
        'tier' => TIER_LABEL[$t['skill_tier']], 'score' => $t['score']], match_technicians($r['service_id'], $area, $r['urgency'] ?? 'normal', 2));
    return $r;
}

$text = trim($_POST['text'] ?? $_GET['text'] ?? '');
$slug = $_POST['slug'] ?? $_GET['slug'] ?? '';

if ($slug !== '') {
    $svc = service_by_slug($slug);
    if (!$svc) { echo json_encode(['found' => false, 'message' => 'Unknown service']); exit; }
    // the caption adds urgency, keywords and hazard safety advice on top of the photo's service
    $c = $text !== '' ? classify_problem(mb_substr($text, 0, 500)) : [];
    $urg = $c['urgency'] ?? 'normal';
    $p = estimate_price((int)$svc['id'], $urg, date('Y-m-d H:i'), user()['area'] ?? 'Koramangala');
    $alt = (!empty($c['found']) && $c['slug'] !== $slug) ? [['name' => $c['service'], 'slug' => $c['slug']]] : [];
    echo json_encode(with_nearby(['found' => true, 'service_id' => (int)$svc['id'], 'service' => $svc['name'], 'slug' => $slug,
        'confidence' => (int)($_POST['conf'] ?? 0), 'urgency' => $urg, 'estimate' => $p['total'], 'min' => $p['min'], 'max' => $p['max'],
        'keywords' => (!empty($c['found']) && $c['slug'] === $slug) ? $c['keywords'] : [], 'alternatives' => $alt,
        'hazard' => $c['hazard'] ?? null, 'safety' => $c['safety'] ?? null]));
    exit;
}
if (mb_strlen($text) < 3) { echo json_encode(['found' => false, 'message' => 'Tell me a little more about the problem.']); exit; }
$r = classify_problem(mb_substr($text, 0, 500));
echo json_encode(with_nearby($r));
