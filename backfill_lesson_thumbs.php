<?php
/**
 * CLI: fill lesson_videos.thumbnail_url when empty (Vimeo CDN or vumbnail proxy if server cannot reach Vimeo).
 * Usage: php backfill_lesson_thumbs.php
 */
require __DIR__ . '/db.php';
require __DIR__ . '/includes/vimeo_helpers.php';

$q = mysqli_query($conn, "
  SELECT video_id, video_url
  FROM lesson_videos
  WHERE (thumbnail_url IS NULL OR thumbnail_url = '')
  ORDER BY video_id ASC
");

$total = 0;
$updated = 0;
$failed = 0;

while ($q && ($row = mysqli_fetch_assoc($q))) {
    $total++;
    $videoId = (int)$row['video_id'];
    $url = (string)$row['video_url'];

    $thumb = ereview_get_vimeo_thumbnail_for_url($url);
    $thumb = is_string($thumb) ? trim($thumb) : '';
    $source = ($thumb !== '') ? ereview_vimeo_thumbnail_storage_source($thumb) : 'fallback';

    $st = mysqli_prepare($conn, "
      UPDATE lesson_videos
      SET thumbnail_url=?, thumbnail_source=?, thumbnail_updated_at=NOW()
      WHERE video_id=?
    ");
    if ($st) {
        mysqli_stmt_bind_param($st, 'ssi', $thumb, $source, $videoId);
        if (mysqli_stmt_execute($st)) {
            if ($thumb !== '') {
                $updated++;
            } else {
                $failed++;
            }
        } else {
            $failed++;
        }
        mysqli_stmt_close($st);
    } else {
        $failed++;
    }
}

echo "Total rows: {$total}\nUpdated (non-empty thumb): {$updated}\nNo-thumb / fail: {$failed}\n";
