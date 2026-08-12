<?php
/**
 * Commerce OCR field extraction (Phase 6) - GCash PH receipt heuristics.
 *
 * Does NOT: run OCR engines, write payments, fulfillment, access_grants, SCA.
 */

declare(strict_types=1);

require_once __DIR__ . '/commerce_catalog.php';

/**
 * Local normalize to avoid a hard circular dependency on commerce_payment.php.
 * Mirrors commerce_normalize_gcash_reference when that helper is not loaded yet.
 */
function commerce_ocr_normalize_reference(string $raw): string
{
    if (function_exists('commerce_normalize_gcash_reference')) {
        return commerce_normalize_gcash_reference($raw);
    }
    $raw = trim($raw);
    $raw = str_replace([' ', '-', "\t", "\n", "\r"], '', $raw);
    return strtoupper($raw);
}

function commerce_ocr_normalize_phone(string $s): string
{
    return preg_replace('/\D+/', '', $s) ?? '';
}

/**
 * Collapse spaces, lowercase; true if one contains the other and min length >= 3.
 */
function commerce_ocr_names_match(string $a, string $b): bool
{
    $na = strtolower(trim(preg_replace('/\s+/', ' ', $a) ?? ''));
    $nb = strtolower(trim(preg_replace('/\s+/', ' ', $b) ?? ''));
    if ($na === '' || $nb === '') {
        return false;
    }
    if (strlen($na) < 3 || strlen($nb) < 3) {
        return false;
    }
    if ($na === $nb) {
        return true;
    }
    return (strpos($na, $nb) !== false) || (strpos($nb, $na) !== false);
}

/**
 * Parse pesos amount string into centavos, or null.
 *
 * @param string $num e.g. "1,234.56" or "1234.56" or "1234"
 */
function commerce_ocr_parse_pesos_to_centavos(string $num): ?int
{
    $num = trim($num);
    $num = str_replace([',', ' '], '', $num);
    if ($num === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $num)) {
        return null;
    }
    return (int) round(((float) $num) * 100);
}

/**
 * Try common PH/GCash datetime formats → Y-m-d H:i:s or null.
 */
function commerce_ocr_parse_paid_at(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    // Normalize separators / AM-PM spacing
    $candidates = [$raw];
    $normalized = preg_replace('/\s+/', ' ', $raw) ?? $raw;
    $candidates[] = $normalized;

    $formats = [
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y-m-d\TH:i:sP',
        'Y-m-d\TH:i:s',
        'Y-m-d\TH:i:s.uP',
        'd/m/Y H:i:s',
        'd/m/Y H:i',
        'm/d/Y H:i:s',
        'm/d/Y H:i',
        'd-m-Y H:i:s',
        'd-m-Y H:i',
        'M d, Y g:i A',
        'M d, Y g:iA',
        'F d, Y g:i A',
        'F j, Y g:i A',
        'd M Y H:i',
        'd M Y g:i A',
        'Y/m/d H:i:s',
        'Y/m/d H:i',
    ];

    foreach ($candidates as $c) {
        // ISO-ish with timezone → convert to local wall time string
        $ts = strtotime($c);
        if ($ts !== false) {
            // Prefer format-strict parse below; use strtotime only if looks date-like
            if (preg_match('/\d{4}|\d{1,2}[\/\-]\d{1,2}/', $c)) {
                return date('Y-m-d H:i:s', $ts);
            }
        }
        foreach ($formats as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $c);
            if ($dt instanceof DateTime) {
                $errs = DateTime::getLastErrors();
                if (is_array($errs) && (($errs['warning_count'] ?? 0) > 0 || ($errs['error_count'] ?? 0) > 0)) {
                    continue;
                }
                return $dt->format('Y-m-d H:i:s');
            }
        }
    }
    return null;
}

/**
 * Extract GCash receipt fields from OCR/vision raw text.
 *
 * @return array{
 *   amount_centavos:?int,
 *   reference_raw:?string,
 *   reference_norm:?string,
 *   recipient:?string,
 *   paid_at:?string,
 *   success_text_found:bool,
 *   success_phrases_matched:list<string>,
 *   warnings:list<string>
 * }
 */
function commerce_ocr_extract_from_text(string $rawText): array
{
    $out = [
        'amount_centavos' => null,
        'reference_raw' => null,
        'reference_norm' => null,
        'recipient' => null,
        'paid_at' => null,
        'success_text_found' => false,
        'success_phrases_matched' => [],
        'warnings' => [],
    ];

    $text = trim($rawText);
    if ($text === '') {
        $out['warnings'][] = 'empty_text';
        return $out;
    }

    // ---------- Success phrases ----------
    $phrases = [
        'payment successful',
        'you have sent',
        'sent money',
        'paid successfully',
        'successful',
        'successfully sent',
        'transfer successful',
    ];
    $lower = strtolower($text);
    foreach ($phrases as $p) {
        if (strpos($lower, $p) !== false) {
            $out['success_phrases_matched'][] = $p;
        }
    }
    $out['success_text_found'] = $out['success_phrases_matched'] !== [];

    // ---------- Amount ----------
    $amountCandidates = [];
    $amountPatterns = [
        '/₱\s*([0-9]{1,3}(?:,[0-9]{3})*(?:\.[0-9]{2})?)/u',
        '/\bPHP\s*([0-9]{1,3}(?:,[0-9]{3})*(?:\.[0-9]{2})?)/i',
        '/\bAmount\s*[:\-]?\s*₱?\s*([0-9]{1,3}(?:,[0-9]{3})*(?:\.[0-9]{2})?)/i',
        '/\bTotal\s*[:\-]?\s*₱?\s*([0-9]{1,3}(?:,[0-9]{3})*(?:\.[0-9]{2})?)/i',
        '/\b([0-9]{1,3}(?:,[0-9]{3})+\.[0-9]{2})\b/',
        '/\b([0-9]+\.[0-9]{2})\b/',
    ];
    foreach ($amountPatterns as $pat) {
        if (preg_match_all($pat, $text, $m)) {
            foreach ($m[1] as $num) {
                $c = commerce_ocr_parse_pesos_to_centavos((string) $num);
                if ($c !== null && $c > 0) {
                    $amountCandidates[] = $c;
                }
            }
        }
    }
    if ($amountCandidates !== []) {
        // Prefer the first labeled match; otherwise first candidate
        $out['amount_centavos'] = $amountCandidates[0];
        if (count(array_unique($amountCandidates)) > 1) {
            $out['warnings'][] = 'multiple_amounts_detected';
        }
    } else {
        $out['warnings'][] = 'amount_not_found';
    }

    // ---------- Reference ----------
    $refRaw = null;
    $refPatterns = [
        '/\b(?:Ref(?:erence)?\s*(?:No\.?|Number|#)?|Transaction\s*(?:No\.?|Number|#)?)\s*[:\-]?\s*([A-Za-z0-9][A-Za-z0-9 \-]{6,22})/i',
        '/\bRef\s*No\.?\s*[:\-]?\s*([A-Za-z0-9]{8,20})\b/i',
        '/\bReference\s*(?:Number|No\.?)?\s*[:\-]?\s*([A-Za-z0-9]{8,20})\b/i',
    ];
    foreach ($refPatterns as $pat) {
        if (preg_match($pat, $text, $m)) {
            $refRaw = trim((string) $m[1]);
            break;
        }
    }
    if ($refRaw === null) {
        // Fallback: longest alphanumeric token 8-20 chars (excluding pure short numbers)
        if (preg_match_all('/\b([A-Za-z0-9]{8,20})\b/', $text, $m)) {
            $best = '';
            foreach ($m[1] as $tok) {
                $tok = (string) $tok;
                // Prefer mixed or long numeric GCash-style refs
                if (strlen($tok) > strlen($best)) {
                    $best = $tok;
                }
            }
            if ($best !== '') {
                $refRaw = $best;
                $out['warnings'][] = 'reference_heuristic_fallback';
            }
        }
    }
    if ($refRaw !== null && $refRaw !== '') {
        $out['reference_raw'] = $refRaw;
        $out['reference_norm'] = commerce_ocr_normalize_reference($refRaw);
    } else {
        $out['warnings'][] = 'reference_not_found';
    }

    // ---------- Recipient ----------
    $recipient = null;
    $recipPatterns = [
        '/\bTo\s*[:\-]\s*([^\n\r]{3,80})/i',
        '/\bSent\s+to\s*[:\-]?\s*([^\n\r]{3,80})/i',
        '/\bAccount\s+Name\s*[:\-]\s*([^\n\r]{3,80})/i',
        '/\bRecipient\s*[:\-]\s*([^\n\r]{3,80})/i',
    ];
    foreach ($recipPatterns as $pat) {
        if (preg_match($pat, $text, $m)) {
            $candidate = trim((string) $m[1]);
            // Cut trailing labels on same line
            $candidate = preg_replace('/\s{2,}.*$/', '', $candidate) ?? $candidate;
            $candidate = trim($candidate, " \t:-");
            if (strlen($candidate) >= 3) {
                $recipient = $candidate;
                break;
            }
        }
    }
    if ($recipient !== null) {
        $out['recipient'] = $recipient;
    } else {
        $out['warnings'][] = 'recipient_not_found';
    }

    // ---------- Date / time ----------
    $paidAt = null;
    $datePatterns = [
        '/\b(?:Date|Paid\s*(?:on|at)?|Transaction\s*Date|Sent\s*on)\s*[:\-]?\s*([A-Za-z0-9,:\-\/\s]{6,40})/i',
        '/\b(\d{4}-\d{2}-\d{2}[ T]\d{1,2}:\d{2}(?::\d{2})?(?:Z|[+\-]\d{2}:?\d{2})?)/',
        '/\b(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}\s+\d{1,2}:\d{2}(?::\d{2})?(?:\s*[AaPp][Mm])?)/',
        '/\b((?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+\d{1,2},?\s+\d{4}\s+\d{1,2}:\d{2}(?:\s*[AaPp][Mm])?)/i',
    ];
    foreach ($datePatterns as $pat) {
        if (preg_match($pat, $text, $m)) {
            $parsed = commerce_ocr_parse_paid_at(trim((string) $m[1]));
            if ($parsed !== null) {
                $paidAt = $parsed;
                break;
            }
        }
    }
    if ($paidAt === null) {
        // Last resort: any ISO-like date in text
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $m)) {
            $parsed = commerce_ocr_parse_paid_at($m[1] . ' 12:00:00');
            if ($parsed !== null) {
                $paidAt = $parsed;
                $out['warnings'][] = 'paid_at_date_only';
            }
        }
    }
    if ($paidAt !== null) {
        $out['paid_at'] = $paidAt;
    } else {
        $out['warnings'][] = 'paid_at_not_found';
    }

    return $out;
}

/**
 * Optionally merge conservative vision JSON fields into an extract result
 * (fill gaps only; do not overwrite non-null extract values).
 *
 * @param array<string,mixed> $extracted
 * @param array<string,mixed> $visionFields
 * @return array<string,mixed>
 */
function commerce_ocr_merge_vision_fields(array $extracted, array $visionFields): array
{
    if ($visionFields === []) {
        return $extracted;
    }

    if ($extracted['amount_centavos'] === null && isset($visionFields['amount_pesos']) && $visionFields['amount_pesos'] !== null && $visionFields['amount_pesos'] !== '') {
        $pesos = $visionFields['amount_pesos'];
        if (is_numeric($pesos)) {
            $extracted['amount_centavos'] = (int) round(((float) $pesos) * 100);
        } elseif (is_string($pesos)) {
            $c = commerce_ocr_parse_pesos_to_centavos(str_replace(['₱', 'PHP', ','], '', $pesos));
            if ($c !== null) {
                $extracted['amount_centavos'] = $c;
            }
        }
    }

    if (empty($extracted['reference_raw']) && !empty($visionFields['reference']) && is_string($visionFields['reference'])) {
        $extracted['reference_raw'] = trim($visionFields['reference']);
        $extracted['reference_norm'] = commerce_ocr_normalize_reference($extracted['reference_raw']);
    }

    if (empty($extracted['recipient']) && !empty($visionFields['recipient']) && is_string($visionFields['recipient'])) {
        $extracted['recipient'] = trim($visionFields['recipient']);
    }

    if (empty($extracted['paid_at']) && !empty($visionFields['paid_at_iso']) && is_string($visionFields['paid_at_iso'])) {
        $parsed = commerce_ocr_parse_paid_at($visionFields['paid_at_iso']);
        if ($parsed !== null) {
            $extracted['paid_at'] = $parsed;
        }
    }

    if (empty($extracted['success_text_found']) && !empty($visionFields['success_text_found'])) {
        $extracted['success_text_found'] = true;
        $extracted['success_phrases_matched'][] = 'vision_success_flag';
    }

    return $extracted;
}
