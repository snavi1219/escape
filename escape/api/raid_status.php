<?php
// /escape/api/raid_status.php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/raid_core.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user_key = require_user_key();

// escape_raid_state는 유저별 1행 구조
$st = $pdo->prepare("SELECT status FROM escape_raid_state WHERE user_key=? LIMIT 1");
$st->execute([$user_key]);
$row = $st->fetch(PDO::FETCH_ASSOC);

$status = $row['status'] ?? 'idle'; // 없으면 idle로 간주
json_out(['ok'=>true, 'status'=>$status]);
