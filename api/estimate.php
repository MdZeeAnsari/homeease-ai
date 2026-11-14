<?php
// Live price estimate for the booking form.
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');
$sid = (int)($_GET['service_id'] ?? 0);
$urg = $_GET['urgency'] ?? 'normal';
$when = $_GET['when'] ?? date('Y-m-d H:i');
if (!isset(services()[$sid]) || !in_array($urg, BOOKABLE_URGENCY, true)) { echo json_encode(['ok' => false]); exit; }
$area = $_GET['area'] ?? null;
echo json_encode(['ok' => true] + estimate_price($sid, $urg, str_replace('T', ' ', $when), isset(AREAS[$area]) ? $area : null));
