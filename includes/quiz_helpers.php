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
 * Clean a fragment of HTML to allowed quiz/exam tags and attributes. Returns null on failure.
 *
 * @return string|null Safe HTML fragment (body inner HTML)
 */
function quiz_rich_clean_html_fragment(string $value): ?string {
  if ($value === '' || !class_exists('DOMDocument')) {
    return null;
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
      return null;
    }

    $cleanNode = function (DOMNode $node) use (&$cleanNode, $allowedTags, $allowedAttrs) {
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
      return null;
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
    return null;
  }
}

/**
 * Sanitize rich question HTML before storing in the database (same rules as quiz questions).
 */
function sanitizeQuizRichHtmlForStorage(string $value): string {
  $value = trim($value);
  if ($value === '') {
    return '';
  }
  if ($value === strip_tags($value)) {
    return $value;
  }
  $clean = quiz_rich_clean_html_fragment($value);
  if ($clean !== null && $clean !== '') {
    return $clean;
  }
  return trim(strip_tags($value));
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

  $html = quiz_rich_clean_html_fragment($value);
  if ($html !== null && $html !== '') {
    return $html;
  }

  return nl2br(h($value));
}