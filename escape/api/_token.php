<?php
declare(strict_types=1);

/**
 * api/_token.php
 * - Token format: base64url(payload_json) . "." . base64url(binary_hmac_sha256(payload_json))
 * - payload_json includes: ts, exp, user_key, scope, nonce (etc)
 */

function b64url_encode(string $bin): string {
  return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function b64url_decode(string $s): string {
  $s = strtr($s, '-_', '+/');
  $pad = strlen($s) % 4;
  if ($pad) $s .= str_repeat('=', 4 - $pad);
  $out = base64_decode($s, true);
  return $out === false ? '' : $out;
}

function token_secret(): string {
  // 운영에서는 ENV로 주입 권장
  $sec = getenv('ESCAPE_TOKEN_SECRET');
  if (is_string($sec) && $sec !== '') return $sec;

  // fallback (반드시 운영에서 교체)
  return 'CHANGE_ME__ESCAPE_TOKEN_SECRET__32CHARS_MIN';
}

function token_sign(array $payload): string {
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) $json = '{}';

  $sig = hash_hmac('sha256', $json, token_secret(), true); // ✅ binary HMAC
  return b64url_encode($json) . '.' . b64url_encode($sig);
}

function token_verify(string $token): array {
  $token = trim($token);
  if ($token === '' || strpos($token, '.') === false) return ['ok'=>false, 'err'=>'bad_token'];

  [$p1, $p2] = explode('.', $token, 2);
  $json = b64url_decode($p1);
  $sig  = b64url_decode($p2);
  if ($json === '' || $sig === '') return ['ok'=>false, 'err'=>'bad_token'];

  $expected = hash_hmac('sha256', $json, token_secret(), true);
  if (!hash_equals($expected, $sig)) return ['ok'=>false, 'err'=>'bad_sig'];

  $data = json_decode($json, true);
  if (!is_array($data)) return ['ok'=>false, 'err'=>'bad_payload'];

  $now = time();
  if (isset($data['exp']) && is_int($data['exp']) && $data['exp'] < $now) return ['ok'=>false, 'err'=>'expired'];
  if (isset($data['ts'])  && is_int($data['ts'])  && $data['ts']  > $now + 30) return ['ok'=>false, 'err'=>'future_ts'];

  return ['ok'=>true, 'payload'=>$data];
}
