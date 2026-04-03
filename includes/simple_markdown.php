<?php
/**
 * Safe, limited Markdown for exam descriptions (no raw HTML from authors).
 */
function ereview_simple_markdown_html(string $text): string
{
    $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $escaped = preg_replace('/^##\s+(.+)$/m', '<h3 class="text-lg font-bold text-[#143D59] mt-4 mb-2">$1</h3>', $escaped);
    $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
    $escaped = preg_replace('/`([^`]+)`/', '<code class="bg-slate-100 px-1 py-0.5 rounded text-sm">$1</code>', $escaped);
    $escaped = preg_replace('/(?<!\*)\*(?!\*)([^*]+?)(?<!\*)\*(?!\*)/', '<em>$1</em>', $escaped);
    $escaped = preg_replace('/^-\s+(.+)$/m', '<li class="ml-4 list-disc">$1</li>', $escaped);
    $escaped = nl2br($escaped);
    return $escaped;
}
