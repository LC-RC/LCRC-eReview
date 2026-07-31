<?php
/**
 * Generate assets/css/tailwind-arbitrary.css from class= attributes in PHP.
 * Allows dropping the Tailwind CDN when assets/css/tailwind.css is present.
 *
 * Usage: php scripts/build_tailwind_arbitrary.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$outFile = $root . '/assets/css/tailwind-arbitrary.css';

function tw_sel(string $cls): string
{
    $out = '';
    $len = strlen($cls);
    for ($i = 0; $i < $len; $i++) {
        $ch = $cls[$i];
        if (preg_match('/[a-zA-Z0-9_-]/', $ch)) {
            $out .= $ch;
        } else {
            $out .= '\\' . $ch;
        }
    }
    return '.' . $out;
}

function prop_for(string $cls): ?string
{
    if (preg_match('/^(sm|md|lg|xl|2xl):(.+)$/', $cls)) {
        return null;
    }
    if (preg_match('/^bg-\[(.+)\]$/', $cls, $m)) {
        $v = str_replace('_', ' ', $m[1]);
        if (str_contains($v, 'gradient')) {
            return 'background-image:' . $v;
        }
        return 'background-color:' . $v;
    }
    if (preg_match('/^text-\[(.+)\]$/', $cls, $m)) {
        $v = str_replace('_', ' ', $m[1]);
        if (preg_match('/^\d/', $v) || preg_match('/(px|rem|em|%)$/', $v)) {
            return 'font-size:' . $v;
        }
        return 'color:' . $v;
    }
    if (preg_match('/^border-\[(.+)\]$/', $cls, $m)) {
        $v = str_replace('_', ' ', $m[1]);
        if (preg_match('/^\d/', $v) || preg_match('/(px|rem|em)$/', $v)) {
            return 'border-width:' . $v;
        }
        return 'border-color:' . $v;
    }
    if (preg_match('/^from-\[(.+)\]$/', $cls, $m)) {
        $v = str_replace('_', ' ', $m[1]);
        return '--tw-gradient-from:' . $v . ' var(--tw-gradient-from-position);--tw-gradient-to:rgb(255 255 255 / 0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)';
    }
    if (preg_match('/^to-\[(.+)\]$/', $cls, $m)) {
        return '--tw-gradient-to:' . str_replace('_', ' ', $m[1]) . ' var(--tw-gradient-to-position)';
    }
    if (preg_match('/^shadow-\[(.+)\]$/', $cls, $m)) {
        return '--tw-shadow:' . str_replace('_', ' ', $m[1]) . ';box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)';
    }

    $simple = [
        'w' => 'width',
        'h' => 'height',
        'min-w' => 'min-width',
        'min-h' => 'min-height',
        'max-w' => 'max-width',
        'max-h' => 'max-height',
        'top' => 'top',
        'left' => 'left',
        'right' => 'right',
        'bottom' => 'bottom',
        'inset' => 'inset',
        'z' => 'z-index',
        'gap' => 'gap',
        'p' => 'padding',
        'pt' => 'padding-top',
        'pb' => 'padding-bottom',
        'pl' => 'padding-left',
        'pr' => 'padding-right',
        'm' => 'margin',
        'mt' => 'margin-top',
        'mb' => 'margin-bottom',
        'ml' => 'margin-left',
        'mr' => 'margin-right',
        'basis' => 'flex-basis',
        'tracking' => 'letter-spacing',
        'leading' => 'line-height',
        'opacity' => 'opacity',
        'duration' => 'transition-duration',
        'delay' => 'transition-delay',
        'rounded' => 'border-radius',
    ];
    if (preg_match('/^(w|h|min-w|min-h|max-w|max-h|top|left|right|bottom|inset|z|gap|p|pt|pb|pl|pr|m|mt|mb|ml|mr|basis|tracking|leading|opacity|duration|delay|rounded)-\[(.+)\]$/', $cls, $m)) {
        $prop = $simple[$m[1]] ?? null;
        if ($prop === null) {
            return null;
        }
        $v = str_replace('_', ' ', $m[2]);
        if ($m[1] === 'duration' || $m[1] === 'delay') {
            if (ctype_digit($v)) {
                $v .= 'ms';
            }
        }
        if ($m[1] === 'opacity' && is_numeric($v) && (float) $v > 1) {
            $v = (string) ((float) $v / 100);
        }
        return $prop . ':' . $v;
    }
    if (preg_match('/^px-\[(.+)\]$/', $cls, $m)) {
        $v = str_replace('_', ' ', $m[1]);
        return 'padding-left:' . $v . ';padding-right:' . $v;
    }
    if (preg_match('/^py-\[(.+)\]$/', $cls, $m)) {
        $v = str_replace('_', ' ', $m[1]);
        return 'padding-top:' . $v . ';padding-bottom:' . $v;
    }
    if (preg_match('/^mx-\[(.+)\]$/', $cls, $m)) {
        $v = str_replace('_', ' ', $m[1]);
        return 'margin-left:' . $v . ';margin-right:' . $v;
    }
    if (preg_match('/^my-\[(.+)\]$/', $cls, $m)) {
        $v = str_replace('_', ' ', $m[1]);
        return 'margin-top:' . $v . ';margin-bottom:' . $v;
    }
    return null;
}

$found = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if (!$f->isFile()) {
        continue;
    }
    $p = str_replace('\\', '/', $f->getPathname());
    if (str_contains($p, '/node_modules/') || str_contains($p, '/.git/') || str_contains($p, '/uploads/')) {
        continue;
    }
    if (!str_ends_with(strtolower($p), '.php')) {
        continue;
    }
    $c = @file_get_contents($p);
    if ($c === false) {
        continue;
    }
    if (!preg_match_all('/(?:class|className)\s*=\s*(["\'])(.*?)\1/s', $c, $blocks)) {
        continue;
    }
    foreach ($blocks[2] as $block) {
        if (preg_match_all('/\b(?:bg|text|border|from|to|via|ring|shadow|w|h|min-w|min-h|max-w|max-h|p|px|py|pt|pb|pl|pr|m|mx|my|mt|mb|ml|mr|gap|top|left|right|bottom|inset|z|basis|tracking|leading|opacity|duration|delay|rounded)-?\[[^\]]+\]/', $block, $m)) {
            foreach ($m[0] as $cls) {
                $found[$cls] = true;
            }
        }
    }
}

ksort($found);
$css = "/* Auto-generated arbitrary Tailwind utilities (CDN-free bridge).\n"
    . " * Regenerate: php scripts/build_tailwind_arbitrary.php\n"
    . " */\n";
$emitted = 0;
$skipped = 0;
foreach (array_keys($found) as $cls) {
    $decl = prop_for($cls);
    if ($decl === null) {
        $skipped++;
        continue;
    }
    $css .= tw_sel($cls) . '{' . $decl . ";}\n";
    $emitted++;
}

if (!is_dir(dirname($outFile))) {
    mkdir(dirname($outFile), 0775, true);
}
file_put_contents($outFile, $css);
echo "found=" . count($found) . " emitted={$emitted} skipped={$skipped} bytes=" . strlen($css) . "\n";
echo "wrote {$outFile}\n";
