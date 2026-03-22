<?php
/**
 * Format total seconds as "1 hour 3 mins 2 seconds" (only non-zero parts).
 * Used for quiz time limit display on admin and student sides.
 */
function formatTimeLimitSeconds($totalSeconds) {
  $s = (int) $totalSeconds;
  if ($s <= 0) return '0 seconds';
  $hours = floor($s / 3600);
  $mins = floor(($s % 3600) / 60);
  $secs = $s % 60;
  $parts = [];
  if ($hours > 0) $parts[] = $hours . ' hour' . ($hours !== 1 ? 's' : '');
  if ($mins > 0) $parts[] = $mins . ' min' . ($mins !== 1 ? 's' : '');
  if ($secs > 0) $parts[] = $secs . ' second' . ($secs !== 1 ? 's' : '');
  return implode(' ', $parts);
}

/**
 * Get quiz time limit in seconds (from time_limit_seconds or legacy time_limit_minutes).
 */
function getQuizTimeLimitSeconds($quizRow) {
  if (isset($quizRow['time_limit_seconds']) && (int)$quizRow['time_limit_seconds'] > 0) {
    return (int) $quizRow['time_limit_seconds'];
  }
  $mins = (int)($quizRow['time_limit_minutes'] ?? 30);
  return $mins * 60;
}

/**
 * Render safe quiz HTML with support for table markup.
 * Allows a small whitelist of tags and strips unsafe attributes.
 */
function renderQuizRichText($value) {
  $value = (string)$value;
  if ($value === '') return '';

  // Keep plain text friendly (line breaks preserved) when no HTML tags are used.
  if ($value === strip_tags($value)) {
    return nl2br(h($value));
  }

  if (!class_exists('DOMDocument')) {
    return nl2br(h($value));
  }

  $allowedTags = [
    'p','br','strong','b','em','i','u','sub','sup',
    'ul','ol','li','table','thead','tbody','tfoot','tr','th','td'
  ];
  $allowedAttrs = [
    'th' => ['colspan','rowspan','scope'],
    'td' => ['colspan','rowspan'],
  ];

  try {
    $dom = new DOMDocument();
    $previousUseInternalErrors = libxml_use_internal_errors(true);
    $wrappedHtml = '<!DOCTYPE html><html><body>' . $value . '</body></html>';
    $loaded = @$dom->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previousUseInternalErrors);
    if (!$loaded) {
      return nl2br(h($value));
    }

    $cleanNode = function (DOMNode $node) use (&$cleanNode, $allowedTags, $allowedAttrs, $dom) {
      if ($node->nodeType === XML_ELEMENT_NODE) {
        $tag = strtolower($node->nodeName);
        if (!in_array($tag, $allowedTags, true)) {
          if ($node->parentNode) {
            while ($node->firstChild) {
              $node->parentNode->insertBefore($node->firstChild, $node);
            }
            $node->parentNode->removeChild($node);
          }
          return;
        }

        if ($node->hasAttributes()) {
          $toRemove = [];
          foreach ($node->attributes as $attr) {
            $name = strtolower($attr->name);
            $allowedForTag = $allowedAttrs[$tag] ?? [];
            if (!in_array($name, $allowedForTag, true)) {
              $toRemove[] = $attr->name;
            }
          }
          foreach ($toRemove as $attrName) {
            $node->removeAttribute($attrName);
          }
        }
      }

      for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
        $cleanNode($node->childNodes->item($i));
      }
    };

    $body = $dom->getElementsByTagName('body')->item(0);
    if (!$body) {
      return nl2br(h($value));
    }
    for ($i = $body->childNodes->length - 1; $i >= 0; $i--) {
      $cleanNode($body->childNodes->item($i));
    }

    $html = '';
    foreach ($body->childNodes as $child) {
      $html .= $dom->saveHTML($child);
    }
    return $html;
  } catch (Throwable $e) {
    return nl2br(h($value));
  }
}