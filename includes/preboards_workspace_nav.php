<?php
declare(strict_types=1);

/**
 * Presentation-only Preboard subject workspace navigation.
 * Maps to existing routes; does not change handlers or query names.
 *
 * @param array{
 *   subject_id:int,
 *   subject_name:string,
 *   active:string,
 *   pending_count?:int,
 *   sets_q?:string,
 *   sets_count?:int,
 *   variant?:string
 * } $opts
 */
function preboards_render_workspace_nav(array $opts): void
{
    $subjectId = (int) ($opts['subject_id'] ?? 0);
    $active = (string) ($opts['active'] ?? 'sets');
    $pending = (int) ($opts['pending_count'] ?? 0);
    $setsQ = trim((string) ($opts['sets_q'] ?? ''));
    $variant = (string) ($opts['variant'] ?? '');
    $setsCount = array_key_exists('sets_count', $opts) ? (int) $opts['sets_count'] : null;
    $setsQs = 'preboards_subject_id=' . $subjectId;
    if ($setsQ !== '') {
        $setsQs .= '&q=' . rawurlencode($setsQ);
    }
    $setsUrl = 'admin_preboards_sets?' . $setsQs;
    $completionUrl = 'admin_preboards_sets?' . $setsQs . '&completion=1';
    $monitorUrl = 'admin_preboards_monitor?preboards_subject_id=' . $subjectId;
    $requestsHref = ($active === 'sets' || $active === 'completion')
        ? '#preboards-requests'
        : ($setsUrl . '#preboards-requests');

    $tabs = [
        'sets' => ['Sets', $setsUrl, $setsCount],
        'monitoring' => ['Monitoring', $monitorUrl, null],
        'completion' => ['Completion', $completionUrl, null],
    ];
    $navClass = 'preboards-workspace-nav';
    if ($variant === 'app') {
        $navClass .= ' preboards-workspace-nav--app';
    }
    ?>
    <nav class="<?php echo h($navClass); ?>" aria-label="Preboard workspace">
      <div class="preboards-workspace-nav__tabs">
        <?php foreach ($tabs as $key => $tab):
            $isCurrent = $active === $key;
            ?>
          <a href="<?php echo h($tab[1]); ?>"
             class="preboards-workspace-nav__tab<?php echo $isCurrent ? ' is-active' : ''; ?>"
             <?php echo $isCurrent ? ' aria-current="page"' : ''; ?>><?php echo h($tab[0]); ?><?php if ($tab[2] !== null): ?> <span class="preboards-workspace-nav__count"><?php echo (int) $tab[2]; ?></span><?php endif; ?></a>
        <?php endforeach; ?>
      </div>
      <?php if ($pending > 0): ?>
        <a href="<?php echo h($requestsHref); ?>" class="preboards-workspace-nav__notice">
          Access requests · <?php echo $pending; ?>
        </a>
      <?php endif; ?>
    </nav>
    <?php
}

/**
 * Split existing access-meta into a short status word + secondary schedule text.
 *
 * @param array{key?:string,label?:string,opens_display?:?string,closes_display?:?string} $accessMeta
 * @return array{key:string,word:string,detail:string}
 */
function preboards_exam_status_parts(array $accessMeta): array
{
    $key = (string) ($accessMeta['key'] ?? 'locked');
    $words = [
        'open' => 'OPEN',
        'upcoming' => 'UPCOMING',
        'closed' => 'CLOSED',
        'locked' => 'LOCKED',
    ];
    $word = $words[$key] ?? strtoupper($key);
    $detail = '';
    $closes = trim((string) ($accessMeta['closes_display'] ?? ''));
    $opens = trim((string) ($accessMeta['opens_display'] ?? ''));
    $label = trim((string) ($accessMeta['label'] ?? ''));

    if ($key === 'closed' && $closes !== '') {
        $detail = 'Ended ' . $closes;
    } elseif ($key === 'upcoming' && $opens !== '') {
        $detail = 'Opens ' . $opens;
    } elseif ($key === 'open' && $closes !== '') {
        $detail = 'Until ' . $closes;
    } elseif ($label !== '' && strcasecmp($label, $word) !== 0) {
        $stripped = preg_replace('/^(Open|Closed|Locked|Opens|Scheduled)(\s*[·•-]\s*|\s+)/i', '', $label);
        $detail = is_string($stripped) ? trim($stripped) : '';
        if ($detail === $label) {
            $detail = '';
        }
    }

    return ['key' => $key, 'word' => $word, 'detail' => $detail];
}
