<?php
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off'), 'path' => '/']);
    session_start();
}

// ============================================================
// Helpers
// ============================================================
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function inr($n): string { return '₹' . number_format((float)$n, 0); }
function redirect(string $url): void { header("Location: $url"); exit; }

function flash(?string $msg = null, string $type = 'success') {
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $type]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function csrf_check(): void {
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? null)) {
        http_response_code(400);
        exit('Invalid form token. Go back and try again.');
    }
}

// ============================================================
// Auth
// ============================================================
function user(): ?array { return $_SESSION['user'] ?? null; }

function require_login(?string $role = null): array {
    $u = user();
    if (!$u) { flash('Please log in first.', 'warning'); redirect('login.php'); }
    if ($role && $u['role'] !== $role) { flash('That page is not for your account type.', 'warning'); redirect(home_for($u['role'])); }
    return $u;
}

function home_for(string $role): string {
    return ['admin' => 'admin.php', 'provider' => 'provider.php'][$role] ?? 'index.php';
}

// ============================================================
// Locations: Bengaluru areas with approximate centre coordinates
// ============================================================
const AREAS = [
    'Yelahanka'       => [13.1007, 77.5963],
    'Hebbal'          => [13.0358, 77.5970],
    'Rajajinagar'     => [12.9915, 77.5554],
    'Indiranagar'     => [12.9784, 77.6408],
    'Whitefield'      => [12.9698, 77.7500],
    'Marathahalli'    => [12.9569, 77.7011],
    'Koramangala'     => [12.9352, 77.6245],
    'Jayanagar'       => [12.9250, 77.5938],
    'Electronic City' => [12.8452, 77.6602],
];

function distance_km(string $a, string $b): float {
    if (!isset(AREAS[$a], AREAS[$b])) return 15.0;
    [$lat1, $lon1] = AREAS[$a];
    [$lat2, $lon2] = AREAS[$b];
    $r = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $h = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    $d = 2 * $r * asin(sqrt($h));
    return $a === $b ? 1.5 : round($d, 1); // same area ~1.5 km
}

// ============================================================
// Services
// ============================================================
function services(): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $res = db()->query('SELECT * FROM services ORDER BY id');
        while ($r = $res->fetch_assoc()) $cache[(int)$r['id']] = $r;
    }
    return $cache;
}
function service_by_slug(string $slug): ?array {
    foreach (services() as $s) if ($s['slug'] === $slug) return $s;
    return null;
}

// ============================================================
// AI 1: Problem assistant (NLP keyword classifier, English + Hinglish)
// ============================================================
const SERVICE_KEYWORDS = [
    'plumbing' => ['leak' => 3, 'leaking' => 3, 'tap' => 3, 'pipe' => 3, 'drain' => 3, 'clog' => 3, 'blocked' => 2,
        'toilet' => 3, 'flush' => 3, 'sink' => 3, 'basin' => 2, 'water' => 1, 'geyser' => 2, 'shower' => 2, 'tank' => 2,
        'motor' => 1, 'nal' => 3, 'paani' => 2, 'pani' => 2, 'tapak' => 3, 'overflow' => 2, 'seepage' => 2, 'bathroom' => 1],
    'electrical' => ['switch' => 3, 'socket' => 3, 'wiring' => 3, 'wire' => 2, 'fan' => 3, 'light' => 2, 'bulb' => 3,
        'tubelight' => 3, 'mcb' => 3, 'fuse' => 3, 'spark' => 3, 'short circuit' => 3, 'shock' => 3, 'power' => 2,
        'inverter' => 3, 'voltage' => 2, 'bijli' => 3, 'pankha' => 3, 'current' => 2, 'plug' => 2, 'tripping' => 3, 'doorbell' => 2],
    'cleaning' => ['clean' => 3, 'cleaning' => 3, 'dust' => 2, 'dirty' => 3, 'stain' => 2, 'sofa' => 2, 'carpet' => 2,
        'kitchen' => 1, 'deep clean' => 3, 'mop' => 2, 'safai' => 3, 'ganda' => 3, 'cockroach' => 2, 'smell' => 1,
        'move in' => 2, 'party' => 1, 'grease' => 2, 'mould' => 2, 'mold' => 2],
    'ac-servicing' => ['ac' => 4, 'air conditioner' => 4, 'aircon' => 4, 'cooling' => 1, 'not cooling' => 1, 'gas' => 2, 'refill' => 2,
        'compressor' => 2, 'split' => 2, 'thanda' => 2, 'hot air' => 2, 'ac service' => 3, 'filter' => 1, 'remote' => 1,
        'dripping' => 1, 'cool' => 1],
    'appliance-repair' => ['fridge' => 4, 'refrigerator' => 4, 'washing machine' => 3, 'microwave' => 3, 'oven' => 2,
        'tv' => 3, 'television' => 3, 'mixer' => 3, 'grinder' => 2, 'chimney' => 3, 'dishwasher' => 3, 'ro' => 2,
        'purifier' => 3, 'iron' => 2, 'appliance' => 3, 'not spinning' => 3, 'drum' => 2, 'display' => 1, 'stove' => 2],
];

const URGENCY_KEYWORDS = [
    'emergency' => ['emergency', 'spark', 'smoke', 'burning', 'fire', 'shock', 'flood', 'flooding', 'burst', 'gas smell', 'abhi', 'turant'],
    'same_day'  => ['urgent', 'today', 'asap', 'quickly', 'jaldi', 'aaj', 'tonight', 'right now', 'immediately'],
];

function classify_problem(string $text): array {
    $t = ' ' . strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', $text)) . ' ';
    $scores = [];
    $hits = [];
    foreach (SERVICE_KEYWORDS as $slug => $words) {
        $scores[$slug] = 0;
        foreach ($words as $w => $wt) {
            if (str_contains($t, ' ' . $w . ' ') || (strlen($w) > 4 && str_contains($t, $w))) {
                $scores[$slug] += $wt;
                $hits[$slug][] = $w;
            }
        }
    }
    arsort($scores);
    $total = array_sum($scores);
    $top = array_key_first($scores);

    $urgency = 'normal';
    foreach (URGENCY_KEYWORDS as $level => $words) {
        foreach ($words as $w) {
            if (preg_match('/\b' . preg_quote($w, '/') . '/', $t)) { $urgency = $level; break 2; }
        }
    }

    $hazard = null;
    if (preg_match('/\b(lpg|cylinder)\b.*\b(leak|smell|badbu)|\bgas\b.*\b(leak\w*|smell\w*|badbu|bu aa)|\b(leak\w*|smell\w*)\b.*\bgas\b/', $t)) $hazard = 'gas';
    elseif (preg_match('/spark|short circuit|smoke|burning|fire|shock/', $t)) $hazard = 'electric';
    elseif (preg_match('/burst|flood/', $t)) $hazard = 'flood';
    if ($hazard) $urgency = 'emergency';
    if ($hazard === 'gas') { $top = SOS_HAZARDS['gas'][1]; $scores[$top] = max($scores[$top], 6); $hits[$top][] = 'gas'; $total = array_sum($scores); }

    if ($total === 0) {
        if ($hazard) { $svc = service_by_slug(SOS_HAZARDS[$hazard][1]); $scores[$svc['slug']] = 3; $total = 3; $top = $svc['slug']; $hits[$top] = [$hazard]; }
        else
        return ['found' => false, 'message' => "I couldn't tell which service you need. Try mentioning the item, e.g. 'tap leaking' or 'AC not cooling'."];
    }
    $svc = service_by_slug($top);
    // confidence = exclusivity (share of evidence for the top service) x evidence strength
    $conf = (int)round(min(97, 100 * ($scores[$top] / $total) * min(1, ($scores[$top] + 3) / 9)));
    $alts = [];
    foreach ($scores as $slug => $sc) {
        if ($slug !== $top && $sc > 0) $alts[] = ['name' => service_by_slug($slug)['name'], 'slug' => $slug];
    }
    $price = estimate_price((int)$svc['id'], $urgency, date('Y-m-d H:i'), user()['area'] ?? 'Koramangala');
    return [
        'found' => true,
        'service_id' => (int)$svc['id'],
        'service' => $svc['name'],
        'slug' => $top,
        'confidence' => $conf,
        'keywords' => array_values(array_unique($hits[$top])),
        'urgency' => $urgency,
        'estimate' => $price['total'], 'min' => $price['min'], 'max' => $price['max'],
        'alternatives' => array_slice($alts, 0, 2),
        'hazard' => $hazard,
        'safety' => $hazard ? implode(' ', array_slice(SOS_HAZARDS[$hazard][2], 0, 2))
            : ($urgency === 'emergency' ? 'Keep clear of the affected area until the technician arrives.' : null),
    ];
}

// ============================================================
// AI 2: Smart pricing, zone surge and fair price range
// P_smart = P_base x (1 + U_urgency + T_peak + D_demand) + Z_zone + Rs 29
// Fair range: P_min = 0.90 x P_smart, P_max = 1.15 x P_smart
// ============================================================
const URGENCY_PCT = ['normal' => 0, 'same_day' => 25, 'emergency' => 50, 'sos' => 50];
const URGENCY_LABEL = ['normal' => 'Standard', 'same_day' => 'Same day', 'emergency' => 'Emergency (within 2 hrs)', 'sos' => 'SOS Critical'];
const BOOKABLE_URGENCY = ['normal', 'same_day', 'emergency']; // SOS is created only from the SOS button
const URGENCY_BADGE = ['normal' => 'secondary', 'same_day' => 'warning', 'emergency' => 'danger', 'sos' => 'danger'];
const ZONE_BONUS = ['green' => 0, 'yellow' => 50, 'red' => 150];
const ZONE_BADGE = ['green' => 'success', 'yellow' => 'warning', 'red' => 'danger'];
const PLATFORM_FEE = 29;

function demand_ratio(int $serviceId): float {
    // last 7 days vs weekly average of the previous 21 days, for this service
    $st = db()->prepare("SELECT
        SUM(created_at >= NOW() - INTERVAL 7 DAY) AS recent,
        SUM(created_at <  NOW() - INTERVAL 7 DAY AND created_at >= NOW() - INTERVAL 28 DAY) AS prev
        FROM bookings WHERE service_id = ?");
    $st->bind_param('i', $serviceId);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $recent = (int)$r['recent'];
    $prevWeekly = ((int)$r['prev']) / 3;
    return $prevWeekly > 0 ? $recent / $prevWeekly : 1.0;
}

// Zone surge: weekly bookings per online technician in each area.
// Green = normal, Yellow = moderate shortage (+Rs 50), Red = high shortage (+Rs 150). Bonus goes 100% to the technician.
function zones(): array {
    static $z = null;
    if ($z !== null) return $z;
    $demand = [];
    $supply = [];
    foreach (db()->query("SELECT area, COUNT(*) c FROM bookings WHERE created_at >= NOW() - INTERVAL 7 DAY AND status <> 'cancelled' GROUP BY area") as $r) $demand[$r['area']] = (int)$r['c'];
    foreach (db()->query("SELECT area, COUNT(*) c FROM technicians WHERE available = 1 GROUP BY area") as $r) $supply[$r['area']] = (int)$r['c'];
    $z = [];
    foreach (array_keys(AREAS) as $a) {
        $d = $demand[$a] ?? 0;
        $s = $supply[$a] ?? 0;
        $ratio = $d / max(1, $s);
        $zone = $ratio >= 9 ? 'red' : ($ratio >= 6.5 ? 'yellow' : 'green');
        $z[$a] = ['zone' => $zone, 'demand' => $d, 'supply' => $s, 'ratio' => round($ratio, 1), 'bonus' => ZONE_BONUS[$zone]];
    }
    return $z;
}

function estimate_price(int $serviceId, string $urgency, string $scheduledAt, ?string $area = null): array {
    $svc = services()[$serviceId];
    $base = (int)$svc['base_price'];
    $lines = [['Base price: ' . $svc['name'], $base]];

    $up = URGENCY_PCT[$urgency] ?? 0;
    $urg = (int)round($base * $up / 100);
    if ($urg) $lines[] = [URGENCY_LABEL[$urgency] . " (+$up%)", $urg];

    $ts = strtotime($scheduledAt) ?: time();
    $hour = (int)date('G', $ts);
    $weekend = (int)date('N', $ts) >= 6;
    $peak = 0;
    if ($hour >= 18 && $hour <= 21) { $peak = (int)round($base * 0.10); $lines[] = ['Evening peak hours (+10%)', $peak]; }
    elseif ($weekend) { $peak = (int)round($base * 0.10); $lines[] = ['Weekend peak (+10%)', $peak]; }

    $ratio = demand_ratio($serviceId);
    $dem = 0;
    if ($ratio > 1.2) { $dem = (int)round($base * 0.05); $lines[] = ['High demand this week (+5%)', $dem]; }

    $zone = 'green';
    $zb = 0;
    if ($area !== null && isset(zones()[$area])) {
        $zone = zones()[$area]['zone'];
        $zb = ZONE_BONUS[$zone];
        if ($zb) $lines[] = [ucfirst($zone) . " zone surge in $area (goes to technician)", $zb];
    }

    $lines[] = ['Platform fee', PLATFORM_FEE];
    $total = $base + $urg + $peak + $dem + $zb + PLATFORM_FEE;
    return ['base' => $base, 'urgency' => $urg, 'peak' => $peak, 'demand' => $dem, 'zone' => $zone, 'zone_bonus' => $zb,
            'fee' => PLATFORM_FEE, 'total' => $total, 'min' => (int)round($total * 0.90), 'max' => (int)round($total * 1.15),
            'lines' => $lines, 'demand_ratio' => round($ratio, 2)];
}

// AI Fair Price guardrail for quotes made during negotiation
const FAIRNESS_LABEL = ['within' => ['Within AI fair range', 'success'], 'fair' => ['Fair, slightly above range', 'warning'], 'high' => ['Unusually high', 'danger']];
function fairness(int $amount, int $pmax): string {
    return $amount <= $pmax ? 'within' : ($amount <= 1.15 * $pmax ? 'fair' : 'high');
}

// ============================================================
// AI 3: Explainable 100-point technician matching
// Only technicians who are Online, Document Verified and offer the service are scored.
// Score = Skill (30) + Availability (25) + Location (20) + Rating (15) + Experience (10)
// ============================================================
const TIER_LABEL = ['beginner' => 'Beginner', 'experienced' => 'Experienced', 'certified' => 'Certified Technician'];
const TIER_POINTS = ['beginner' => 0, 'experienced' => 3, 'certified' => 6];

function match_technicians(int $serviceId, string $area, string $urgency, int $limit = 3, ?string $when = null, array $exclude = []): array {
    $when = $when ?: date('Y-m-d H:i:s');
    $sql = "SELECT t.*, u.name, u.phone,
              (SELECT COUNT(*) FROM bookings b WHERE b.technician_id = t.id AND b.status IN ('pending','accepted','in_progress')
                 AND ABS(TIMESTAMPDIFF(MINUTE, b.scheduled_at, ?)) <= 180) AS slot_jobs
            FROM technicians t
            JOIN users u ON u.id = t.user_id
            JOIN technician_services ts ON ts.technician_id = t.id AND ts.service_id = ?
            WHERE t.available = 1 AND t.doc_verified = 1";
    $st = db()->prepare($sql);
    $st->bind_param('si', $when, $serviceId);
    $st->execute();
    $res = $st->get_result();
    $critical = in_array($urgency, ['emergency', 'sos'], true);
    $out = [];
    while ($t = $res->fetch_assoc()) {
        if (in_array((int)$t['id'], $exclude, true)) continue;
        $km = distance_km($area, $t['area']);
        $slot = (int)$t['slot_jobs'];
        $jobs = (int)$t['jobs_done'];
        $p = [
            'Skill' => 24 + (TIER_POINTS[$t['skill_tier']] ?? 0),               // 24 for offering the service, +3 Experienced, +6 Certified
            'Availability' => max(0, 25 - 8 * $slot),                           // -8 per job within +/-3 hours of the slot
            'Location' => max(0, 20 * (1 - $km / 25)),                          // Haversine distance, zero beyond 25 km
            'Rating' => ((float)$t['rating'] / 5) * 15 * min(1, $jobs / 5),     // confidence factor for new technicians
            'Experience' => min(10, (int)$t['experience_years'] * 1.5),
        ];
        if ($critical) { // emergencies: Location and Experience get a 1.25x boost, re-normalised to 100
            $p['Location'] *= 1.25;
            $p['Experience'] *= 1.25;
            $norm = 100 / (30 + 25 + 25 + 15 + 12.5);
            foreach ($p as $k => $v) $p[$k] = $v * $norm;
        }
        foreach ($p as $k => $v) $p[$k] = round($v, 1);
        $badges = [];
        if ((float)$t['rating'] >= 4.7 && $jobs >= 10) $badges[] = 'Top Rated';
        if ((int)$t['experience_years'] >= 5) $badges[] = 'Most Experienced';
        $badges[] = 'Verified';
        $out[] = $t + [
            'km' => $km, 'score' => (int)round(array_sum($p)), 'eta' => max(20, (int)round($km * 4 + $slot * 30)),
            'parts' => $p, 'badges' => $badges,
            'reasons' => [
                TIER_LABEL[$t['skill_tier']] . ' in ' . services()[$serviceId]['name'],
                $km . ' km away (' . $t['area'] . ')',
                '★ ' . number_format((float)$t['rating'], 1) . ' from ' . $jobs . ' jobs',
                $slot === 0 ? 'Free at this time' : "$slot job" . ($slot > 1 ? 's' : '') . ' near this slot',
                $t['experience_years'] . ' yrs experience',
            ],
        ];
    }
    usort($out, fn($a, $b) => [$b['score'], $a['km']] <=> [$a['score'], $b['km']]);
    return array_slice($out, 0, $limit);
}

// ============================================================
// AI 4: Weather-augmented demand forecasting
// Y(t) = (a + b*t) x S_weekday(t) x W_weather(t)
// Least-squares trend and day-of-week seasonality fitted on the last 28 days.
// ============================================================
// condition => [label, icon, multiplier, service slug it boosts]
const WEATHER = [
    'normal' => ['Normal', 'bi-sun', 1.00, null],
    'rain'   => ['Heavy rain', 'bi-cloud-rain-heavy', 1.35, 'plumbing'],
    'heat'   => ['Heatwave', 'bi-thermometer-sun', 1.40, 'ac-servicing'],
    'storm'  => ['Storm winds', 'bi-wind', 1.30, 'electrical'],
];

function weather_next(int $days = 7): array {
    $map = [];
    foreach (db()->query("SELECT forecast_date, cond FROM weather_logs WHERE forecast_date > CURDATE()") as $r) $map[$r['forecast_date']] = $r['cond'];
    $out = [];
    for ($h = 1; $h <= $days; $h++) {
        $d = date('Y-m-d', strtotime("+$h day"));
        $out[$d] = isset(WEATHER[$map[$d] ?? '']) ? $map[$d] : 'normal';
    }
    return $out;
}

function service_share(string $slug): float {
    static $share = null;
    if ($share === null) {
        $share = [];
        $tot = 0;
        foreach (db()->query("SELECT s.slug, COUNT(b.id) c FROM services s LEFT JOIN bookings b ON b.service_id = s.id AND b.created_at >= NOW() - INTERVAL 28 DAY GROUP BY s.id") as $r) { $share[$r['slug']] = (int)$r['c']; $tot += (int)$r['c']; }
        foreach ($share as $k => $v) $share[$k] = $tot ? $v / $tot : 0;
    }
    return $share[$slug] ?? 0;
}

function daily_counts(int $days, ?int $serviceId = null): array {
    $sql = "SELECT DATE(created_at) d, COUNT(*) c FROM bookings
            WHERE created_at >= CURDATE() - INTERVAL ? DAY" . ($serviceId ? " AND service_id = ?" : "") . " GROUP BY d";
    $st = db()->prepare($sql);
    $daysBack = $days - 1;
    if ($serviceId) $st->bind_param('ii', $daysBack, $serviceId); else $st->bind_param('i', $daysBack);
    $st->execute();
    $map = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $map[$r['d']] = (int)$r['c'];
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $out[$d] = $map[$d] ?? 0;
    }
    return $out;
}

function forecast_demand(int $horizon = 7, ?int $serviceId = null): array {
    $hist = daily_counts(28, $serviceId);
    $y = array_values($hist);
    $dates = array_keys($hist);
    $n = count($y);
    // 1. linear trend via least squares
    $xs = range(0, $n - 1);
    $mx = array_sum($xs) / $n;
    $my = array_sum($y) / $n;
    $num = 0; $den = 0;
    foreach ($xs as $i => $x) { $num += ($x - $mx) * ($y[$i] - $my); $den += ($x - $mx) ** 2; }
    $slope = $den ? $num / $den : 0;
    $intercept = $my - $slope * $mx;
    // 2. day-of-week seasonal index = avg(actual / trend) per weekday
    $sum = array_fill(1, 7, 0); $cnt = array_fill(1, 7, 0);
    foreach ($y as $i => $v) {
        $trend = max(0.5, $intercept + $slope * $i);
        $dow = (int)date('N', strtotime($dates[$i]));
        $sum[$dow] += $v / $trend; $cnt[$dow]++;
    }
    $season = [];
    for ($d = 1; $d <= 7; $d++) $season[$d] = $cnt[$d] ? $sum[$d] / $cnt[$d] : 1;
    // 3. forecast, scaled by the weather multiplier
    $weather = weather_next($horizon);
    $slug = $serviceId ? services()[$serviceId]['slug'] : null;
    $fc = []; $base = [];
    for ($h = 1; $h <= $horizon; $h++) {
        $date = date('Y-m-d', strtotime("+$h day", strtotime(end($dates))));
        $trend = max(0, $intercept + $slope * ($n - 1 + $h));
        $b = $trend * $season[(int)date('N', strtotime($date))];
        [, , $mult, $boost] = WEATHER[$weather[$date] ?? 'normal'];
        // one service: full multiplier if it is the boosted trade; all services: weighted by that trade's share of bookings
        $w = $boost === null ? 1 : ($slug !== null ? ($slug === $boost ? $mult : 1) : 1 + service_share($boost) * ($mult - 1));
        $base[$date] = max(0, round($b, 1));
        $fc[$date] = max(0, round($b * $w, 1));
    }
    // 4. in-sample fit error (MAPE) for honesty on the dashboard
    $err = 0; $m = 0;
    foreach ($y as $i => $v) {
        if ($v <= 0) continue;
        $pred = max(0, $intercept + $slope * $i) * $season[(int)date('N', strtotime($dates[$i]))];
        $err += abs($v - $pred) / $v; $m++;
    }
    return ['history' => $hist, 'forecast' => $fc, 'base' => $base, 'weather' => $weather, 'slope' => round($slope, 3),
            'season' => $season, 'mape' => $m ? round(100 * $err / $m, 1) : null];
}

// ============================================================
// Booking orchestration: OTP, Safety Shield, escrow, warranty, automatic replacement
// ============================================================
function new_otp(): string { return (string)random_int(100000, 999999); }

function load_booking(int $id): ?array {
    $st = db()->prepare('SELECT * FROM bookings WHERE id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    return $st->get_result()->fetch_assoc() ?: null;
}

// HomeEase Safety Shield: simulated on-duty accident cover from acceptance to 45 min after the job
const SHIELD_LABEL = ['outbound' => 'Active · travelling to customer', 'on_job' => 'Active · on job', 'return' => 'Active · return journey (45 min)', 'closed' => 'Closed', 'sos' => 'SOS triggered'];
function shield_activate(int $bookingId, int $techId): void {
    $policy = 'HES-' . date('ym') . '-' . str_pad((string)$bookingId, 5, '0', STR_PAD_LEFT);
    $st = db()->prepare("INSERT INTO safety_shields (booking_id, technician_id, policy_no, status, activated_at) VALUES (?,?,?,'outbound', NOW())");
    $st->bind_param('iis', $bookingId, $techId, $policy);
    $st->execute();
}
function shield_set(int $bookingId, int $techId, string $status): void {
    $extra = $status === 'return' ? ', return_expires_at = NOW() + INTERVAL 45 MINUTE' : '';
    $st = db()->prepare("UPDATE safety_shields SET status = ? $extra WHERE booking_id = ? AND technician_id = ? AND status IN ('outbound','on_job','return')");
    $st->bind_param('sii', $status, $bookingId, $techId);
    $st->execute();
}
function shields_expire(): void {
    db()->query("UPDATE safety_shields SET status = 'closed' WHERE status = 'return' AND return_expires_at < NOW()");
}

// AI automatic replacement: next best technician keeps the locked price and the escrow hold.
// $force = true (cancel / SOS): the old technician is removed even if nobody else is free.
function replace_technician(array $b, string $reason, bool $force = false): ?array {
    $db = db();
    $prev = $b['technician_id'] !== null ? (int)$b['technician_id'] : null;
    $tried = $prev ? [$prev] : [];
    $st = $db->prepare('SELECT prev_technician_id FROM replacement_logs WHERE booking_id = ?' . ($prev ? '' : ' AND created_at > NOW() - INTERVAL 30 MINUTE'));
    $st->bind_param('i', $b['id']);
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) if ($r['prev_technician_id']) $tried[] = (int)$r['prev_technician_id'];
    $new = match_technicians((int)$b['service_id'], $b['area'], $b['urgency'], 1, $b['scheduled_at'], $tried)[0] ?? null;
    if (!$new && !$force) return null;

    if ($prev) shield_set((int)$b['id'], $prev, 'closed');
    $newId = $new ? (int)$new['id'] : null;
    $score = $new ? (int)$new['score'] : null;
    $st = $db->prepare("UPDATE bookings SET technician_id = ?, match_score = COALESCE(?, match_score),
        status = IF(status IN ('accepted','in_progress'), 'pending', status), started_at = NULL WHERE id = ?");
    $st->bind_param('iii', $newId, $score, $b['id']);
    $st->execute();
    $st = $db->prepare('INSERT INTO replacement_logs (booking_id, prev_technician_id, new_technician_id, reason, new_score) VALUES (?,?,?,?,?)');
    $st->bind_param('iiisi', $b['id'], $prev, $newId, $reason, $score);
    $st->execute();
    return $new;
}

// Customer confirmed the job: release escrow to the technician wallet and issue the 30-day digital warranty
function release_escrow(array $b): bool {
    $db = db();
    $st = $db->prepare("UPDATE bookings SET escrow_status = 'released', customer_confirmed = 1 WHERE id = ? AND escrow_status IN ('held','disputed')");
    $st->bind_param('i', $b['id']);
    $st->execute();
    if ($st->affected_rows !== 1) return false; // someone else already released it
    $payout = max(0, (int)$b['price_total'] - PLATFORM_FEE);
    if ($payout > 0 && $b['technician_id']) {
        $st = $db->prepare('UPDATE technicians SET wallet_balance = wallet_balance + ? WHERE id = ?');
        $st->bind_param('ii', $payout, $b['technician_id']);
        $st->execute();
    }
    if (!$b['parent_booking_id']) { // warranty follow-ups do not get a new warranty
        $days = (int)(services()[(int)$b['service_id']]['warranty_days'] ?? 30);
        $code = 'HEW-' . strtoupper(substr(md5($b['id'] . '|' . $b['otp']), 0, 8));
        $st = $db->prepare("INSERT IGNORE INTO warranties (booking_id, code, customer_id, technician_id, service_id, valid_from, valid_until, status)
            VALUES (?,?,?,?,?, CURDATE(), CURDATE() + INTERVAL ? DAY, 'active')");
        $st->bind_param('isiiii', $b['id'], $code, $b['customer_id'], $b['technician_id'], $b['service_id'], $days);
        $st->execute();
    }
    return true;
}

// Emergency SOS hazards: label, service slug, life-safety instructions
const SOS_HAZARDS = [
    'gas' => ['Gas leak', 'appliance-repair', [
        'Do not switch any light or appliance on or off, and do not light a flame.',
        'Turn off the gas regulator or cylinder valve if you can reach it safely.',
        'Open all doors and windows, then move everyone outside.',
        'From outside, call the LPG emergency helpline 1906 or 112.',
    ]],
    'electric' => ['Electrical short circuit or sparks', 'electrical', [
        'Do not touch the sparking switch, wire or appliance.',
        'Switch off the main MCB if you can reach it safely.',
        'Keep water away. Never use water on an electrical fire.',
        'If there is fire or smoke, leave the house and call 101 or 112.',
    ]],
    'flood' => ['Burst pipe or flooding', 'plumbing', [
        'Close the main water inlet valve or the overhead tank valve.',
        'Switch off power to the wet area if water is near sockets.',
        'Do not use electrical appliances while standing in water.',
        'Move valuables and electronics to a dry, higher place.',
    ]],
];

// Nearest major hospital per area, shown when a technician triggers SOS
const HOSPITALS = [
    'Yelahanka' => 'Aster CMI Hospital, Hebbal', 'Hebbal' => 'Manipal Hospital, Hebbal', 'Rajajinagar' => 'Suguna Hospital, Rajajinagar',
    'Indiranagar' => 'Chinmaya Mission Hospital, Indiranagar', 'Whitefield' => 'Manipal Hospital, Whitefield',
    'Marathahalli' => 'Sakra World Hospital, Marathahalli', 'Koramangala' => "St. John's Medical College Hospital, Koramangala",
    'Jayanagar' => 'Apollo Speciality Hospital, Jayanagar', 'Electronic City' => 'Narayana Health City, Bommasandra',
];

// ============================================================
// Cross-sell: "customers who booked X also booked Y"
// ============================================================
function also_booked(int $serviceId): ?array {
    $st = db()->prepare("SELECT s.id, s.name, s.slug, COUNT(DISTINCT b2.customer_id) c
        FROM bookings b1 JOIN bookings b2 ON b1.customer_id = b2.customer_id AND b2.service_id <> b1.service_id
        JOIN services s ON s.id = b2.service_id
        WHERE b1.service_id = ? GROUP BY s.id ORDER BY c DESC LIMIT 1");
    $st->bind_param('i', $serviceId);
    $st->execute();
    return $st->get_result()->fetch_assoc() ?: null;
}

const STATUS_STEPS = ['pending' => 'Requested', 'accepted' => 'Accepted', 'in_progress' => 'In progress', 'completed' => 'Completed'];
const STATUS_LABEL = ['negotiating' => 'Negotiating', 'pending' => 'Requested', 'accepted' => 'Accepted', 'in_progress' => 'In progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
const STATUS_BADGE = ['negotiating' => 'dark', 'pending' => 'warning', 'accepted' => 'info', 'in_progress' => 'primary', 'completed' => 'success', 'cancelled' => 'secondary'];
const ESCROW_LABEL = ['unpaid' => ['Payment pending', 'secondary'], 'held' => ['Held in escrow', 'info'], 'released' => ['Released to technician', 'success'],
    'refunded' => ['Refunded', 'secondary'], 'disputed' => ['Disputed, funds frozen', 'danger']];

// Re-dispatch watchdog, called on every page load and auto-refresh poll:
//  - any pending booking left with no technician (cancel / SOS when nobody else was free) is retried
//  - SOS Critical bookings not accepted within 10 minutes go to the next nearest technician
function sos_watchdog(): void {
    $rows = db()->query("SELECT b.* FROM bookings b WHERE b.status = 'pending' AND (b.technician_id IS NULL
        OR (b.urgency = 'sos' AND b.created_at < NOW() - INTERVAL 10 MINUTE
            AND NOT EXISTS (SELECT 1 FROM replacement_logs r WHERE r.booking_id = b.id AND r.created_at > NOW() - INTERVAL 10 MINUTE)))")->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $b) replace_technician($b, $b['technician_id'] === null ? 'Re-dispatched (was unassigned)' : 'No response in 10 min (SOS)');
}

// ============================================================
// Premium plan
// ============================================================
require_once __DIR__ . '/../config/ai.php';

// Adds the premium columns to databases created before this feature (no reset needed)
function ensure_premium_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!db()->query("SHOW COLUMNS FROM users LIKE 'is_premium'")->num_rows) {
        db()->query("ALTER TABLE users ADD COLUMN is_premium TINYINT(1) DEFAULT 0, ADD COLUMN premium_until DATE NULL");
    }
}

function is_premium(?array $u = null): bool {
    $u = $u ?? user();
    if (!$u || $u['role'] !== 'customer') return false;
    ensure_premium_schema();
    $st = db()->prepare('SELECT is_premium, premium_until FROM users WHERE id = ?');
    $st->bind_param('i', $u['id']);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    return $r && $r['is_premium'] && (!$r['premium_until'] || strtotime($r['premium_until']) >= strtotime('today'));
}
