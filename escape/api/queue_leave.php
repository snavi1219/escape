<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$user_key = trim((string)($_POST['user_key'] ?? ''));
if ($user_key === '') { http_response_code(400); echo j(['error'=>'user_key required']); exit; }

$st = $pdo->prepare("DELETE FROM escape_queue WHERE user_key=?");
$st->execute([$user_key]);

echo j(['ok'=>true, 'status'=>'left']);
