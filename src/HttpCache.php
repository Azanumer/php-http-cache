<?php
/**
 * HttpCache
 *
 * A small cache-header builder for plain PHP pages and endpoints.
 *
 * - PHP 7.4+ compatible, zero dependencies
 * - Fluent API: $cache->setMaxAge(300)->setEtag($body)->sendHeaders();
 * - checkConditional() answers If-None-Match / If-Modified-Since with a
 *   "304 Not Modified" — it returns true so the caller can return early,
 *   it never calls exit().
 *
 * IMPORTANT: call checkConditional() and sendHeaders() BEFORE any output
 * (echo, whitespace, HTML). If headers were already sent, sendHeaders()
 * returns false and checkConditional() returns false instead of failing.
 */

class HttpCache
{
    /** @var int|null */
    private $maxAge;

    /** @var int|null */
    private $sharedMaxAge;

    /** @var bool */
    private $isPrivate = false;

    /** @var bool */
    private $noCacheFlag = false;

    /** @var bool */
    private $noStoreFlag = false;

    /** @var string|null Quoted ETag, e.g. "abc123" */
    private $etag;

    /** @var int|null */
    private $lastModified;

    /** @var array */
    private $vary = array();

    /** @var int|null */
    private $expires;

    /**
     * Cache-Control: max-age=N (browser cache, seconds).
     */
    public function setMaxAge($seconds)
    {
        $this->maxAge = max(0, (int) $seconds);
        return $this;
    }

    /**
     * Cache-Control: s-maxage=N (shared/CDN cache, seconds).
     */
    public function setSharedMaxAge($seconds)
    {
        $this->sharedMaxAge = max(0, (int) $seconds);
        return $this;
    }

    /**
     * Cache-Control: private (only the browser may cache, not CDNs).
     */
    public function setPrivate()
    {
        $this->isPrivate = true;
        return $this;
    }

    /**
     * Cache-Control: no-cache (revalidate every time, may still store).
     */
    public function noCache()
    {
        $this->noCacheFlag = true;
        return $this;
    }

    /**
     * Cache-Control: no-store (never store the response anywhere).
     * Overrides every other directive.
     */
    public function noStore()
    {
        $this->noStoreFlag = true;
        return $this;
    }

    /**
     * Set the ETag. Pass raw content (it gets hashed) or an already
     * computed hash/tag. The value is always sent quoted, per RFC 7232.
     */
    public function setEtag($contentOrHash)
    {
        $tag = (string) $contentOrHash;
        if (strlen($tag) >= 2 && $tag[0] === '"' && substr($tag, -1) === '"') {
            $this->etag = $tag; // already quoted
        } elseif (preg_match('/^[0-9a-f]{16,}$/i', $tag)) {
            $this->etag = '"' . $tag . '"'; // looks like a hash
        } else {
            $this->etag = '"' . md5($tag) . '"'; // raw content → hash it
        }
        return $this;
    }

    /**
     * Set Last-Modified from a Unix timestamp.
     */
    public function setLastModified($unixTime)
    {
        $this->lastModified = (int) $unixTime;
        return $this;
    }

    /**
     * Vary: fields, e.g. setVary('Accept-Encoding', 'Cookie').
     */
    public function setVary(...$fields)
    {
        foreach ($fields as $field) {
            $field = trim((string) $field);
            if ($field !== '' && !in_array($field, $this->vary, true)) {
                $this->vary[] = $field;
            }
        }
        return $this;
    }

    /**
     * Expires from a Unix timestamp.
     */
    public function setExpires($unixTime)
    {
        $this->expires = (int) $unixTime;
        return $this;
    }

    /**
     * Emit Cache-Control, ETag, Last-Modified, Expires and Vary.
     *
     * @return bool False when headers were already sent (nothing emitted).
     */
    public function sendHeaders()
    {
        if (headers_sent()) {
            return false;
        }
        $cacheControl = $this->buildCacheControl();
        if ($cacheControl !== '') {
            header('Cache-Control: ' . $cacheControl);
        }
        if ($this->etag !== null) {
            header('ETag: ' . $this->etag);
        }
        if ($this->lastModified !== null) {
            header('Last-Modified: ' . self::httpDate($this->lastModified));
        }
        if ($this->expires !== null) {
            header('Expires: ' . self::httpDate($this->expires));
        }
        if (!empty($this->vary)) {
            header('Vary: ' . implode(', ', $this->vary));
        }
        return true;
    }

    /**
     * Answer conditional requests. Compares If-None-Match against the ETag
     * set via setEtag(), and If-Modified-Since against the Last-Modified
     * set via setLastModified().
     *
     * On a match it sends "HTTP/1.1 304 Not Modified" and returns true —
     * the caller should then return early WITHOUT echoing the body.
     * It never calls exit(). Returns false when nothing matched, when no
     * validators were set, or when headers were already sent.
     */
    public function checkConditional()
    {
        if (headers_sent()) {
            return false;
        }
        $notModified = false;

        if ($this->etag !== null && isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
            $notModified = $notModified || $this->etagMatches($_SERVER['HTTP_IF_NONE_MATCH']);
        }

        if (!$notModified
            && $this->lastModified !== null
            && isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])
        ) {
            $since = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
            if ($since !== false && $since >= $this->lastModified) {
                $notModified = true;
            }
        }

        if ($notModified) {
            header('HTTP/1.1 304 Not Modified');
            return true;
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function buildCacheControl()
    {
        if ($this->noStoreFlag) {
            return 'no-store';
        }
        $parts = array();
        if ($this->noCacheFlag) {
            $parts[] = 'no-cache';
        }
        if ($this->isPrivate) {
            $parts[] = 'private';
        } elseif ($this->maxAge !== null || $this->sharedMaxAge !== null) {
            $parts[] = 'public';
        }
        if ($this->maxAge !== null) {
            $parts[] = 'max-age=' . $this->maxAge;
        }
        if ($this->sharedMaxAge !== null) {
            $parts[] = 's-maxage=' . $this->sharedMaxAge;
        }
        return implode(', ', $parts);
    }

    /**
     * Does an If-None-Match header value match our ETag?
     * Handles "*" and comma-separated lists; ignores weak (W/) prefixes.
     */
    private function etagMatches($ifNoneMatch)
    {
        $ifNoneMatch = trim($ifNoneMatch);
        if ($ifNoneMatch === '*') {
            return true;
        }
        $ours = $this->etag; // always a strong quoted tag, e.g. "abc123"
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if (strpos($candidate, 'W/') === 0) {
                $candidate = substr($candidate, 2);
            }
            if ($candidate !== ''
                && strlen($candidate) === strlen($ours)
                && hash_equals($ours, $candidate)
            ) {
                return true;
            }
        }
        return false;
    }

    private static function httpDate($unixTime)
    {
        return gmdate('D, d M Y H:i:s', $unixTime) . ' GMT';
    }
}
