<?php
/**
 * HttpCache demo — a JSON endpoint with proper caching headers.
 *
 * Deploy as e.g. api/status.php and hit it twice with curl:
 *   curl -i https://example.com/api/status.php
 *   curl -i -H "If-None-Match: <etag from first response>" \
 *          https://example.com/api/status.php   → 304, no body
 *
 * IMPORTANT: checkConditional() and sendHeaders() must run BEFORE any
 * output (no echo/HTML/whitespace before them).
 */

require __DIR__ . '/../src/HttpCache.php';

// Build the payload first (in a real app: DB queries, rendering, …).
$payload = json_encode(array(
    'status' => 'ok',
    'time'   => time(),
    'visits' => 1234,
));

$cache = new HttpCache();
$cache->setMaxAge(300)            // browsers may cache 5 minutes
      ->setSharedMaxAge(600)      // CDNs/proxies may cache 10 minutes
      ->setEtag($payload)         // raw content → md5 tag, quoted
      ->setVary('Accept-Encoding');

// Conditional request? Send 304 and stop — no body, no wasted work.
if ($cache->checkConditional()) {
    return; // inside a plain script; in a function use `return`, never exit()
}

$cache->sendHeaders();

header('Content-Type: application/json; charset=utf-8');
echo $payload;
