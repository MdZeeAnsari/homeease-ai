<?php
// ============================================================
// One-time setup: creates tables and fills demo data.
// Local: open http://localhost/homeease/setup.php
// Re-seed: setup.php?reset=1   (wipes everything)
// DELETE or rename this file after deploying live.
// ============================================================
require_once __DIR__ . '/config/db.php';
const URGENCY_PCT_SEED = ['normal' => 0, 'same_day' => 25, 'emergency' => 50];

$log = [];
try {
    if ($IS_LOCAL) {
        $root = new mysqli(DB_HOST, DB_USER, DB_PASS);
        $root->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        $log[] = "Database '" . DB_NAME . "' ready";
    }
    $db = db();

    $exists = $db->query("SHOW TABLES LIKE 'users'")->num_rows > 0;
    if ($exists && !$IS_LOCAL && isset($_GET['reset'])) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
        session_start();
        if (($_SESSION['user']['role'] ?? '') !== 'admin') {
            http_response_code(403);
            exit(page('Reset is blocked on the live site. Log in as admin first, then open setup.php?reset=1 again.', []));
        }
    }
    if ($exists && !isset($_GET['reset'])) {
        $n = (int)$db->query("SELECT COUNT(*) c FROM users")->fetch_assoc()['c'];
        if ($n > 0) {
            exit(page("Already set up ($n users). Open <a href='index.php'>the app</a>, or <a href='setup.php?reset=1'>reset and reseed</a> (wipes all data).", []));
        }
    }

    $db->query("SET FOREIGN_KEY_CHECKS = 0");
    foreach (['weather_logs', 'safety_shields', 'replacement_logs', 'warranties', 'price_negotiations', 'bookings', 'technician_services', 'technicians', 'services', 'users'] as $t) $db->query("DROP TABLE IF EXISTS $t");
    $db->query("SET FOREIGN_KEY_CHECKS = 1");

    $db->query("CREATE TABLE users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        email VARCHAR(120) NOT NULL UNIQUE,
        phone VARCHAR(15),
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('customer','provider','admin') NOT NULL DEFAULT 'customer',
        area VARCHAR(40),
        is_premium TINYINT(1) DEFAULT 0,
        premium_until DATE NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $db->query("CREATE TABLE services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL,
        slug VARCHAR(40) NOT NULL UNIQUE,
        icon VARCHAR(40),
        base_price INT NOT NULL,
        description VARCHAR(160),
        duration_min INT DEFAULT 60,
        warranty_days INT DEFAULT 30
    ) ENGINE=InnoDB");

    $db->query("CREATE TABLE technicians (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        area VARCHAR(40) NOT NULL,
        rating DECIMAL(3,2) DEFAULT 4.50,
        jobs_done INT DEFAULT 0,
        experience_years INT DEFAULT 1,
        available TINYINT(1) DEFAULT 1,
        skill_tier ENUM('beginner','experienced','certified') DEFAULT 'beginner',
        quiz_score INT DEFAULT 70,
        doc_verified TINYINT(1) DEFAULT 1,
        wallet_balance INT DEFAULT 0,
        emergency_phone VARCHAR(15),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $db->query("CREATE TABLE technician_services (
        technician_id INT NOT NULL,
        service_id INT NOT NULL,
        PRIMARY KEY (technician_id, service_id),
        FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE CASCADE,
        FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $db->query("CREATE TABLE bookings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        service_id INT NOT NULL,
        technician_id INT NULL,
        area VARCHAR(40) NOT NULL,
        address VARCHAR(200),
        description TEXT,
        urgency ENUM('normal','same_day','emergency','sos') DEFAULT 'normal',
        scheduled_at DATETIME,
        est_min INT, est_max INT,
        price_base INT, price_urgency INT DEFAULT 0, price_peak INT DEFAULT 0, price_demand INT DEFAULT 0, price_zone INT DEFAULT 0, price_fee INT DEFAULT 29,
        negotiated_price INT NULL,
        price_locked TINYINT(1) DEFAULT 1,
        price_total INT,
        match_score INT,
        ai_source ENUM('text','photo','manual') DEFAULT 'manual',
        status ENUM('negotiating','pending','accepted','in_progress','completed','cancelled') DEFAULT 'pending',
        escrow_status ENUM('unpaid','held','released','refunded','disputed') DEFAULT 'unpaid',
        customer_confirmed TINYINT(1) DEFAULT 0,
        otp CHAR(6),
        rating TINYINT NULL,
        review VARCHAR(300) NULL,
        parent_booking_id INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        started_at DATETIME NULL,
        completed_at DATETIME NULL,
        FOREIGN KEY (customer_id) REFERENCES users(id),
        FOREIGN KEY (service_id) REFERENCES services(id),
        FOREIGN KEY (technician_id) REFERENCES technicians(id),
        INDEX (created_at), INDEX (status)
    ) ENGINE=InnoDB");

    $db->query("CREATE TABLE price_negotiations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        booking_id INT NOT NULL,
        sender ENUM('customer','provider','ai') NOT NULL,
        amount INT NOT NULL,
        fairness ENUM('within','fair','high') DEFAULT 'within',
        message VARCHAR(300),
        status ENUM('info','proposed','accepted','rejected','countered') DEFAULT 'proposed',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $db->query("CREATE TABLE warranties (
        id INT AUTO_INCREMENT PRIMARY KEY,
        booking_id INT NOT NULL UNIQUE,
        code VARCHAR(20) NOT NULL UNIQUE,
        customer_id INT NOT NULL, technician_id INT NULL, service_id INT NOT NULL,
        valid_from DATE, valid_until DATE,
        status ENUM('active','claimed','expired') DEFAULT 'active',
        FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $db->query("CREATE TABLE replacement_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        booking_id INT NOT NULL,
        prev_technician_id INT NULL, new_technician_id INT NULL,
        reason VARCHAR(80), new_score INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $db->query("CREATE TABLE safety_shields (
        id INT AUTO_INCREMENT PRIMARY KEY,
        booking_id INT NOT NULL, technician_id INT NOT NULL,
        policy_no VARCHAR(30),
        status ENUM('outbound','on_job','return','closed','sos') DEFAULT 'outbound',
        activated_at DATETIME, return_expires_at DATETIME NULL,
        FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $db->query("CREATE TABLE weather_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        forecast_date DATE NOT NULL UNIQUE,
        cond ENUM('normal','rain','heat','storm') DEFAULT 'normal'
    ) ENGINE=InnoDB");
    $log[] = "10 tables created (users, services, technicians, bookings, negotiations, warranties, replacement logs, safety shields, weather)";

    // ---------------- services ----------------
    $services = [
        ['Plumbing', 'plumbing', 'bi-droplet-fill', 349, 'Leaks, taps, drains, toilets, geysers', 60],
        ['Electrical', 'electrical', 'bi-lightning-charge-fill', 299, 'Switches, wiring, fans, lights, MCB trips', 45],
        ['Home Cleaning', 'cleaning', 'bi-stars', 999, 'Deep clean, kitchen, bathroom, sofa', 180],
        ['AC Servicing', 'ac-servicing', 'bi-snow', 599, 'Service, gas refill, not cooling, leaks', 90],
        ['Appliance Repair', 'appliance-repair', 'bi-tv-fill', 449, 'Fridge, washing machine, microwave, TV', 75],
    ];
    $st = $db->prepare("INSERT INTO services (name, slug, icon, base_price, description, duration_min) VALUES (?,?,?,?,?,?)");
    foreach ($services as $s) { $st->bind_param('sssisi', ...$s); $st->execute(); }
    $basePrice = [1 => 349, 2 => 299, 3 => 999, 4 => 599, 5 => 449];

    // ---------------- users ----------------
    $demoHash = password_hash('demo123', PASSWORD_DEFAULT);
    $adminHash = password_hash('admin123', PASSWORD_DEFAULT);
    $insUser = $db->prepare("INSERT INTO users (name, email, phone, password_hash, role, area, created_at) VALUES (?,?,?,?,?,?, NOW() - INTERVAL 60 DAY)");
    $addUser = function ($name, $email, $phone, $hash, $role, $area) use ($insUser, $db) {
        $insUser->bind_param('ssssss', $name, $email, $phone, $hash, $role, $area);
        $insUser->execute();
        return $db->insert_id;
    };
    $addUser('Admin', 'admin@homeease.in', '9000000000', $adminHash, 'admin', 'Yelahanka');

    $customers = [
        ['Priya Sharma', 'Koramangala'], ['Arjun Rao', 'Whitefield'], ['Neha Gupta', 'Yelahanka'], ['Rahul Verma', 'Jayanagar'],
        ['Sana Khan', 'Hebbal'], ['Vikram Iyer', 'Indiranagar'], ['Ananya Das', 'Marathahalli'], ['Rohit Nair', 'Electronic City'],
        ['Kavya Reddy', 'Rajajinagar'], ['Aditya Menon', 'Koramangala'], ['Meghana S', 'Whitefield'], ['Farhan Ali', 'Hebbal'],
        ['Divya Prakash', 'Jayanagar'], ['Karthik M', 'Yelahanka'], ['Sneha Joshi', 'Indiranagar'], ['Zoya Siddiqui', 'Marathahalli'],
        ['Harish Kumar', 'Electronic City'], ['Pooja Hegde', 'Rajajinagar'], ['Nikhil Jain', 'Whitefield'], ['Ayesha Banu', 'Koramangala'],
    ];
    $custIds = [];
    foreach ($customers as $i => [$name, $area]) {
        $email = strtolower(strtok($name, ' ')) . '@homeease.in';
        $custIds[] = ['id' => $addUser($name, $email, '98' . str_pad((string)(45000000 + $i * 7919), 8, '0'), $demoHash, 'customer', $area), 'area' => $area];
    }
    $log[] = count($customers) . " customers + 1 admin created";

    // ---------------- technicians ----------------
    // name, area, [service ids], rating, experience, available
    $techs = [
        ['Ravi Kumar', 'Koramangala', [1], 4.8, 8, 1, 94],
        ['Suresh Gowda', 'Yelahanka', [1, 5], 4.6, 6, 1, 85],
        ['Imran Pasha', 'Whitefield', [2, 4], 4.7, 7, 1, 88],
        ['Manjunath B', 'Jayanagar', [2], 4.5, 10, 1, 92],
        ['Deepak Singh', 'Hebbal', [4], 4.9, 9, 1, 96],
        ['Anil Reddy', 'Marathahalli', [4, 5], 4.4, 4, 1, 72],
        ['Lakshmi N', 'Indiranagar', [3], 4.8, 5, 1, 86],
        ['Fatima Begum', 'Electronic City', [3], 4.6, 3, 1, 68],
        ['Kiran Patil', 'Rajajinagar', [1, 2], 4.3, 2, 1, 74],
        ['Vinod Shetty', 'Whitefield', [5], 4.7, 11, 1, 93],
        ['Arif Khan', 'Yelahanka', [2, 4], 4.5, 5, 0, 81],
        ['Meena Joseph', 'Koramangala', [3], 4.7, 6, 1, 84],
    ];
    $insTech = $db->prepare("INSERT INTO technicians (user_id, area, rating, experience_years, available, quiz_score, skill_tier, emergency_phone) VALUES (?,?,?,?,?,?,?,?)");
    $insTS = $db->prepare("INSERT INTO technician_services (technician_id, service_id) VALUES (?,?)");
    $techBySvc = [];
    $techMeta = [];
    foreach ($techs as $i => [$name, $area, $svcs, $rating, $exp, $avail, $quiz]) {
        $email = strtolower(strtok($name, ' ')) . '@homeease.in';
        $uid = $addUser($name, $email, '97' . str_pad((string)(31000000 + $i * 6271), 8, '0'), $demoHash, 'provider', $area);
        $tier = $quiz > 90 ? 'certified' : ($quiz >= 80 ? 'experienced' : 'beginner'); // quiz tiers: 60-79, 80-90, >90
        $ephone = '96' . str_pad((string)(52000000 + $i * 4513), 8, '0');
        $insTech->bind_param('isdiiiss', $uid, $area, $rating, $exp, $avail, $quiz, $tier, $ephone);
        $insTech->execute();
        $tid = $db->insert_id;
        foreach ($svcs as $sid) { $insTS->bind_param('ii', $tid, $sid); $insTS->execute(); $techBySvc[$sid][] = $tid; }
        $techMeta[$tid] = ['area' => $area, 'rating' => $rating];
    }
    $log[] = count($techs) . " technicians created";

    // ---------------- bookings (synthetic history, 45 days) ----------------
    mt_srand(2026);
    $dowFactor = [1 => 0.85, 2 => 0.8, 3 => 0.85, 4 => 0.95, 5 => 1.15, 6 => 1.6, 7 => 1.45];
    $svcWeights = [1 => 24, 2 => 20, 3 => 15, 4 => 26, 5 => 15];
    $pickWeighted = function (array $w) {
        $r = mt_rand(1, array_sum($w));
        foreach ($w as $k => $v) { $r -= $v; if ($r <= 0) return $k; }
        return array_key_first($w);
    };
    $hourWeights = [8 => 3, 9 => 6, 10 => 9, 11 => 9, 12 => 7, 13 => 5, 14 => 5, 15 => 5, 16 => 6, 17 => 7, 18 => 9, 19 => 8, 20 => 5, 21 => 2];
    $descs = [
        1 => ['Kitchen tap leaking continuously', 'Bathroom drain blocked', 'Toilet flush not working', 'Geyser pipe leaking', 'Low water pressure in shower'],
        2 => ['Fan making noise and slow', 'Switchboard sparking', 'MCB keeps tripping', 'Need 3 new light points', 'Inverter not charging'],
        3 => ['Full home deep cleaning', 'Kitchen grease cleaning', 'Sofa shampoo cleaning', 'Bathroom deep clean', 'Move-in cleaning 2BHK'],
        4 => ['AC not cooling properly', 'Split AC annual service', 'AC water dripping inside', 'Gas refill needed', 'AC making noise'],
        5 => ['Fridge not cooling', 'Washing machine not spinning', 'Microwave not heating', 'TV no display', 'RO purifier leaking'],
    ];
    $insB = $db->prepare("INSERT INTO bookings (customer_id, service_id, technician_id, area, address, description, urgency, scheduled_at,
        price_base, price_urgency, price_peak, price_demand, price_fee, price_total, match_score, ai_source, status, otp, rating, review, created_at, completed_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,0,29,?,?,?,?,?,?,?,?,?)");
    $reviews = [5 => ['Excellent work, very professional', 'Quick and neat job', 'On time and polite'], 4 => ['Good service', 'Solved the issue', 'Decent, slightly late'], 3 => ['Okay, took longer than expected']];
    $count = 0;
    for ($d = 45; $d >= 0; $d--) {
        $date = date('Y-m-d', strtotime("-$d day"));
        $dow = (int)date('N', strtotime($date));
        $expected = (4.5 + (45 - $d) * 0.09) * $dowFactor[$dow];
        $n = max(1, (int)round($expected + mt_rand(-15, 15) / 10));
        if ($d === 0) $n = max(2, (int)round($n * (int)date('G') / 22)); // today: partial day
        for ($k = 0; $k < $n; $k++) {
            $w = $svcWeights;
            if ($d <= 7) $w[4] += 8; // AC demand rising this week -> visible insight
            $sid = $pickWeighted($w);
            $c = $custIds[mt_rand(1, count($custIds) - 1)]; // Priya (index 0) only gets hand-made demo bookings
            $cands = $techBySvc[$sid];
            usort($cands, fn($a, $b) => (distance_km($c['area'], $techMeta[$a]['area']) <=> distance_km($c['area'], $techMeta[$b]['area'])));
            $tid = $cands[mt_rand(0, 9) < 7 ? 0 : mt_rand(0, count($cands) - 1)];
            $hour = $pickWeighted($hourWeights);
            $created = "$date " . str_pad((string)$hour, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)mt_rand(0, 59), 2, '0', STR_PAD_LEFT) . ':00';
            if (strtotime($created) > time()) $created = date('Y-m-d H:i:s', time() - mt_rand(600, 5400));
            $urg = ['normal', 'normal', 'normal', 'normal', 'same_day', 'same_day', 'emergency'][mt_rand(0, 6)];
            $sched = date('Y-m-d H:i:s', strtotime($created) + ($urg === 'emergency' ? 7200 : ($urg === 'same_day' ? 14400 : 86400)));
            $base = $basePrice[$sid];
            $pu = (int)round($base * URGENCY_PCT_SEED[$urg] / 100);
            $sh = (int)date('G', strtotime($sched));
            $pp = ($sh >= 18 && $sh <= 21) || (int)date('N', strtotime($sched)) >= 6 ? (int)round($base * 0.1) : 0;
            $total = $base + $pu + $pp + 29;
            $age = (time() - strtotime($created)) / 3600;
            if ($age > 30) $status = mt_rand(1, 100) <= 94 ? 'completed' : 'cancelled';
            else $status = ['pending', 'accepted', 'in_progress', 'completed'][min(3, (int)floor($age / 6) + mt_rand(0, 1))];
            $rating = null; $review = null; $done = null;
            if ($status === 'completed') {
                $done = date('Y-m-d H:i:s', min(time(), strtotime($sched) + 5400));
                $rating = max(3, min(5, (int)round($techMeta[$tid]['rating'] + mt_rand(-10, 6) / 10)));
                $review = mt_rand(0, 2) ? $reviews[$rating][mt_rand(0, count($reviews[$rating]) - 1)] : null;
            }
            $desc = $descs[$sid][mt_rand(0, 4)];
            $addr = mt_rand(10, 999) . ', ' . $c['area'] . ', Bengaluru';
            $score = mt_rand(72, 96);
            $src = ['text', 'text', 'photo', 'manual'][mt_rand(0, 3)];
            $otp = (string)mt_rand(100000, 999999);
            $insB->bind_param('iiisssssiiiiisssisss', $c['id'], $sid, $tid, $c['area'], $addr, $desc, $urg, $sched,
                $base, $pu, $pp, $total, $score, $src, $status, $otp, $rating, $review, $created, $done);
            $insB->execute();
            $count++;
        }
    }

    // ---------------- hand-made demo bookings ----------------
    $priya = $custIds[0]['id']; // Koramangala
    $demo = [
        // [customer, service, tech, description, status, created offset, urgency, scheduled]
        'ac'    => [$priya, 4, 5, 'Split AC annual service', 'completed', '-130 day', 'normal', null],      // triggers maintenance reminder
        'tap'   => [$priya, 1, 1, 'Bathroom tap leaking', 'completed', '-9 day', 'normal', null],            // has an active 30-day warranty
        'fan'   => [$priya, 2, 4, 'Bedroom fan making noise', 'accepted', '-3 hour', 'same_day', null],
        'drain' => [$custIds[9]['id'], 1, 1, 'Kitchen sink drain blocked', 'pending', '-40 minute', 'normal', 'tomorrow 15:00'],
        'gey'   => [$custIds[19]['id'], 1, 1, 'Geyser pipe leaking near valve', 'pending', '-15 minute', 'normal', 'tomorrow 17:00'],
        'neg1'  => [$priya, 1, 1, 'Replace kitchen sink mixer tap', 'negotiating', '-25 minute', 'normal', 'tomorrow 12:00'],
        'neg2'  => [$custIds[9]['id'], 1, 1, 'Bathroom shower has low pressure', 'negotiating', '-10 minute', 'normal', 'tomorrow 13:00'],
    ];
    $demoIds = [];
    foreach ($demo as $key => [$cid, $sid, $tid, $desc, $status, $off, $urg, $schedAt]) {
        $created = date('Y-m-d H:i:s', strtotime($off));
        $sched = $schedAt ? date('Y-m-d H:i:s', strtotime($schedAt)) : date('Y-m-d H:i:s', strtotime($created) + 10800);
        $base = $basePrice[$sid];
        $pu = (int)round($base * URGENCY_PCT_SEED[$urg] / 100);
        $pp = 0; $total = $base + $pu + 29; $score = 93; $src = 'text'; $otp = (string)mt_rand(100000, 999999);
        $area = 'Koramangala'; $addr = '12, 5th Block, Koramangala, Bengaluru';
        $done = $status === 'completed' ? date('Y-m-d H:i:s', strtotime($sched) + 3600) : null;
        $rating = $status === 'completed' ? 5 : null; $review = $status === 'completed' ? 'Very professional, fixed it quickly' : null;
        $insB->bind_param('iiisssssiiiiisssisss', $cid, $sid, $tid, $area, $addr, $desc, $urg, $sched,
            $base, $pu, $pp, $total, $score, $src, $status, $otp, $rating, $review, $created, $done);
        $insB->execute();
        $demoIds[$key] = $db->insert_id;
        $count++;
    }

    // ---------------- derived state: price range, escrow, shields, warranties, wallets ----------------
    $db->query("UPDATE bookings SET est_min = ROUND(price_total * 0.90), est_max = ROUND(price_total * 1.15),
        price_locked = (status <> 'negotiating'),
        escrow_status = CASE status WHEN 'completed' THEN 'released' WHEN 'cancelled' THEN 'refunded' WHEN 'negotiating' THEN 'unpaid' ELSE 'held' END,
        customer_confirmed = (status = 'completed'),
        started_at = IF(status IN ('in_progress','completed'), scheduled_at, NULL)");
    $db->query("INSERT INTO safety_shields (booking_id, technician_id, policy_no, status, activated_at)
        SELECT id, technician_id, CONCAT('HES-', DATE_FORMAT(created_at, '%y%m'), '-', LPAD(id, 5, '0')), IF(status = 'accepted', 'outbound', 'on_job'), created_at
        FROM bookings WHERE status IN ('accepted','in_progress')");
    $db->query("INSERT INTO warranties (booking_id, code, customer_id, technician_id, service_id, valid_from, valid_until, status)
        SELECT id, CONCAT('HEW-', UPPER(SUBSTRING(MD5(CONCAT(id, otp)), 1, 8))), customer_id, technician_id, service_id, DATE(completed_at), DATE(completed_at) + INTERVAL 30 DAY, 'active'
        FROM bookings WHERE status = 'completed' AND completed_at >= NOW() - INTERVAL 30 DAY");
    $db->query("UPDATE technicians t SET wallet_balance = (SELECT COALESCE(SUM(price_total - 29), 0) FROM bookings b
        WHERE b.technician_id = t.id AND b.status = 'completed' AND b.completed_at >= NOW() - INTERVAL 30 DAY)");

    // negotiation threads: one where the technician quoted unusually high, one waiting for the technician
    $insN = $db->prepare("INSERT INTO price_negotiations (booking_id, sender, amount, fairness, message, status, created_at) VALUES (?,?,?,?,?,?, NOW() - INTERVAL ? MINUTE)");
    $threads = [
        [$demoIds['neg1'], 'ai', 378, 'within', 'AI smart price ₹378. Fair range ₹340 to ₹435.', 'info', 25],
        [$demoIds['neg1'], 'provider', 650, 'high', 'Mixer tap replacement needs extra fittings and sealant.', 'proposed', 12],
        [$demoIds['neg2'], 'ai', 378, 'within', 'AI smart price ₹378. Fair range ₹340 to ₹435.', 'info', 10],
        [$demoIds['neg2'], 'customer', 350, 'within', 'Can you do it for 350? It is a small job.', 'proposed', 9],
    ];
    foreach ($threads as $t) { $insN->bind_param('isisssi', ...$t); $insN->execute(); }

    // simulated weather outlook for the next 7 days (heavy rain on day 2 and 3)
    $insW = $db->prepare("INSERT INTO weather_logs (forecast_date, cond) VALUES (?, ?)");
    for ($h = 1; $h <= 7; $h++) {
        $wd = date('Y-m-d', strtotime("+$h day")); $wc = in_array($h, [2, 3], true) ? 'rain' : 'normal';
        $insW->bind_param('ss', $wd, $wc); $insW->execute();
    }
    $db->query("UPDATE users SET is_premium = 1, premium_until = CURDATE() + INTERVAL 30 DAY WHERE email = 'aditya@homeease.in'");
    $db->query("UPDATE technicians t SET jobs_done = 40 + (SELECT COUNT(*) FROM bookings b WHERE b.technician_id = t.id AND b.status = 'completed')");
    $log[] = "$count bookings generated (45 days of history with weekly seasonality and growth)";

    echo page('Setup complete.', $log);
} catch (Throwable $ex) {
    http_response_code(500);
    echo page('<span class="text-danger">Setup failed:</span> ' . htmlspecialchars($ex->getMessage()), $log);
}


function distance_km(string $a, string $b): float {
    static $c = ['Yelahanka' => [13.1007, 77.5963], 'Hebbal' => [13.0358, 77.5970], 'Rajajinagar' => [12.9915, 77.5554],
        'Indiranagar' => [12.9784, 77.6408], 'Whitefield' => [12.9698, 77.7500], 'Marathahalli' => [12.9569, 77.7011],
        'Koramangala' => [12.9352, 77.6245], 'Jayanagar' => [12.9250, 77.5938], 'Electronic City' => [12.8452, 77.6602]];
    if ($a === $b) return 1.5;
    [$x1, $y1] = $c[$a]; [$x2, $y2] = $c[$b];
    return hypot($x1 - $x2, $y1 - $y2) * 111;
}

function page(string $msg, array $log): string {
    $items = implode('', array_map(fn($l) => "<li>✔ " . htmlspecialchars($l) . "</li>", $log));
    return "<!doctype html><meta charset='utf-8'><title>HomeEase setup</title>
    <body style='font-family:system-ui;max-width:640px;margin:60px auto;padding:0 20px;color:#123C42'>
    <h2>🏠 HomeEase AI setup</h2><p>$msg</p><ul style='line-height:1.9'>$items</ul>
    <h4>Demo logins (password: demo123)</h4>
    <ul><li>Customer: <b>priya@homeease.in</b> (free plan, can upgrade)</li><li>Premium customer: <b>aditya@homeease.in</b></li><li>Technician: <b>ravi@homeease.in</b></li><li>Admin: <b>admin@homeease.in</b> / <b>admin123</b></li></ul>
    <p><a href='index.php' style='background:#E8582F;color:#fff;padding:10px 18px;border-radius:30px;text-decoration:none'>Open HomeEase AI →</a></p>";
}
