<?php
/**
 * Vimeo thumbnail resolution (oEmbed + v2 API fallback + file cache + parallel fetch).
 */

if (!function_exists('ereview_parse_vimeo_id')) {
    /**
     * Extract numeric Vimeo id from a URL, embed snippet, or plain text.
     *
     * @return non-empty-string|null
     */
    function ereview_parse_vimeo_id($url) {
        $raw = trim((string)$url);
        if ($raw === '') {
            return null;
        }
        if (strpos($raw, '<') !== false) {
            if (preg_match('/<iframe[^>]+src=["\']([^"\']+)["\']/i', $raw, $m)) {
                $raw = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } elseif (preg_match('/src=["\']([^"\']*player\.vimeo\.com[^"\']*)["\']/i', $raw, $m)) {
                $raw = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        $raw = trim($raw);
        if (preg_match('/vimeo\.com\/(?:video\/|channels\/[^\/]+\/|groups\/[^\/]+\/videos\/)?(\d+)/i', $raw, $m)) {
            return $m[1];
        }
        if (preg_match('/player\.vimeo\.com\/video\/(\d+)/i', $raw, $m)) {
            return $m[1];
        }
        if (preg_match('/vimeo\.com\/(\d+)/i', $raw, $m)) {
            return $m[1];
        }
        return null;
    }
}

/**
 * When the server cannot reach Vimeo oEmbed/API (firewall/SSL), students can still load a
 * public preview image in the browser. Not affiliated with Vimeo; used as last-resort URL.
 */
if (!function_exists('ereview_vimeo_thumbnail_proxy_url')) {
    function ereview_vimeo_thumbnail_proxy_url($vimeoId) {
        return 'https://vumbnail.com/' . rawurlencode((string)$vimeoId) . '.jpg';
    }
}

/** For DB / admin: distinguish real Vimeo CDN thumbs from browser-side proxy URLs. */
if (!function_exists('ereview_vimeo_thumbnail_storage_source')) {
    function ereview_vimeo_thumbnail_storage_source($resolvedUrl) {
        $u = trim((string)$resolvedUrl);
        if ($u === '') {
            return 'fallback';
        }
        if (stripos($u, 'vumbnail.com') !== false) {
            return 'vumbnail_proxy';
        }
        return 'vimeo_cdn';
    }
}

if (!function_exists('ereview_vimeo_thumb_cache_dir')) {
    function ereview_vimeo_thumb_cache_dir() {
        $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'vimeo_thumb';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return is_dir($dir) ? $dir : null;
    }
}

if (!function_exists('ereview_read_vimeo_thumb_cache')) {
    /**
     * @return non-empty-string|null
     */
    function ereview_read_vimeo_thumb_cache($vimeoId) {
        $ttl = 7 * 86400;
        $cacheDir = ereview_vimeo_thumb_cache_dir();
        if (!$cacheDir) {
            return null;
        }
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $vimeoId . '.json';
        if (!is_readable($cacheFile)) {
            return null;
        }
        $raw = @file_get_contents($cacheFile);
        if ($raw === false || $raw === '') {
            return null;
        }
        $cached = json_decode($raw, true);
        if (!is_array($cached) || empty($cached['thumbnail_url']) || !isset($cached['cached_at'])) {
            return null;
        }
        if ((time() - (int)$cached['cached_at']) >= $ttl) {
            return null;
        }
        $u = trim((string)$cached['thumbnail_url']);
        if ($u !== '' && strncmp($u, 'http', 4) === 0) {
            return preg_replace('/^http:\/\//i', 'https://', $u);
        }
        return null;
    }
}

if (!function_exists('ereview_write_vimeo_thumb_cache')) {
    function ereview_write_vimeo_thumb_cache($vimeoId, $thumbnailUrl) {
        $cacheDir = ereview_vimeo_thumb_cache_dir();
        if (!$cacheDir || !is_dir($cacheDir)) {
            return;
        }
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $vimeoId . '.json';
        @file_put_contents(
            $cacheFile,
            json_encode(['thumbnail_url' => $thumbnailUrl, 'cached_at' => time()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
}

if (!function_exists('ereview_http_get_json')) {
    /**
     * @return array<int|string,mixed>|null
     */
    function ereview_http_get_json($url, $timeout = 8) {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_USERAGENT => 'LCRC-eReview/1.0 (+thumbnail)',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code !== 200 || $body === false || $body === '') {
                return null;
            }
            $data = json_decode($body, true);
            return is_array($data) ? $data : null;
        }
        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'header' => "User-Agent: LCRC-eReview/1.0 (+thumbnail)\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false || $body === '') {
            return null;
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }
}

if (!function_exists('ereview_fetch_oembed_thumb_only')) {
    /**
     * oEmbed then v2; does not read/write cache (caller handles cache).
     *
     * @return non-empty-string|null
     */
    function ereview_fetch_oembed_thumb_only($vimeoId) {
        $oembed = 'https://vimeo.com/api/oembed.json?url=' . rawurlencode('https://vimeo.com/' . $vimeoId) . '&width=1280';
        $data = ereview_http_get_json($oembed, 8);
        if (is_array($data) && !empty($data['thumbnail_url']) && is_string($data['thumbnail_url'])) {
            $u = trim($data['thumbnail_url']);
            if ($u !== '' && strncmp($u, 'http', 4) === 0) {
                return preg_replace('/^http:\/\//i', 'https://', $u);
            }
        }
        return ereview_fetch_vimeo_thumb_v2($vimeoId);
    }
}

if (!function_exists('ereview_fetch_vimeo_thumb_v2')) {
    /**
     * Legacy public JSON endpoint (no OAuth); works for many public videos.
     *
     * @return non-empty-string|null
     */
    function ereview_fetch_vimeo_thumb_v2($vimeoId) {
        $url = 'https://vimeo.com/api/v2/video/' . rawurlencode((string)$vimeoId) . '.json';
        $data = ereview_http_get_json($url, 8);
        if (!is_array($data) || !isset($data[0]) || !is_array($data[0])) {
            return null;
        }
        $row = $data[0];
        foreach (['thumbnail_large', 'thumbnail_medium', 'thumbnail_small'] as $k) {
            if (!empty($row[$k]) && is_string($row[$k])) {
                $u = trim($row[$k]);
                if ($u !== '' && strncmp($u, 'http', 4) === 0) {
                    return preg_replace('/^http:\/\//i', 'https://', $u);
                }
            }
        }
        return null;
    }
}

if (!function_exists('ereview_resolve_vimeo_thumbnail_ids_parallel')) {
    /**
     * @param list<string> $vimeoIds
     * @return array<string, string> id => thumbnail URL (Vimeo CDN or vumbnail proxy)
     */
    function ereview_resolve_vimeo_thumbnail_ids_parallel(array $vimeoIds) {
        $vimeoIds = array_values(array_unique(array_filter($vimeoIds)));
        $out = [];
        $need = [];
        foreach ($vimeoIds as $id) {
            $cached = ereview_read_vimeo_thumb_cache($id);
            if ($cached !== null) {
                $out[$id] = $cached;
            } else {
                $need[] = $id;
            }
        }
        if ($need === []) {
            return $out;
        }
        if (function_exists('curl_multi_init')) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($need as $id) {
                $oembed = 'https://vimeo.com/api/oembed.json?url=' . rawurlencode('https://vimeo.com/' . $id) . '&width=1280';
                $ch = curl_init($oembed);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 12,
                    CURLOPT_CONNECTTIMEOUT => 6,
                    CURLOPT_USERAGENT => 'LCRC-eReview/1.0 (+thumbnail)',
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$id] = $ch;
            }
            $running = null;
            do {
                $stat = curl_multi_exec($mh, $running);
                if ($running > 0) {
                    curl_multi_select($mh, 0.15);
                }
            } while ($running > 0 && $stat === CURLM_OK);
            foreach ($handles as $id => $ch) {
                $body = curl_multi_getcontent($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                $thumb = null;
                if ($code === 200 && is_string($body) && $body !== '') {
                    $data = json_decode($body, true);
                    if (is_array($data) && !empty($data['thumbnail_url']) && is_string($data['thumbnail_url'])) {
                        $thumb = trim($data['thumbnail_url']);
                        if ($thumb !== '' && strncmp($thumb, 'http', 4) !== 0) {
                            $thumb = null;
                        }
                    }
                }
                if ($thumb === null) {
                    $thumb = ereview_fetch_vimeo_thumb_v2($id);
                }
                if ($thumb !== null) {
                    $out[$id] = $thumb;
                    ereview_write_vimeo_thumb_cache($id, $thumb);
                } else {
                    $proxy = ereview_vimeo_thumbnail_proxy_url($id);
                    $out[$id] = $proxy;
                    ereview_write_vimeo_thumb_cache($id, $proxy);
                }
            }
            curl_multi_close($mh);
            return $out;
        }
        foreach ($need as $id) {
            $thumb = ereview_fetch_oembed_thumb_only($id);
            if ($thumb !== null) {
                $out[$id] = $thumb;
                ereview_write_vimeo_thumb_cache($id, $thumb);
            } else {
                $proxy = ereview_vimeo_thumbnail_proxy_url($id);
                $out[$id] = $proxy;
                ereview_write_vimeo_thumb_cache($id, $proxy);
            }
        }
        return $out;
    }
}

if (!function_exists('ereview_lesson_vimeo_thumbnails_for_subject')) {
    /**
     * @return array<int, non-empty-string> lesson_id => thumbnail URL
     */
    function ereview_lesson_vimeo_thumbnails_for_subject($conn, $subjectId) {
        $subjectId = (int)$subjectId;
        $lessonIds = [];
        $res = mysqli_query($conn, 'SELECT lesson_id FROM lessons WHERE subject_id=' . $subjectId . ' ORDER BY lesson_id ASC');
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $lessonIds[] = (int)$row['lesson_id'];
            }
        }
        if ($lessonIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $lessonIds));
        $lessonToVimeo = [];
        $vimeoIds = [];
        $vq = mysqli_query($conn, 'SELECT lesson_id, video_url, video_id FROM lesson_videos WHERE lesson_id IN (' . $in . ') ORDER BY lesson_id ASC, video_id ASC');
        if ($vq) {
            while ($vr = mysqli_fetch_assoc($vq)) {
                $lid = (int)$vr['lesson_id'];
                if (isset($lessonToVimeo[$lid])) {
                    continue;
                }
                $url = !empty($vr['video_url']) ? (string)$vr['video_url'] : '';
                if ($url === '') {
                    continue;
                }
                if (stripos($url, 'uploads/videos/') === 0 || stripos($url, 'uploads\\videos\\') === 0) {
                    continue;
                }
                $vid = ereview_parse_vimeo_id($url);
                if ($vid !== null) {
                    $lessonToVimeo[$lid] = $vid;
                    $vimeoIds[$vid] = true;
                }
            }
        }
        if ($vimeoIds === []) {
            return [];
        }
        $resolved = ereview_resolve_vimeo_thumbnail_ids_parallel(array_keys($vimeoIds));
        $out = [];
        foreach ($lessonToVimeo as $lid => $vid) {
            $u = $resolved[$vid] ?? null;
            if (!is_string($u) || $u === '') {
                $u = ereview_vimeo_thumbnail_proxy_url($vid);
            }
            $out[$lid] = $u;
        }
        return $out;
    }
}

if (!function_exists('ereview_get_vimeo_thumbnail_for_url')) {
    /**
     * Single URL: cache + oEmbed + v2; if the server cannot reach Vimeo, returns a vumbnail.com URL
     * (loaded by the browser, not the server).
     *
     * @return non-empty-string|null null when the URL is not a recognizable Vimeo link
     */
    function ereview_get_vimeo_thumbnail_for_url($videoUrl) {
        $id = ereview_parse_vimeo_id($videoUrl);
        if ($id === null) {
            return null;
        }
        $cached = ereview_read_vimeo_thumb_cache($id);
        if ($cached !== null) {
            return $cached;
        }
        $map = ereview_resolve_vimeo_thumbnail_ids_parallel([$id]);
        $u = $map[$id] ?? null;
        if (!is_string($u) || $u === '') {
            $u = ereview_vimeo_thumbnail_proxy_url($id);
            ereview_write_vimeo_thumb_cache($id, $u);
        }
        return $u;
    }
}
