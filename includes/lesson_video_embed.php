<?php
/**
 * Normalize lesson video URLs for player rendering + activity tracking.
 *
 * @return array{type:string,embed_url:string,provider_id:string,is_trackable:bool}
 */
function ereview_lesson_video_embed(string $url): array
{
    $url = trim($url);
    $out = [
        'type' => 'other',
        'embed_url' => $url,
        'provider_id' => '',
        'is_trackable' => false,
    ];
    if ($url === '') {
        return $out;
    }

    // Local / uploaded MP4
    if (preg_match('#(?:^|/)uploads/videos/#i', $url) || preg_match('/\.mp4($|\?)/i', $url)) {
        $out['type'] = 'local';
        $out['embed_url'] = $url;
        $out['is_trackable'] = true;
        return $out;
    }

    // YouTube
    if (preg_match('~(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,})~i', $url, $m)) {
        $id = $m[1];
        $out['type'] = 'youtube';
        $out['provider_id'] = $id;
        $out['embed_url'] = 'https://www.youtube.com/embed/' . $id . '?rel=0&enablejsapi=1&origin=' . rawurlencode(ereview_lesson_video_origin());
        $out['is_trackable'] = true;
        return $out;
    }

    // Vimeo (numeric id, optional privacy hash)
    if (preg_match('~vimeo\.com/(?:video/)?(\d+)(?:/([a-zA-Z0-9]+))?~i', $url, $m)) {
        $id = $m[1];
        $hash = $m[2] ?? '';
        $embed = 'https://player.vimeo.com/video/' . $id;
        $qs = ['api' => '1', 'player_id' => 'vimeo_' . $id];
        if ($hash !== '') {
            $qs['h'] = $hash;
        }
        $out['type'] = 'vimeo';
        $out['provider_id'] = $id;
        $out['embed_url'] = $embed . '?' . http_build_query($qs);
        $out['is_trackable'] = true;
        return $out;
    }

    return $out;
}

function ereview_lesson_video_origin(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return ($https ? 'https' : 'http') . '://' . $host;
}

/**
 * Render trackable lesson video player markup.
 *
 * @param array<string,mixed> $video
 */
function ereview_render_lesson_video_player(array $video, float $resumeSec = 0.0): void
{
    $videoId = (int) ($video['video_id'] ?? 0);
    $info = ereview_lesson_video_embed((string) ($video['video_url'] ?? ''));
    $vidAttr = (string) $videoId;
    $resumeAttr = $resumeSec > 0 ? ' data-resume-sec="' . htmlspecialchars((string) round($resumeSec, 2), ENT_QUOTES, 'UTF-8') . '"' : '';

    if ($info['type'] === 'local') {
        echo '<video class="video-embed" controls playsinline data-video-id="' . htmlspecialchars($vidAttr, ENT_QUOTES, 'UTF-8') . '" data-activity-video="1"' . $resumeAttr . '>';
        echo '<source src="' . htmlspecialchars($info['embed_url'], ENT_QUOTES, 'UTF-8') . '" type="video/mp4">';
        echo 'Your browser does not support the video tag.</video>';
        return;
    }

    if ($info['type'] === 'youtube') {
        $embed = $info['embed_url'];
        if ($resumeSec > 0) {
            $embed .= (strpos($embed, '?') !== false ? '&' : '?') . 'start=' . (int) floor($resumeSec);
        }
        echo '<iframe class="video-embed" data-activity-youtube="1" data-video-id="' . htmlspecialchars($vidAttr, ENT_QUOTES, 'UTF-8') . '"' . $resumeAttr;
        echo ' src="' . htmlspecialchars($embed, ENT_QUOTES, 'UTF-8') . '"';
        echo ' allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
        return;
    }

    if ($info['type'] === 'vimeo') {
        echo '<iframe class="video-embed" data-activity-vimeo="1" data-video-id="' . htmlspecialchars($vidAttr, ENT_QUOTES, 'UTF-8') . '"' . $resumeAttr;
        echo ' src="' . htmlspecialchars($info['embed_url'], ENT_QUOTES, 'UTF-8') . '"';
        echo ' allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>';
        return;
    }

    echo '<iframe class="video-embed" src="' . htmlspecialchars($info['embed_url'], ENT_QUOTES, 'UTF-8') . '" allowfullscreen></iframe>';
}
