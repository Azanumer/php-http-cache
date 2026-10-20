# php-http-cache

A cache-header builder for plain PHP pages and endpoints. PHP 7.4+, no
dependencies — one fluent class.

```php
$cache = new HttpCache();
$cache->setMaxAge(300)        // Cache-Control: max-age=300 (browser)
      ->setSharedMaxAge(600)  // s-maxage=600 (CDN / shared cache)
      ->setEtag($body)        // raw content → quoted md5 tag
      ->setVary('Accept-Encoding');

// Answer conditional requests with 304 — returns true, never exit()s.
if ($cache->checkConditional()) {
    return; // send nothing more; the client already has this version
}

$cache->sendHeaders();
header('Content-Type: application/json');
echo $body;
```

## Methods

| Method | Header produced |
|--------|-----------------|
| `setMaxAge($s)` | `Cache-Control: …, max-age=N` |
| `setSharedMaxAge($s)` | `Cache-Control: …, s-maxage=N` |
| `setPrivate()` | `Cache-Control: private` (browser only, no CDN caching) |
| `noCache()` / `noStore()` | `no-cache` / `no-store` (`no-store` wins) |
| `setEtag($contentOrHash)` | `ETag: "…"` — raw content is hashed, hashes are quoted as-is |
| `setLastModified($unixTime)` | `Last-Modified: <GMT date>` |
| `setVary(...$fields)` | `Vary: Accept-Encoding, …` |
| `setExpires($unixTime)` | `Expires: <GMT date>` |
| `sendHeaders()` | Emits everything; `false` if headers were already sent |
| `checkConditional()` | `true` + `HTTP/1.1 304 Not Modified` when `If-None-Match` matches the ETag or `If-Modified-Since` is fresh enough |

`checkConditional()` handles `If-None-Match: *`, comma-separated ETag
lists and weak (`W/`) validators; Last-Modified is compared at
one-second granularity.

## Rules

- Call `checkConditional()` and `sendHeaders()` **before any output**
  (echo, HTML, stray whitespace). Both degrade gracefully — they return
  `false` instead of crashing when `headers_sent()` is true.
- `checkConditional()` never calls `exit()`; the caller returns early so
  no body is generated or sent.

## Files

- `src/HttpCache.php` — the whole library (one class)
- `examples/http-cache-example.php` — JSON endpoint demo with curl
  instructions in the header comment

## Requirements

PHP 7.4+. No extensions, no Composer.

## License

MIT — see `LICENSE`.
