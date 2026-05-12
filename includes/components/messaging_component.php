<?php
if (!isset($_SESSION)) {
    @session_start();
}
if (!empty($GLOBALS['ereview_messaging_component_included'])) {
    return;
}
$GLOBALS['ereview_messaging_component_included'] = true;

$msgRole = (string)($_SESSION['role'] ?? '');
$msgAllowed = in_array($msgRole, ['admin', 'professor_admin', 'student', 'college_student'], true);
if (!$msgAllowed) {
    return;
}
$msgThemeClass = ($msgRole === 'admin' || $msgRole === 'professor_admin') ? 'ere-msg--admin' : 'ere-msg--reviewee';
?>
<div id="ereMsgRoot" class="ere-msg <?php echo h($msgThemeClass); ?>" data-msg-role="<?php echo h($msgRole); ?>" data-msg-is-staff="<?php echo ($msgRole === 'admin' || $msgRole === 'professor_admin') ? '1' : '0'; ?>" aria-hidden="true">
  <div class="ere-msg__overlay" data-ere-msg-close></div>
  <section class="ere-msg__panel" role="dialog" aria-modal="true" aria-labelledby="ereMsgTitle">
    <header class="ere-msg__head">
      <h2 class="ere-msg__title" id="ereMsgTitle"><i class="bi bi-envelope-paper"></i> Messages</h2>
      <button type="button" class="ere-msg__close" data-ere-msg-close aria-label="Close messages"><i class="bi bi-x-lg"></i></button>
    </header>
    <div class="ere-msg__body">
      <aside class="ere-msg__threads" id="ereMsgThreadsWrap">
        <div class="ere-msg__threads-head">Conversations</div>
        <div class="ere-msg__threads-tools">
          <input type="search" id="ereMsgThreadSearch" class="ere-msg__search" placeholder="Search conversations..." aria-label="Search conversations">
        </div>
        <div class="ere-msg__threads-list" id="ereMsgThreads"></div>
      </aside>
      <main class="ere-msg__chat">
        <div class="ere-msg__chat-head">
          <div id="ereMsgChatHead">Select a conversation</div>
          <div class="ere-msg__chat-search">
            <button type="button" id="ereMsgHistoryBtn" class="ere-msg__history-trigger" aria-label="Media and files history">
              <span aria-hidden="true">🗂</span>
              <span>Media &amp; Files</span>
            </button>
            <input type="search" id="ereMsgSearch" class="ere-msg__search ere-msg__search--chat" placeholder="Search in chat..." aria-label="Search in conversation">
            <button type="button" id="ereMsgSearchPrev" class="ere-msg__mini-btn" aria-label="Previous match">↑</button>
            <button type="button" id="ereMsgSearchNext" class="ere-msg__mini-btn" aria-label="Next match">↓</button>
          </div>
        </div>
        <div id="ereMsgHistoryPanel" class="ere-msg__history" hidden>
          <div class="ere-msg__history-head">
            <strong>Media & Files</strong>
            <button type="button" id="ereMsgHistoryClose" class="ere-msg__mini-btn" aria-label="Close history">✕</button>
          </div>
          <div class="ere-msg__history-filters">
            <div class="ere-msg__history-filter-group">
              <p>Direction</p>
              <div class="ere-msg__history-chip-row">
                <button type="button" class="ere-msg__history-chip is-on" data-hdir="all">All</button>
                <button type="button" class="ere-msg__history-chip" data-hdir="sent">Sent</button>
                <button type="button" class="ere-msg__history-chip" data-hdir="received">Received</button>
              </div>
            </div>
            <div class="ere-msg__history-filter-group">
              <p>Type</p>
              <div class="ere-msg__history-chip-row">
                <button type="button" class="ere-msg__history-chip is-on" data-hkind="all">All types</button>
                <button type="button" class="ere-msg__history-chip" data-hkind="photo">Photos</button>
                <button type="button" class="ere-msg__history-chip" data-hkind="document">Documents</button>
                <button type="button" class="ere-msg__history-chip" data-hkind="audio">Audios</button>
                <button type="button" class="ere-msg__history-chip" data-hkind="link">Links</button>
              </div>
            </div>
          </div>
          <div id="ereMsgHistoryList" class="ere-msg__history-list"></div>
        </div>
        <div id="ereMsgAwayBanner" class="ere-msg__away-banner" hidden>Admin replies are available during business hours (Mon-Fri, 8:00 AM-6:00 PM PHT).</div>
        <div class="ere-msg__log" id="ereMsgLog"></div>
        <div id="ereMsgTyping" class="ere-msg__typing" hidden aria-live="polite"></div>
        <form class="ere-msg__composer" id="ereMsgComposer">
          <div class="ere-msg__composer-box">
            <textarea id="ereMsgInput" class="ere-msg__input" rows="2" maxlength="15000" placeholder="Type your message..."></textarea>
            <div class="ere-msg__toolbar" id="ereMsgToolbar">
              <div class="ere-msg__toolbar-left">
                <button type="button" id="ereMsgFmtBtn" class="ere-msg__tool-btn" aria-label="Formatting options" title="Formatting">A</button>
                <button type="button" id="ereMsgEmojiBtn" class="ere-msg__tool-btn" aria-label="Add emoji" title="Emoji">☺</button>
                <button type="button" id="ereMsgGifBtn" class="ere-msg__tool-btn" aria-label="Add GIF or sticker" title="GIF / Sticker">▣</button>
                <button type="button" id="ereMsgAttachBtn" class="ere-msg__tool-btn" aria-label="Upload file" title="Upload file">⇪</button>
                <button type="button" id="ereMsgVoiceBtn" class="ere-msg__tool-btn" aria-label="Record voice message" title="Voice message">🎤</button>
              </div>
              <div class="ere-msg__toolbar-right">
                <select id="ereMsgCanned" class="ere-msg__canned" hidden>
                  <option value="">Canned reply...</option>
                  <option value="Enrollment reminder: Please complete your pending requirements to avoid access interruption.">Enrollment reminder</option>
                  <option value="Please upload your payment proof in your profile to proceed with review activation.">Payment proof reminder</option>
                  <option value="Thank you for your message. We received this and will get back to you shortly.">Acknowledgement</option>
                </select>
              </div>
            </div>
            <div id="ereMsgAttachmentPreview" class="ere-msg__attachment-preview" hidden></div>
            <div id="ereMsgFmtMenu" class="ere-msg__popover" hidden>
              <button type="button" data-fmt="bold"><strong>Bold</strong></button>
              <button type="button" data-fmt="code"><code>Code</code></button>
              <button type="button" data-fmt="list">Bullet list</button>
            </div>
            <div id="ereMsgEmojiMenu" class="ere-msg__popover" hidden>
              <button type="button" data-emoji="😀">😀</button><button type="button" data-emoji="😂">😂</button><button type="button" data-emoji="😍">😍</button><button type="button" data-emoji="👍">👍</button><button type="button" data-emoji="🙏">🙏</button><button type="button" data-emoji="🔥">🔥</button><button type="button" data-emoji="🎉">🎉</button><button type="button" data-emoji="✅">✅</button>
            </div>
            <div id="ereMsgGifMenu" class="ere-msg__popover ere-msg__popover--wide" hidden>
              <button type="button" data-gif="https://media.giphy.com/media/3o7TKtnuHOHHUjR38Y/giphy.gif">Sticker: Great</button>
              <button type="button" data-gif="https://media.giphy.com/media/l0HlBO7eyXzSZkJri/giphy.gif">GIF: Thank you</button>
              <button type="button" data-gif="https://media.giphy.com/media/fxsqOYnIMEefC/giphy.gif">GIF: Nice</button>
            </div>
          </div>
          <input type="file" id="ereMsgAttachment" class="ere-msg__attachment-input" accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.mp3,.wav,.ogg,.webm,audio/*">
          <button type="submit" class="ere-msg__send"><i class="bi bi-send"></i><span>Send</span></button>
        </form>
        <div class="ere-msg__composer-foot">
          <span id="ereMsgSendHint" class="ere-msg__composer-hint">Enter to send · Shift+Enter newline · Ctrl/Cmd+Enter send</span>
          <span id="ereMsgCharCount" class="ere-msg__composer-count">0 / 15000</span>
        </div>
      </main>
    </div>
  </section>
</div>

<style>
  /* Slide drawer: open = .ere-msg--open on #ereMsgRoot (no [hidden] — transitions need computed layout). */
  .ere-msg{
    position:fixed;inset:0;z-index:1400;
    pointer-events:none;
  }
  .ere-msg.ere-msg--open{
    pointer-events:auto;
  }
  .ere-msg__overlay{
    position:absolute;inset:0;background:rgba(2,6,23,.62);backdrop-filter:blur(5px);
    opacity:0;
    transition:opacity .38s cubic-bezier(.22,1,.36,1);
  }
  .ere-msg.ere-msg--open .ere-msg__overlay{
    opacity:1;
  }
  .ere-msg__panel{
    position:absolute;right:0;top:0;height:100%;width:min(1040px,100%);
    display:flex;flex-direction:column;
    background:linear-gradient(160deg,#f8fbff 0%,#f1f7ff 30%,#f8fafc 100%);
    border-left:1px solid rgba(255,255,255,.5);
    box-shadow:-36px 0 64px -36px rgba(2,6,23,.75);
    transform:translate3d(100%,0,0);
    transition:transform .42s cubic-bezier(.22,1,.36,1);
  }
  .ere-msg.ere-msg--open .ere-msg__panel{
    transform:translate3d(0,0,0);
  }
  @media (prefers-reduced-motion:reduce){
    .ere-msg__overlay,.ere-msg__panel,.ere-msg__thread,.ere-msg__send,.ere-msg__close{transition-duration:.01ms!important;animation:none!important}
  }
  .ere-msg__head{
    display:flex;align-items:center;justify-content:space-between;padding:.9rem 1rem;
    border-bottom:1px solid #dce8f5;
    background:linear-gradient(115deg,#0f4f80 0%,#1665A0 45%,#1d84d4 100%);
    color:#fff;
  }
  .ere-msg__title{margin:0;font-size:1rem;font-weight:800;color:#fff;display:flex;align-items:center;gap:.55rem}
  .ere-msg__title i{font-size:1.05rem;opacity:.95}
  .ere-msg__close{
    border:1px solid rgba(255,255,255,.32);background:rgba(255,255,255,.14);color:#fff;
    border-radius:.62rem;width:2rem;height:2rem;display:inline-flex;align-items:center;justify-content:center;
    transition:transform .18s ease, background-color .18s ease;
  }
  .ere-msg__close:hover{transform:translateY(-1px);background:rgba(255,255,255,.22)}
  .ere-msg__body{display:grid;grid-template-columns:300px 1fr;min-height:0;flex:1}
  .ere-msg__body.ere-msg__body--single{grid-template-columns:1fr}
  .ere-msg__threads{
    border-right:1px solid #dbe7f5;display:flex;flex-direction:column;min-height:0;
    background:linear-gradient(180deg,#f8fbff 0%,#f8fafc 100%);
  }
  .ere-msg__threads-head{padding:.72rem .86rem;font-size:.7rem;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:#64748b}
  .ere-msg__threads-tools{padding:0 .55rem .4rem}
  .ere-msg__search{
    width:100%;border:1px solid #c9dff1;background:#fff;border-radius:.6rem;padding:.45rem .58rem;font-size:.75rem;color:#0f172a;
  }
  .ere-msg__search:focus{outline:none;border-color:#1665A0;box-shadow:0 0 0 2px rgba(22,101,160,.13)}
  .ere-msg__threads-list{overflow:auto;padding:.45rem}
  .ere-msg__thread-row{
    width:100%;position:relative;margin:0 0 .45rem;
  }
  .ere-msg__thread{
    width:100%;text-align:left;border:1px solid #dbe7f5;background:linear-gradient(180deg,#fff 0%,#f8fbff 100%);
    border-radius:.72rem;padding:.55rem .62rem;color:#0f172a;
    box-shadow:0 8px 16px -16px rgba(15,23,42,.5);
    transition:transform .16s ease,border-color .16s ease,box-shadow .16s ease,background .16s ease;
    display:flex;align-items:flex-start;gap:.62rem;
  }
  .ere-msg__thread:hover{transform:translateY(-1px);border-color:#8fc0e8;box-shadow:0 14px 20px -18px rgba(20,61,89,.65)}
  .ere-msg__thread.is-active{border-color:#1665A0;background:linear-gradient(180deg,#eff6ff 0%,#e8f2fa 100%)}
  .ere-msg__thread-avatar-wrap{
    position:relative;flex-shrink:0;width:2.55rem;height:2.55rem;border-radius:9999px;
    display:flex;align-items:center;justify-content:center;overflow:visible;
  }
  .ere-msg__thread-avatar-media{
    width:100%;height:100%;border-radius:9999px;overflow:hidden;
    display:flex;align-items:center;justify-content:center;
    background:#334155;color:#fff;font-weight:800;font-size:.88rem;text-transform:uppercase;
    border:2px solid rgba(255,255,255,.88);
    box-shadow:0 4px 12px rgba(15,23,42,.2);
  }
  .ere-msg__thread-avatar-media img{width:100%;height:100%;object-fit:cover;display:block}
  .ere-msg__thread-status-dot{
    position:absolute;right:-1px;bottom:-1px;width:.88rem;height:.88rem;border-radius:9999px;
    border:2px solid rgba(255,255,255,.92);z-index:2;
  }
  .ere-msg__thread-status-dot--active{
    background:#22c55e;
    box-shadow:0 0 0 2px rgba(34,197,94,.28),0 0 10px rgba(34,197,94,.75);
  }
  .ere-msg__thread-status-dot--inactive{
    background:#9ca3af;
    box-shadow:0 0 0 2px rgba(148,163,184,.22);
  }
  .ere-msg__thread-main{flex:1;min-width:0}
  .ere-msg__thread-top{display:flex;justify-content:space-between;align-items:flex-start;gap:.5rem;font-size:.79rem;font-weight:800}
  .ere-msg__thread-name{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
  .ere-msg__thread-meta{display:inline-flex;align-items:center;gap:.35rem;flex-shrink:0}
  .ere-msg__thread-pin{
    position:absolute;left:.22rem;top:.22rem;width:1.08rem;height:1.08rem;border-radius:.3rem;border:1px solid #bfd8ee;background:#fff;color:#0f4f80;
    font-size:.62rem;line-height:1;text-align:center;padding:0;display:inline-flex;align-items:center;justify-content:center;z-index:3;
  }
  .ere-msg__thread-pin:hover{background:#eff6ff;border-color:#8fc0e8}
  .ere-msg__thread-time{font-size:.66rem;color:#64748b;white-space:nowrap}
  .ere-msg__thread-preview{font-size:.72rem;color:#64748b;margin-top:.12rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .ere-msg__thread-badge{display:inline-flex;align-items:center;justify-content:center;min-width:1.1rem;height:1.1rem;padding:0 .3rem;border-radius:9999px;background:#dc2626;color:#fff;font-size:.62rem;font-weight:800}
  .ere-msg__skeleton-line{display:block;height:.6rem;border-radius:9999px;background:linear-gradient(90deg,rgba(148,163,184,.24),rgba(148,163,184,.4),rgba(148,163,184,.24));background-size:200% 100%;animation:ereMsgShimmer 1.35s linear infinite}
  .ere-msg__thread-skeleton{border:1px solid #dbe7f5;border-radius:.72rem;padding:.58rem .62rem;margin:0 0 .45rem;display:flex;gap:.62rem;background:linear-gradient(180deg,#fff 0%,#f8fbff 100%)}
  .ere-msg__thread-skel-avatar{width:2.55rem;height:2.55rem;border-radius:9999px;flex-shrink:0;background:linear-gradient(90deg,rgba(148,163,184,.24),rgba(148,163,184,.4),rgba(148,163,184,.24));background-size:200% 100%;animation:ereMsgShimmer 1.35s linear infinite}
  .ere-msg__thread-skel-main{flex:1;min-width:0}
  .ere-msg__thread-skel-main .ere-msg__skeleton-line:first-child{width:68%;margin:.15rem 0 .38rem}
  .ere-msg__thread-skel-main .ere-msg__skeleton-line:last-child{width:92%}
  .ere-msg__chat{position:relative;display:flex;flex-direction:column;min-height:0;background:linear-gradient(180deg,#f8fbff 0%,#fff 100%);--ere-msg-history-w:0px}
  .ere-msg__away-banner{padding:.45rem .9rem;border-bottom:1px dashed #cbd5e1;background:#fff7ed;color:#9a3412;font-size:.72rem}
  .ere-msg__chat-head{
    padding:.62rem .9rem;border-bottom:1px solid #dbe7f5;font-size:.82rem;font-weight:800;color:#0f3960;
    background:linear-gradient(180deg,#fff,#f8fbff);display:flex;align-items:center;justify-content:space-between;gap:.7rem;
  }
  .ere-msg__chat-search{display:flex;align-items:center;gap:.35rem;min-width:0}
  .ere-msg__search--chat{min-width:210px;font-size:.72rem;padding:.36rem .5rem}
  .ere-msg__history-trigger{
    display:inline-flex;align-items:center;gap:.34rem;height:1.8rem;border:1px solid #bfd8ee;background:#fff;color:#0f4f80;border-radius:.55rem;padding:0 .62rem;font-size:.69rem;font-weight:800;white-space:nowrap;flex-shrink:0;
  }
  .ere-msg__history-trigger span[aria-hidden="true"]{font-size:.82rem;line-height:1}
  .ere-msg__history-trigger:hover{background:#eff6ff;border-color:#8fc0e8}
  .ere-msg__history-trigger.is-on{background:#dbeafe;border-color:#60a5fa;color:#1e3a8a}
  .ere-msg__chat.ere-msg__chat--history-open{--ere-msg-history-w:100%}
  .ere-msg__chat.ere-msg__chat--history-open .ere-msg__away-banner,
  .ere-msg__chat.ere-msg__chat--history-open .ere-msg__log,
  .ere-msg__chat.ere-msg__chat--history-open .ere-msg__typing,
  .ere-msg__chat.ere-msg__chat--history-open .ere-msg__composer,
  .ere-msg__chat.ere-msg__chat--history-open .ere-msg__composer-foot{display:none!important}
  .ere-msg__history{
    position:absolute;top:3.1rem;left:0;right:0;bottom:0;width:auto;overflow-y:auto;overflow-x:hidden;z-index:9;
    border-top:1px solid #dbe7f5;background:#fff;box-shadow:none;
  }
  .ere-msg__history-head{display:flex;align-items:center;justify-content:space-between;padding:.62rem .7rem;border-bottom:1px solid #e2e8f0}
  .ere-msg__history-filters{display:grid;gap:.4rem;padding:.52rem .7rem;border-bottom:1px solid #eef2f7}
  .ere-msg__history-filter-group p{margin:0 0 .22rem;font-size:.62rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#64748b}
  .ere-msg__history-chip-row{display:flex;flex-wrap:wrap;gap:.32rem}
  .ere-msg__history-chip{border:1px solid #cbd5e1;background:#fff;border-radius:9999px;padding:.16rem .55rem;font-size:.68rem}
  .ere-msg__history-chip.is-on{background:#dbeafe;border-color:#60a5fa;color:#1e3a8a}
  .ere-msg__history-list{padding:.55rem .65rem;display:grid;gap:.45rem}
  .ere-msg__history-item{display:flex;align-items:center;gap:.5rem;border:1px solid #e2e8f0;border-radius:.55rem;padding:.4rem .5rem;background:#f8fbff}
  .ere-msg__history-item img{width:52px;height:52px;border-radius:.45rem;object-fit:cover}
  .ere-msg__history-meta{min-width:0;flex:1}
  .ere-msg__history-meta strong{display:block;font-size:.72rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .ere-msg__history-meta span{display:block;font-size:.63rem;color:#64748b}
  .ere-msg__mini-btn{
    border:1px solid #bfd8ee;background:#fff;color:#0f4f80;border-radius:.45rem;height:1.72rem;min-width:1.72rem;font-size:.74rem;font-weight:800;
  }
  .ere-msg__mini-btn:hover{background:#eff6ff;border-color:#8fc0e8}
  .ere-msg__toolbar{
    display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.3rem .22rem .12rem;border-top:1px solid #e2e8f0;background:transparent;
  }
  .ere-msg__toolbar-left,.ere-msg__toolbar-right{display:flex;align-items:center;gap:.35rem}
  .ere-msg__tool-btn{
    width:1.95rem;height:1.95rem;border-radius:.52rem;border:1px solid #bfd8ee;background:#fff;color:#0f4f80;font-size:.86rem;font-weight:700;
    display:inline-flex;align-items:center;justify-content:center;
  }
  .ere-msg__tool-btn:hover{background:#eff6ff;border-color:#8fc0e8}
  .ere-msg__tool-btn.is-on,.ere-msg__tool-btn.is-recording{background:#dbeafe;border-color:#60a5fa;color:#1d4ed8}
  .ere-msg__popover{
    position:absolute;left:.55rem;bottom:3.05rem;z-index:8;background:#fff;border:1px solid #dbe7f5;border-radius:.6rem;box-shadow:0 18px 28px -18px rgba(15,23,42,.65);
    padding:.35rem;display:flex;gap:.25rem;flex-wrap:wrap;max-width:260px;
  }
  .ere-msg__popover[hidden]{display:none!important}
  .ere-msg__popover button{border:1px solid #dbe7f5;background:#fff;border-radius:.45rem;padding:.28rem .45rem;font-size:.72rem}
  .ere-msg__popover button:hover{background:#eff6ff}
  .ere-msg__popover--wide{max-width:330px}
  .ere-msg mark{background:#fde68a;color:inherit;border-radius:.22rem;padding:0 .12rem}
  .ere-msg mark.is-active{background:#f59e0b;color:#111827}
  .ere-msg__msg--removed{font-style:italic;opacity:.76}
  .ere-msg__link-preview{margin-top:.38rem;border:1px solid rgba(148,163,184,.35);border-radius:.55rem;padding:.4rem .5rem;background:rgba(255,255,255,.52)}
  .ere-msg__link-preview a{font-size:.72rem;color:inherit;text-decoration:none}
  .ere-msg__link-preview-title{font-weight:700;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .ere-msg__link-preview-domain{display:block;font-size:.64rem;opacity:.75}
  .ere-msg__log{flex:1;overflow:auto;padding:.95rem;background:
    radial-gradient(80% 100% at 100% 0%, rgba(59,130,246,.08) 0%, rgba(59,130,246,0) 55%),
    linear-gradient(180deg,#f8fbff,#fff)}
  .ere-msg__empty{
    padding:1.25rem;border:1px dashed #b9d7f0;border-radius:.8rem;background:#fff;color:#64748b;font-size:.82rem;
    box-shadow:0 8px 20px -18px rgba(15,23,42,.5);
  }
  .ere-msg__bubble-row{display:flex;margin:0 0 .52rem}
  .ere-msg__bubble-row.mine{justify-content:flex-end}
  .ere-msg__bubble-group-head{display:flex;align-items:center;gap:.45rem;margin:.75rem 0 .25rem;color:#64748b;font-size:.66rem;font-weight:700}
  .ere-msg__bubble-group-head.mine{justify-content:flex-end}
  .ere-msg__bubble-group-name{max-width:48%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .ere-msg__bubble-group-time{opacity:.82}
  .ere-msg__bubble{
    max-width:min(78%,560px);padding:.58rem .66rem;border-radius:.78rem;border:1px solid #dbe7f5;
    background:linear-gradient(180deg,#fff 0%,#f8fbff 100%);color:#0f172a;font-size:.8rem;line-height:1.5;
    box-shadow:0 10px 18px -18px rgba(15,23,42,.6);
  }
  .ere-msg__bubble code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.72rem;background:rgba(15,23,42,.08);padding:.08rem .28rem;border-radius:.3rem}
  .ere-msg__bubble ul{margin:.2rem 0 .2rem 1rem;padding:0}
  .ere-msg__bubble li{margin:.05rem 0}
  .ere-msg__log-skeleton{padding:.3rem 0}
  .ere-msg__log-skel-row{display:flex;margin:0 0 .62rem}
  .ere-msg__log-skel-row.mine{justify-content:flex-end}
  .ere-msg__log-skel-bubble{width:min(72%,520px);border-radius:.76rem;padding:.65rem;border:1px solid #dbe7f5;background:rgba(255,255,255,.9)}
  .ere-msg__log-skel-bubble .ere-msg__skeleton-line{height:.55rem;margin-bottom:.32rem}
  .ere-msg__log-skel-bubble .ere-msg__skeleton-line:last-child{margin-bottom:0;width:58%}
  @keyframes ereMsgShimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
  .ere-msg__bubble-row.mine .ere-msg__bubble{
    background:linear-gradient(145deg,#1665A0 0%,#0f4f80 100%);color:#fff;border-color:#1665A0;
    box-shadow:0 14px 22px -20px rgba(13,79,128,.9);
  }
  .ere-msg__bubble-meta{display:block;margin-top:.23rem;font-size:.64rem;opacity:.72}
  .ere-msg__bubble-tools{margin-top:.28rem;display:flex;gap:.45rem;align-items:center}
  .ere-msg__read-hint{font-size:.63rem;opacity:.76}
  .ere-msg__undo-btn{border:none;background:transparent;color:inherit;font-size:.65rem;font-weight:700;text-decoration:underline;padding:0;cursor:pointer}
  .ere-msg__attachment-input{display:none}
  .ere-msg__attachment-preview{
    display:flex;align-items:center;gap:.45rem;margin:.18rem .18rem 0;padding:.28rem .42rem;border:1px dashed #bfd8ee;border-radius:.55rem;background:#f8fbff;font-size:.69rem;color:#0f3960;
  }
  .ere-msg__attachment-preview img{width:44px;height:44px;border-radius:.45rem;object-fit:cover;border:1px solid #dbe7f5}
  .ere-msg__attachment-preview button{margin-left:auto;border:1px solid #bfd8ee;background:#fff;border-radius:.4rem;padding:.12rem .35rem;font-size:.66rem}
  .ere-msg__send[disabled]{opacity:.45;cursor:not-allowed;transform:none!important;filter:none!important;box-shadow:none!important}
  .ere-msg__canned{border:1px solid #c9dff1;background:#fff;border-radius:.6rem;padding:.28rem .42rem;font-size:.69rem;max-width:180px}
  .ere-msg__composer-foot{display:flex;justify-content:space-between;align-items:center;padding:0 .95rem .55rem;font-size:.66rem;color:#64748b}
  .ere-msg__composer-hint{opacity:.8}
  .ere-msg__composer-count{font-weight:700}
  .ere-msg__typing{
    flex-shrink:0;padding:0 .95rem .4rem;font-size:.78rem;font-weight:700;color:#1665A0;min-height:1.2rem;letter-spacing:.01em;
  }
  .ere-msg--admin .ere-msg__typing{color:#7dd3fc}
  .ere-msg__composer{
    padding:.85rem 1rem;border-top:1px solid #dbe7f5;display:flex;align-items:flex-end;gap:.72rem;background:linear-gradient(180deg,#fff,#f8fbff);
  }
  .ere-msg__composer-box{
    position:relative;flex:1;border:1px solid #bfd8ee;border-radius:.85rem;background:#fff;box-shadow:inset 0 1px 0 rgba(255,255,255,.9);padding:.3rem .36rem .28rem;
  }
  .ere-msg__input{
    width:100%;border:none;border-radius:.65rem;padding:.46rem .52rem;font-size:.83rem;line-height:1.45;resize:none;min-height:2.25rem;max-height:8rem;background:transparent;
  }
  .ere-msg__composer-box:focus-within{border-color:#1665A0;box-shadow:0 0 0 3px rgba(22,101,160,.12)}
  .ere-msg__input:focus{outline:none;box-shadow:none}
  .ere-msg__send{
    flex-shrink:0;align-self:center;display:inline-flex;gap:.45rem;align-items:center;justify-content:center;border:1px solid #155d96;
    background:linear-gradient(155deg,#1a6fb8 0%,#124a78 48%,#0f4f80 100%);color:#fff;border-radius:.85rem;padding:0 1.05rem;min-height:2.85rem;font-size:.8rem;font-weight:800;cursor:pointer;
    box-shadow:0 6px 18px -8px rgba(15,79,128,.85),inset 0 1px 0 rgba(255,255,255,.22);
    transition:transform .2s cubic-bezier(.22,1,.36,1),box-shadow .2s ease,filter .2s ease,background .2s ease,border-color .2s ease;
  }
  .ere-msg__send i{font-size:1.02rem;opacity:.95}
  .ere-msg__send:hover{
    transform:translateY(-2px) scale(1.02);
    filter:brightness(1.06);
    box-shadow:0 12px 28px -10px rgba(15,79,128,.95),0 0 0 1px rgba(255,255,255,.12) inset;
    border-color:#38bdf8;
  }
  .ere-msg__send:active{
    transform:translateY(0) scale(.98);
    filter:brightness(.97);
    box-shadow:0 4px 12px -8px rgba(13,79,128,.9),inset 0 2px 6px rgba(0,0,0,.12);
  }
  .ere-msg__send:focus-visible{outline:2px solid #1665A0;outline-offset:3px}
  .ere-msg-topbar-badge{position:absolute;top:-3px;right:-3px;display:none;min-width:1rem;height:1rem;padding:0 .2rem;border-radius:9999px;background:#dc2626;color:#fff;font-size:.62rem;line-height:1rem;font-weight:800}
  .ere-msg-topbar-badge.is-on{display:inline-flex;align-items:center;justify-content:center}
  .ere-msg--admin .ere-msg__panel{
    background:linear-gradient(160deg,#111827 0%,#0b1220 44%,#0f172a 100%);
    border-left:1px solid rgba(148,163,184,.2);
  }
  .ere-msg--admin .ere-msg__head{
    border-bottom:1px solid rgba(148,163,184,.26);
    background:linear-gradient(115deg,#111827 0%,#0f172a 55%,#1e293b 100%);
  }
  .ere-msg--admin .ere-msg__threads{
    border-right:1px solid rgba(148,163,184,.2);
    background:linear-gradient(180deg,#0f172a 0%,#111827 100%);
  }
  .ere-msg--admin .ere-msg__threads-head,
  .ere-msg--admin .ere-msg__thread-time,
  .ere-msg--admin .ere-msg__thread-preview{color:#94a3b8}
  .ere-msg--admin .ere-msg__search{border-color:#334155;background:#0b1220;color:#e2e8f0}
  .ere-msg--admin .ere-msg__search:focus{border-color:#38bdf8;box-shadow:0 0 0 2px rgba(56,189,248,.2)}
  .ere-msg--admin .ere-msg__mini-btn{border-color:#334155;background:#0b1220;color:#bae6fd}
  .ere-msg--admin .ere-msg__mini-btn:hover{border-color:#38bdf8;background:#0f172a}
  .ere-msg--admin .ere-msg__history-trigger{border-color:#334155;background:#0b1220;color:#bae6fd}
  .ere-msg--admin .ere-msg__history-trigger:hover{border-color:#38bdf8;background:#0f172a}
  .ere-msg--admin .ere-msg__history-trigger.is-on{background:#0c4a6e;border-color:#0ea5e9;color:#bae6fd}
  .ere-msg--admin .ere-msg__history{border-color:#334155;background:#0f172a}
  .ere-msg--admin .ere-msg__history-head,.ere-msg--admin .ere-msg__history-filters{border-color:#334155}
  .ere-msg--admin .ere-msg__history-chip{border-color:#334155;background:#111827;color:#e2e8f0}
  .ere-msg--admin .ere-msg__history-chip.is-on{background:#0c4a6e;border-color:#0ea5e9;color:#bae6fd}
  .ere-msg--admin .ere-msg__history-item{border-color:#334155;background:#111827}
  .ere-msg--admin .ere-msg__history-meta span{color:#94a3b8}
  .ere-msg--admin .ere-msg__tool-btn{border-color:#334155;background:#0b1220;color:#bae6fd}
  .ere-msg--admin .ere-msg__tool-btn:hover{border-color:#38bdf8;background:#0f172a}
  .ere-msg--admin .ere-msg__thread-pin{border-color:#334155;background:#0b1220;color:#bae6fd}
  .ere-msg--admin .ere-msg__thread-pin:hover{border-color:#38bdf8}
  .ere-msg--admin .ere-msg__thread{
    border-color:#334155;background:linear-gradient(180deg,#111827 0%,#0f172a 100%);color:#e2e8f0;
  }
  .ere-msg--admin .ere-msg__thread-skeleton{border-color:#334155;background:linear-gradient(180deg,#111827 0%,#0f172a 100%)}
  .ere-msg--admin .ere-msg__thread-skel-avatar,.ere-msg--admin .ere-msg__skeleton-line{background:linear-gradient(90deg,rgba(100,116,139,.22),rgba(148,163,184,.34),rgba(100,116,139,.22));background-size:200% 100%}
  .ere-msg--admin .ere-msg__thread:hover{border-color:#475569}
  .ere-msg--admin .ere-msg__thread-avatar-media{
    background:#1e293b;border-color:rgba(148,163,184,.45);color:#e2e8f0;
  }
  .ere-msg--admin .ere-msg__thread.is-active{
    border-color:#38bdf8;background:linear-gradient(180deg,#0b2134 0%,#0e2438 100%);
  }
  .ere-msg--admin .ere-msg__chat{background:linear-gradient(180deg,#0f172a,#111827)}
  .ere-msg--admin .ere-msg__chat-head{
    border-bottom:1px solid rgba(148,163,184,.2);
    color:#e2e8f0;background:linear-gradient(180deg,#0f172a,#111827);
  }
  .ere-msg--admin .ere-msg__log{
    background:
      radial-gradient(80% 100% at 100% 0%, rgba(14,165,233,.14) 0%, rgba(14,165,233,0) 55%),
      linear-gradient(180deg,#0b1220,#111827);
  }
  .ere-msg--admin .ere-msg__link-preview{border-color:#334155;background:rgba(15,23,42,.66)}
  .ere-msg--admin .ere-msg mark{background:#facc15;color:#111827}
  .ere-msg--admin .ere-msg__empty{border-color:#334155;background:#0f172a;color:#94a3b8;}
  .ere-msg--admin .ere-msg__bubble{
    border-color:#334155;background:linear-gradient(180deg,#111827 0%,#0f172a 100%);color:#e2e8f0;
  }
  .ere-msg--admin .ere-msg__bubble code{background:rgba(148,163,184,.2);color:#e2e8f0}
  .ere-msg--admin .ere-msg__bubble-group-head{color:#94a3b8}
  .ere-msg--admin .ere-msg__log-skel-bubble{border-color:#334155;background:#0f172a}
  .ere-msg--admin .ere-msg__bubble-row.mine .ere-msg__bubble{
    background:linear-gradient(145deg,#0ea5e9 0%,#0284c7 100%);border-color:#0284c7;color:#fff;
  }
  .ere-msg--admin .ere-msg__composer{
    border-top:1px solid rgba(148,163,184,.22);background:linear-gradient(180deg,#0f172a,#111827);
    align-items:center;
  }
  .ere-msg--admin .ere-msg__composer-box{border-color:#334155;background:#0b1220;box-shadow:none}
  .ere-msg--admin .ere-msg__input{
    background:#0b1220;color:#e2e8f0;box-shadow:none;
  }
  .ere-msg--admin .ere-msg__input::placeholder{color:#64748b}
  .ere-msg--admin .ere-msg__composer-box:focus-within{border-color:#38bdf8;box-shadow:0 0 0 3px rgba(14,165,233,.18)}
  .ere-msg--admin .ere-msg__send{
    border-color:#0ea5e9;background:linear-gradient(155deg,#22d3ee 0%,#0ea5e9 45%,#0369a1 100%);
    box-shadow:0 6px 22px -8px rgba(14,165,233,.55),inset 0 1px 0 rgba(255,255,255,.25);
  }
  .ere-msg--admin .ere-msg__send:hover{
    border-color:#67e8f9;
    box-shadow:0 14px 32px -10px rgba(14,165,233,.65),inset 0 1px 0 rgba(255,255,255,.2);
  }
  .ere-msg--admin .ere-msg__send:active{
    box-shadow:0 4px 14px -8px rgba(14,165,233,.5),inset 0 2px 8px rgba(0,0,0,.2);
  }
  .ere-msg--admin .ere-msg__send:focus-visible{outline-color:#38bdf8}
  .ere-msg--admin .ere-msg__away-banner{background:#082f49;color:#bae6fd;border-bottom-color:#0ea5e9}
  .ere-msg--admin .ere-msg__canned{border-color:#334155;background:#0b1220;color:#e2e8f0}
  .ere-msg--admin .ere-msg__attachment-preview{border-color:#334155;background:#0f172a;color:#bae6fd}
  .ere-msg--admin .ere-msg__attachment-preview button{border-color:#334155;background:#111827;color:#e2e8f0}
  .ere-msg--admin .ere-msg__popover{background:#0f172a;border-color:#334155}
  .ere-msg--admin .ere-msg__popover button{border-color:#334155;background:#111827;color:#e2e8f0}
  .ere-msg--admin .ere-msg__composer-foot{color:#94a3b8}
  .ere-msg__api-error{
    margin:.45rem;padding:.65rem .72rem;border-radius:.65rem;font-size:.78rem;line-height:1.45;
    border:1px solid rgba(248,113,113,.45);background:rgba(127,29,29,.25);color:#fecaca;
  }
  .ere-msg--reviewee .ere-msg__api-error{border-color:#fca5a5;background:#fef2f2;color:#991b1b}
  .ere-msg__api-error button{
    margin-top:.45rem;display:inline-flex;align-items:center;gap:.35rem;padding:.35rem .6rem;border-radius:.5rem;
    border:1px solid rgba(255,255,255,.35);background:rgba(255,255,255,.12);color:inherit;font-size:.74rem;font-weight:700;cursor:pointer;
  }
  .ere-msg--reviewee .ere-msg__api-error button{border-color:#cbd5e1;background:#fff;color:#0f172a}
  @media (max-width: 900px){
    .ere-msg__panel{width:100%}
    .ere-msg__body,.ere-msg__body.ere-msg__body--single{grid-template-columns:1fr}
    .ere-msg__threads{max-height:38%;border-right:none;border-bottom:1px solid #e2e8f0}
    .ere-msg__history{top:3.1rem;left:0;right:0;bottom:0;width:auto;border-left:none;border-top:1px solid #dbe7f5}
  }
  @media (prefers-reduced-motion:reduce){
    .ere-msg__skeleton-line,.ere-msg__thread-skel-avatar{animation:none!important;background-size:100% 100%}
  }
</style>

<script>
(function () {
  var root = document.getElementById('ereMsgRoot');
  if (!root) return;
  var openBtns = document.querySelectorAll('[data-message-toggle]');
  var closeBtns = root.querySelectorAll('[data-ere-msg-close]');
  var threadsWrap = document.getElementById('ereMsgThreadsWrap');
  var bodyWrap = root.querySelector('.ere-msg__body');
  var threadsEl = document.getElementById('ereMsgThreads');
  var chatEl = root.querySelector('.ere-msg__chat');
  var logEl = document.getElementById('ereMsgLog');
  var headEl = document.getElementById('ereMsgChatHead');
  var threadSearchEl = document.getElementById('ereMsgThreadSearch');
  var msgSearchEl = document.getElementById('ereMsgSearch');
  var msgSearchPrevEl = document.getElementById('ereMsgSearchPrev');
  var msgSearchNextEl = document.getElementById('ereMsgSearchNext');
  var historyBtnEl = document.getElementById('ereMsgHistoryBtn');
  var historyPanelEl = document.getElementById('ereMsgHistoryPanel');
  var historyCloseEl = document.getElementById('ereMsgHistoryClose');
  var historyListEl = document.getElementById('ereMsgHistoryList');
  var awayBannerEl = document.getElementById('ereMsgAwayBanner');
  var toolbarEl = document.getElementById('ereMsgToolbar');
  var fmtBtnEl = document.getElementById('ereMsgFmtBtn');
  var emojiBtnEl = document.getElementById('ereMsgEmojiBtn');
  var gifBtnEl = document.getElementById('ereMsgGifBtn');
  var voiceBtnEl = document.getElementById('ereMsgVoiceBtn');
  var fmtMenuEl = document.getElementById('ereMsgFmtMenu');
  var emojiMenuEl = document.getElementById('ereMsgEmojiMenu');
  var gifMenuEl = document.getElementById('ereMsgGifMenu');
  var attachInputEl = document.getElementById('ereMsgAttachment');
  var attachBtnEl = document.getElementById('ereMsgAttachBtn');
  var cannedEl = document.getElementById('ereMsgCanned');
  var sendHintEl = document.getElementById('ereMsgSendHint');
  var charCountEl = document.getElementById('ereMsgCharCount');
  var attachmentPreviewEl = document.getElementById('ereMsgAttachmentPreview');
  var form = document.getElementById('ereMsgComposer');
  var input = document.getElementById('ereMsgInput');
  var sendBtnEl = form ? form.querySelector('button[type="submit"]') : null;
  var badges = document.querySelectorAll('.ere-msg-topbar-badge');
  var initialRole = (root.getAttribute('data-msg-role') || '').trim();
  var initialIsStaff = root.getAttribute('data-msg-is-staff') === '1';
  var state = { role: initialRole, userId: 0, selectedContactId: 0, threadStudentId: 0, threads: [], messages: [], stream: null, poll: null, pollSlow: null, presencePoll: null, badgePoll: null, loadingThreads: false, loadingMessages: false, bootstrapped: false, threadFilter: '', messageFilter: '', matchIdx: 0, matchCount: 0, rateLimitedUntil: 0, lastNotifiedUnread: 0, unreadPrimed: false, historyItems: [], historyDir: 'all', historyKind: 'all', lastApiError: '' };
  var draftDebounce = null;
  var previewCache = {};
  var pendingVoiceBlob = null;
  var mediaRecorder = null;
  var mediaChunks = [];
  var attachmentPreviewUrl = '';
  var MSG_PRESENCE_MS = 10000;

  function msgApiUrl(path){
    path = String(path || '').replace(/^\//, '');
    var pathname = location.pathname || '/';
    var dir = pathname.replace(/\/[^/]*$/, '/');
    if (dir === '//') dir = '/';
    return dir + path;
  }
  function esc(s){ return String(s||'').replace(/[&<>"']/g, function(ch){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[ch]; });}
  function escRe(s){ return String(s||'').replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
  function bodyWithLinks(txt){
    var raw = String(txt || '');
    var html = esc(raw);
    var re = /(https?:\/\/[^\s<>"']+)/ig;
    return html.replace(re, function(u){
      var safe = esc(u);
      return '<a href="'+safe+'" target="_blank" rel="noopener noreferrer">'+safe+'</a>';
    });
  }
  function markdownLite(txt){
    var s = String(txt || '');
    var html = bodyWithLinks(s);
    html = html.replace(/`([^`\n]{1,200})`/g, '<code>$1</code>');
    html = html.replace(/\*\*([^*\n]{1,300})\*\*/g, '<strong>$1</strong>');
    var lines = html.split(/\r?\n/);
    var out = '';
    var inList = false;
    for (var i = 0; i < lines.length; i++) {
      var ln = lines[i];
      var m = ln.match(/^\s*[-*]\s+(.+)/);
      if (m) {
        if (!inList) { out += '<ul>'; inList = true; }
        out += '<li>' + m[1] + '</li>';
      } else {
        if (inList) { out += '</ul>'; inList = false; }
        out += (out ? '<br>' : '') + ln;
      }
    }
    if (inList) out += '</ul>';
    return out;
  }
  function messageRemoved(body){
    return String(body || '').trim() === '[message removed]';
  }
  function messageFirstUrl(body){
    var m = String(body || '').match(/https?:\/\/[^\s<>"']+/i);
    return m ? m[0] : '';
  }
  function fmt(ts){
    var d = new Date(ts.replace(' ','T'));
    if (isNaN(d.getTime())) return '';
    return d.toLocaleString([], { month:'short', day:'numeric', hour:'numeric', minute:'2-digit' });
  }
  function parseSqlTs(ts){
    var s = String(ts || '').trim();
    if (!s) return null;
    var d = new Date(s.replace(' ','T'));
    return isNaN(d.getTime()) ? null : d;
  }
  function relTime(ts){
    var d = parseSqlTs(ts);
    if (!d) return '';
    var sec = Math.max(0, Math.floor((Date.now() - d.getTime()) / 1000));
    if (sec < 15) return 'just now';
    if (sec < 60) return sec + 's ago';
    var min = Math.floor(sec / 60);
    if (min < 60) return min + 'm ago';
    var hr = Math.floor(min / 60);
    if (hr < 24) return hr + 'h ago';
    var day = Math.floor(hr / 24);
    if (day < 7) return day + 'd ago';
    return fmt(String(ts || '')) || '';
  }
  function threadSkeletonHtml(n){
    var count = Math.max(2, Math.min(6, Number(n || 4)));
    var out = '';
    for (var i = 0; i < count; i++) {
      out += '<div class="ere-msg__thread-skeleton" aria-hidden="true">'+
        '<span class="ere-msg__thread-skel-avatar"></span>'+
        '<span class="ere-msg__thread-skel-main">'+
          '<span class="ere-msg__skeleton-line"></span>'+
          '<span class="ere-msg__skeleton-line"></span>'+
        '</span></div>';
    }
    return out;
  }
  function logSkeletonHtml(){
    return '<div class="ere-msg__log-skeleton" aria-hidden="true">'+
      '<div class="ere-msg__log-skel-row"><div class="ere-msg__log-skel-bubble"><span class="ere-msg__skeleton-line" style="width:92%"></span><span class="ere-msg__skeleton-line" style="width:72%"></span></div></div>'+
      '<div class="ere-msg__log-skel-row mine"><div class="ere-msg__log-skel-bubble"><span class="ere-msg__skeleton-line" style="width:88%"></span><span class="ere-msg__skeleton-line" style="width:56%"></span></div></div>'+
      '<div class="ere-msg__log-skel-row"><div class="ere-msg__log-skel-bubble"><span class="ere-msg__skeleton-line" style="width:83%"></span><span class="ere-msg__skeleton-line" style="width:63%"></span></div></div>'+
    '</div>';
  }
  function draftKey(){
    var tid = getThreadStudentId();
    return 'ere_msg_draft_' + String(tid || 0);
  }
  function loadDraft(){
    if (!input) return;
    var k = draftKey();
    try {
      var v = sessionStorage.getItem(k);
      if (typeof v === 'string') input.value = v;
    } catch (e1) {}
    autoResizeInput();
    updateComposerMeta();
    updateSendState();
  }
  function saveDraft(){
    if (!input) return;
    var k = draftKey();
    try { sessionStorage.setItem(k, String(input.value || '')); } catch (e1) {}
    updateComposerMeta();
  }
  function clearDraft(){
    try { sessionStorage.removeItem(draftKey()); } catch (e1) {}
    autoResizeInput();
    updateComposerMeta();
    updateSendState();
  }
  function updateComposerMeta(){
    var len = String(input && input.value || '').length;
    if (charCountEl) charCountEl.textContent = String(len) + ' / 15000';
    var remain = Math.max(0, 15000 - len);
    if (sendHintEl) {
      sendHintEl.textContent = (state.rateLimitedUntil > Date.now())
        ? ('Slow down: wait ' + Math.ceil((state.rateLimitedUntil - Date.now()) / 1000) + 's')
        : ((pendingVoiceBlob ? 'Voice message ready. Press Send.' : 'Enter to send · Shift+Enter newline · Ctrl/Cmd+Enter send') + ' · ' + remain + ' chars left');
    }
  }
  function autoResizeInput(){
    if (!input) return;
    input.style.height = 'auto';
    var next = Math.max(40, Math.min(input.scrollHeight, 240));
    input.style.height = next + 'px';
  }
  function hasSendablePayload(){
    var body = String(input && input.value || '').trim();
    var hasFile = !!(attachInputEl && attachInputEl.files && attachInputEl.files[0]);
    return body.length > 0 || hasFile || !!pendingVoiceBlob;
  }
  function updateSendState(){
    if (!sendBtnEl) return;
    sendBtnEl.disabled = !hasSendablePayload();
  }
  function clearAttachmentPreview(){
    if (!attachmentPreviewEl) return;
    if (attachmentPreviewUrl) {
      try { URL.revokeObjectURL(attachmentPreviewUrl); } catch (e1) {}
      attachmentPreviewUrl = '';
    }
    attachmentPreviewEl.hidden = true;
    attachmentPreviewEl.innerHTML = '';
    updateSendState();
  }
  function renderAttachmentPreview(file){
    if (!attachmentPreviewEl) return;
    if (!file) { clearAttachmentPreview(); return; }
    if (attachmentPreviewUrl) {
      try { URL.revokeObjectURL(attachmentPreviewUrl); } catch (e1) {}
      attachmentPreviewUrl = '';
    }
    var sizeKb = Math.max(1, Math.round((Number(file.size || 0) / 1024)));
    var mime = String(file.type || '');
    var isImg = mime.indexOf('image/') === 0;
    if (isImg) attachmentPreviewUrl = URL.createObjectURL(file);
    var thumb = isImg ? '<img src="'+esc(attachmentPreviewUrl)+'" alt="Attachment preview">' : '<span>📄</span>';
    attachmentPreviewEl.hidden = false;
    attachmentPreviewEl.innerHTML = thumb + '<span>'+esc(file.name || 'Attachment')+' · '+sizeKb+' KB</span><button type="button" id="ereMsgAttachRemove">Remove</button>';
    var rm = document.getElementById('ereMsgAttachRemove');
    if (rm) rm.addEventListener('click', function(){
      if (attachInputEl) attachInputEl.value = '';
      pendingVoiceBlob = null;
      clearAttachmentPreview();
      updateComposerMeta();
    }, { once:true });
    updateSendState();
  }
  function showPopover(kind){
    var list = [fmtMenuEl, emojiMenuEl, gifMenuEl];
    var btns = [fmtBtnEl, emojiBtnEl, gifBtnEl];
    list.forEach(function(el){ if (el) el.hidden = true; });
    btns.forEach(function(b){ if (b) b.classList.remove('is-on'); });
    if (kind === 'fmt' && fmtMenuEl) { fmtMenuEl.hidden = false; if (fmtBtnEl) fmtBtnEl.classList.add('is-on'); }
    if (kind === 'emoji' && emojiMenuEl) { emojiMenuEl.hidden = false; if (emojiBtnEl) emojiBtnEl.classList.add('is-on'); }
    if (kind === 'gif' && gifMenuEl) { gifMenuEl.hidden = false; if (gifBtnEl) gifBtnEl.classList.add('is-on'); }
  }
  function closePopovers(){
    [fmtMenuEl, emojiMenuEl, gifMenuEl].forEach(function(el){ if (el) el.hidden = true; });
    [fmtBtnEl, emojiBtnEl, gifBtnEl].forEach(function(b){ if (b) b.classList.remove('is-on'); });
  }
  function setHistoryOpen(open){
    if (!historyPanelEl) return;
    historyPanelEl.hidden = !open;
    if (historyBtnEl) historyBtnEl.classList.toggle('is-on', !!open);
    if (chatEl) chatEl.classList.toggle('ere-msg__chat--history-open', !!open);
  }
  function renderHistory(){
    if (!historyListEl) return;
    var dir = String(state.historyDir || 'all');
    var kind = String(state.historyKind || 'all');
      var rows = state.historyItems.filter(function(it){
      var okDir = (dir === 'all') || (String(it.direction || '') === dir);
      var okKind = (kind === 'all') || (String(it.kind || '') === kind);
      return okDir && okKind;
    });
    if (!rows.length) {
      historyListEl.innerHTML = '<div class="ere-msg__empty">No items for this filter.</div>';
      return;
    }
      rows.sort(function(a,b){
        var ta = Date.parse(String(a.created_at || '').replace(' ','T')) || 0;
        var tb = Date.parse(String(b.created_at || '').replace(' ','T')) || 0;
        return tb - ta;
      });
      historyListEl.innerHTML = rows.map(function(it){
      var kindIcon = it.kind === 'photo' ? '🖼' : (it.kind === 'audio' ? '🎧' : (it.kind === 'link' ? '🔗' : '📄'));
      var thumb = (it.kind === 'photo') ? '<img src="'+esc(String(it.url||''))+'" alt="'+esc(String(it.name||'photo'))+'">' : '<span style="font-size:1.35rem">'+kindIcon+'</span>';
      return '<a class="ere-msg__history-item" href="'+esc(String(it.url||'#'))+'" target="_blank" rel="noopener noreferrer">'+
        thumb+
        '<span class="ere-msg__history-meta"><strong>'+esc(String(it.name || 'Item'))+'</strong><span>'+esc(String(it.direction || ''))+' · '+esc(String(it.created_at_human || ''))+'</span></span>'+
      '</a>';
    }).join('');
  }
  function loadHistory(){
    var tid = getThreadStudentId();
    if (!tid) return;
    if (historyListEl) historyListEl.innerHTML = '<div class="ere-msg__empty">Loading history...</div>';
    fetch(msgApiUrl('api/messages/history.php?thread_student_id='+encodeURIComponent(String(tid))), { credentials:'same-origin', headers:{Accept:'application/json'} })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d || !d.ok || !Array.isArray(d.items)) {
          if (historyListEl) historyListEl.innerHTML = '<div class="ere-msg__empty">Could not load history.</div>';
          return;
        }
        state.historyItems = d.items;
        renderHistory();
      }).catch(function(){
        if (historyListEl) historyListEl.innerHTML = '<div class="ere-msg__empty">Could not load history.</div>';
      });
  }
  function insertAtCursor(txt){
    if (!input) return;
    var start = input.selectionStart || 0;
    var end = input.selectionEnd || 0;
    var val = String(input.value || '');
    input.value = val.slice(0, start) + txt + val.slice(end);
    var pos = start + txt.length;
    input.selectionStart = pos;
    input.selectionEnd = pos;
    input.focus();
    saveDraft();
  }
  function maybeNotifyUnread(nextUnread){
    nextUnread = Number(nextUnread || 0);
    if (!state.unreadPrimed) {
      state.unreadPrimed = true;
      state.lastNotifiedUnread = nextUnread;
      return;
    }
    if (root.classList.contains('ere-msg--open')) {
      state.lastNotifiedUnread = nextUnread;
      return;
    }
    if (nextUnread <= state.lastNotifiedUnread) return;
    state.lastNotifiedUnread = nextUnread;
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (Ctx) {
        var ctx = new Ctx();
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = 880;
        gain.gain.value = 0.03;
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.08);
      }
    } catch (e1) {}
    if ('Notification' in window && Notification.permission === 'granted') {
      try { new Notification('New message', { body: 'You have new unread message(s).' }); } catch (e2) {}
    }
  }
  function setUnread(n){
    n = Number(n);
    if (!isFinite(n) || n < 0) n = 0;
    maybeNotifyUnread(n);
    var label = n > 0 ? ('Messages (' + (n > 99 ? '99+' : String(n)) + ' unread)') : 'Messages';
    badges.forEach(function(b){
      if (n > 0){ b.textContent = n > 99 ? '99+' : String(n); b.classList.add('is-on'); }
      else { b.textContent = ''; b.classList.remove('is-on'); }
    });
    openBtns.forEach(function(btn){ btn.setAttribute('aria-label', label); });
  }
  function tickUnreadBadge(){
    if (root.classList.contains('ere-msg--open')) return;
    fetch(msgApiUrl('api/messages/unread.php'), { credentials:'same-origin', headers:{Accept:'application/json'} })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d || !d.ok) return;
        if (typeof d.unread_total === 'number') setUnread(d.unread_total);
      }).catch(function(){});
  }
  function stopTopbarUnreadPolling(){
    if (state.badgePoll){
      clearInterval(state.badgePoll);
      state.badgePoll = null;
    }
  }
  function startTopbarUnreadPolling(){
    stopTopbarUnreadPolling();
    state.badgePoll = setInterval(tickUnreadBadge, 8000);
    setTimeout(tickUnreadBadge, 1200);
  }
  function renderThreads(){
    threadsWrap.style.display = '';
    if (bodyWrap) bodyWrap.classList.remove('ere-msg__body--single');
    if (state.loadingThreads){
      threadsEl.innerHTML = threadSkeletonHtml(state.threads.length || 4);
      return;
    }
    if (state.lastApiError){
      threadsEl.innerHTML = '<div class="ere-msg__api-error" role="alert">'+
        '<strong>Could not load contacts.</strong><br />'+esc(state.lastApiError)+
        '<br /><button type="button" id="ereMsgRetryBtn">Try again</button></div>';
      var rb = document.getElementById('ereMsgRetryBtn');
      if (rb) rb.addEventListener('click', function(){ state.lastApiError = ''; refreshAll(false); }, { once:true });
      return;
    }
    var list = state.threads.slice();
    var needle = String(state.threadFilter || '').trim().toLowerCase();
    if (needle !== '') {
      list = list.filter(function(t){
        var name = String(t.contact_name || '').toLowerCase();
        var prev = String(t.last_preview || '').toLowerCase();
        return name.indexOf(needle) >= 0 || prev.indexOf(needle) >= 0;
      });
    }
    if (!list.length){
      threadsEl.innerHTML = '<div class="ere-msg__empty">No contacts found. Ask your developer to confirm reviewee accounts use roles <strong>student</strong> or <strong>college_student</strong>, and staff use <strong>admin</strong> or <strong>professor_admin</strong>.</div>';
      return;
    }
    threadsEl.innerHTML = list.map(function(t){
      var active = Number(t.contact_id) === Number(state.selectedContactId) ? ' is-active' : '';
      var badge = t.unread_count > 0 ? '<span class="ere-msg__thread-badge">'+(t.unread_count>99?'99+':t.unread_count)+'</span>' : '';
      var src = String(t.avatar_src || '').trim();
      var initial = esc(t.avatar_initial || '?');
      var online = !!t.session_active;
      var dotClass = online ? 'ere-msg__thread-status-dot--active' : 'ere-msg__thread-status-dot--inactive';
      var dotTitle = online ? 'Session active' : 'Session inactive';
      var avInner = src !== '' ? '<img src="'+esc(src)+'" alt="" loading="lazy">' : initial;
      var cname = String(t.contact_name || ('Contact #' + t.contact_id));
      var abs = String(t.last_at_human || fmt(String(t.last_at || '')) || '');
      var rel = relTime(String(t.last_at || '')) || abs;
      var timeTitle = abs !== '' ? ' title="'+esc(abs)+'"' : '';
      var pinned = !!t.is_pinned;
      var pinBtn = (isStaffRole(state.role))
        ? ('<button type="button" class="ere-msg__thread-pin" data-pin-thread="'+Number(t.contact_id)+'" data-pin-state="'+(pinned ? '1':'0')+'" title="'+(pinned?'Unpin':'Pin')+'">'+(pinned?'★':'☆')+'</button>')
        : '';
      return '<div class="ere-msg__thread-row">'+pinBtn+'<button type="button" class="ere-msg__thread'+active+'" data-contact-id="'+Number(t.contact_id)+'" aria-label="Open conversation with '+esc(cname)+'">'+
        '<span class="ere-msg__thread-avatar-wrap" aria-hidden="true">'+
          '<span class="ere-msg__thread-avatar-media">'+avInner+'</span>'+
          '<span class="ere-msg__thread-status-dot '+dotClass+'" title="'+esc(dotTitle)+'"></span>'+
        '</span>'+
        '<span class="ere-msg__thread-main">'+
          '<div class="ere-msg__thread-top">'+
            '<span class="ere-msg__thread-name">'+esc(t.contact_name||('Contact #'+t.contact_id))+'</span>'+
            '<span class="ere-msg__thread-meta"><span class="ere-msg__thread-time"'+timeTitle+'>'+esc(rel || '')+'</span>'+badge+'</span>'+
          '</div>'+
          '<div class="ere-msg__thread-preview">'+esc(t.last_preview||'No messages yet')+'</div>'+
        '</span>'+
      '</button></div>';
    }).join('');
  }
  function renderMessages(){
    if (state.loadingMessages){
      logEl.innerHTML = logSkeletonHtml();
      return;
    }
    if (state.lastApiError){
      logEl.innerHTML = '<div class="ere-msg__empty" role="status">Fix the issue above, then use <strong>Try again</strong>.</div>';
      return;
    }
    if (!state.messages.length){ logEl.innerHTML = '<div class="ere-msg__empty">No messages in this conversation yet.</div>'; state.matchCount = 0; return; }
    var q = String(state.messageFilter || '').trim();
    var qRe = q ? new RegExp(escRe(q), 'ig') : null;
    var latestMine = null;
    for (var mi = state.messages.length - 1; mi >= 0; mi--) {
      if (Number(state.messages[mi].sender_id) === Number(state.userId)) { latestMine = state.messages[mi]; break; }
    }
    var out = '';
    for (var i = 0; i < state.messages.length; i++) {
      var m = state.messages[i];
      var mine = Number(m.sender_id) === Number(state.userId);
      var mineClass = mine ? ' mine' : '';
      var prev = i > 0 ? state.messages[i - 1] : null;
      var nowDate = parseSqlTs(String(m.created_at || ''));
      var prevDate = prev ? parseSqlTs(String(prev.created_at || '')) : null;
      var gapMs = (nowDate && prevDate) ? Math.abs(nowDate.getTime() - prevDate.getTime()) : Number.MAX_SAFE_INTEGER;
      var startsGroup = !prev || Number(prev.sender_id) !== Number(m.sender_id) || gapMs > (5 * 60 * 1000);
      if (startsGroup) {
        var absTime = String(m.created_at_human || fmt(String(m.created_at || '')) || '');
        var rel = relTime(String(m.created_at || '')) || absTime;
        var title = absTime ? ' title="'+esc(absTime)+'"' : '';
        out += '<div class="ere-msg__bubble-group-head'+mineClass+'"><span class="ere-msg__bubble-group-name">'+esc(m.sender_name || m.sender_role || 'User')+'</span><span class="ere-msg__bubble-group-time"'+title+'>'+esc(rel)+'</span></div>';
      }
      var bodyHtml = messageRemoved(m.body) ? '<em class="ere-msg__msg--removed">This message was removed.</em>' : markdownLite(m.body);
      if (qRe) bodyHtml = bodyHtml.replace(qRe, '<mark>$&</mark>');
      var previewUrl = (!messageRemoved(m.body)) ? messageFirstUrl(m.body) : '';
      var previewHtml = '';
      if (previewUrl && previewCache[previewUrl] && typeof previewCache[previewUrl] === 'object') {
        var p = previewCache[previewUrl];
        previewHtml = '<div class="ere-msg__link-preview"><a href="'+esc(p.url)+'" target="_blank" rel="noopener noreferrer"><span class="ere-msg__link-preview-title">'+esc(p.title || p.domain || p.url)+'</span><span class="ere-msg__link-preview-domain">'+esc(p.domain || '')+'</span></a></div>';
      }
      var attachmentHtml = '';
      if (m.attachment && m.attachment.download_url) {
        var an = String(m.attachment.orig_name || 'attachment');
        var am = String(m.attachment.mime_type || '');
        var asz = Number(m.attachment.size_bytes || 0);
        var kb = asz > 0 ? Math.max(1, Math.round(asz / 1024)) + ' KB' : '';
        if (am.indexOf('audio/') === 0) {
          attachmentHtml = '<div class="ere-msg__link-preview"><audio controls preload="none" src="'+esc(String(m.attachment.download_url))+'" style="width:100%"></audio><span class="ere-msg__link-preview-domain">'+esc(an + (kb ? (' · ' + kb) : ''))+'</span></div>';
        } else if (am.indexOf('image/') === 0) {
          attachmentHtml = '<div class="ere-msg__link-preview"><a href="'+esc(String(m.attachment.download_url))+'" target="_blank" rel="noopener noreferrer"><img src="'+esc(String(m.attachment.download_url))+'" alt="'+esc(an)+'" style="max-width:220px;max-height:180px;border-radius:.45rem;display:block;margin-bottom:.25rem"><span class="ere-msg__link-preview-domain">'+esc(an + (kb ? (' · ' + kb) : ''))+'</span></a></div>';
        } else {
          attachmentHtml = '<div class="ere-msg__link-preview"><a href="'+esc(String(m.attachment.download_url))+'" target="_blank" rel="noopener noreferrer"><span class="ere-msg__link-preview-title">'+esc(an)+'</span><span class="ere-msg__link-preview-domain">'+esc(am + (kb ? (' · ' + kb) : ''))+'</span></a></div>';
        }
      }
      var tools = '';
      var canUndo = Number(m.sender_id) === Number(state.userId) && !m.read_at && !messageRemoved(m.body);
      if (canUndo && ((Date.now() - (parseSqlTs(String(m.created_at || '')) || new Date()).getTime()) <= 120000)) {
        tools += '<button type="button" class="ere-msg__undo-btn" data-msg-undo="'+Number(m.message_id||0)+'">Undo send</button>';
      }
      if (latestMine && Number(latestMine.message_id) === Number(m.message_id) && Number(m.sender_id) === Number(state.userId)) {
        tools += '<span class="ere-msg__read-hint">'+(m.read_at ? 'Read' : 'Delivered')+'</span>';
      }
      out += '<div class="ere-msg__bubble-row'+mineClass+'" data-msg-id="'+Number(m.message_id||0)+'"><div class="ere-msg__bubble"><div>'+bodyHtml+'</div>'+attachmentHtml+previewHtml+(tools?('<div class="ere-msg__bubble-tools">'+tools+'</div>'):'')+'</div></div>';
    }
    logEl.innerHTML = out;
    state.matchCount = logEl.querySelectorAll('mark').length;
    // Modern chat behavior: always keep view pinned to latest message.
    requestAnimationFrame(function(){
      logEl.scrollTop = logEl.scrollHeight;
    });
    hydrateLinkPreviews();
  }
  function jumpToSearchMatch(dir){
    var marks = logEl.querySelectorAll('mark');
    if (!marks.length) return;
    if (dir > 0) state.matchIdx = (state.matchIdx + 1) % marks.length;
    else if (dir < 0) state.matchIdx = (state.matchIdx - 1 + marks.length) % marks.length;
    marks.forEach(function(m){ m.classList.remove('is-active'); });
    var el = marks[state.matchIdx] || marks[0];
    if (!el) return;
    el.classList.add('is-active');
    el.scrollIntoView({ block:'center', behavior:'smooth' });
  }
  function hydrateLinkPreviews(){
    var urls = [];
    state.messages.forEach(function(m){
      var u = messageFirstUrl(m.body);
      if (u && !previewCache[u]) urls.push(u);
    });
    if (!urls.length) return;
    urls.slice(0, 4).forEach(function(u){
      previewCache[u] = '__pending__';
      fetch(msgApiUrl('api/messages/link_preview.php?url='+encodeURIComponent(u)), { credentials:'same-origin', headers:{Accept:'application/json'} })
        .then(function(r){ return r.json(); })
        .then(function(d){
          if (d && d.ok && d.preview) {
            previewCache[u] = d.preview;
            renderMessages();
          } else {
            delete previewCache[u];
          }
        }).catch(function(){ delete previewCache[u]; });
    });
  }
  function setTypingIndicator(t){
    var el = document.getElementById('ereMsgTyping');
    if (!el) return;
    if (t && t.active && t.user_name){
      el.hidden = false;
      el.textContent = String(t.user_name) + ' is typing…';
    } else {
      el.hidden = true;
      el.textContent = '';
    }
  }
  function mergeNewMessages(incoming){
    if (!incoming || !incoming.length) return;
    var byId = {};
    state.messages.forEach(function(m){ var id = Number(m.message_id||0); if (id) byId[id] = m; });
    incoming.forEach(function(m){ var id = Number(m.message_id||0); if (id) byId[id] = m; });
    state.messages = Object.keys(byId).map(function(k){ return byId[k]; }).sort(function(a,b){ return Number(a.message_id)-Number(b.message_id); });
  }
  function searchInThreadRemote(){
    var q = String(state.messageFilter || '').trim();
    var tid = getThreadStudentId();
    if (q.length < 2 || !tid) return;
    fetch(msgApiUrl('api/messages/search.php?thread_student_id='+encodeURIComponent(String(tid))+'&q='+encodeURIComponent(q)), { credentials:'same-origin', headers:{Accept:'application/json'} })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d || !d.ok || !Array.isArray(d.messages) || !d.messages.length) return;
        mergeNewMessages(d.messages);
        renderMessages();
        jumpToSearchMatch(1);
      }).catch(function(){});
  }
  function threadIdsForPresence(){
    var ids = [];
    state.threads.forEach(function(t){
      var id = Number(t.contact_id || 0);
      if (id) ids.push(id);
    });
    return ids;
  }
  function applyPresenceMap(map){
    if (!map || !state.threads.length) return;
    state.threads.forEach(function(t){
      var id = String(Number(t.contact_id || 0));
      if (id === '0' || id === 'NaN') return;
      if (Object.prototype.hasOwnProperty.call(map, id)) t.session_active = !!map[id];
    });
    state.threads.sort(function(a, b){
      var pa = a.is_pinned ? 1 : 0;
      var pb = b.is_pinned ? 1 : 0;
      if (pa !== pb) return pb - pa;
      var oa = a.session_active ? 1 : 0;
      var ob = b.session_active ? 1 : 0;
      if (oa !== ob) return ob - oa;
      var ta = Date.parse(String(a.last_at || '').replace(' ', 'T'));
      var tb = Date.parse(String(b.last_at || '').replace(' ', 'T'));
      if (isNaN(ta)) ta = 0;
      if (isNaN(tb)) tb = 0;
      if (ta !== tb) return tb - ta;
      return String(a.contact_name || '').localeCompare(String(b.contact_name || ''), undefined, { sensitivity: 'base' });
    });
    if (!state.lastApiError) renderThreads();
  }
  function pollPresenceOnce(){
    if (!root.classList.contains('ere-msg--open') || state.lastApiError) return;
    var ids = threadIdsForPresence();
    if (!ids.length) return;
    var q = 'ids=' + encodeURIComponent(ids.join(','));
    fetch(msgApiUrl('api/messages/presence.php?' + q), { method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
      .then(function(r){ return r.ok ? r.json() : null; })
      .then(function(d){
        if (!d || !d.ok || !d.presence) return;
        applyPresenceMap(d.presence);
      }).catch(function(){});
  }
  function syncTick(){
    if (!root.classList.contains('ere-msg--open') || state.lastApiError) return;
    var tid = getThreadStudentId();
    if (!tid){ setTypingIndicator(null); return; }
    var after = 0;
    state.messages.forEach(function(m){ var id = Number(m.message_id||0); if (id > after) after = id; });
    fetch(msgApiUrl('api/messages/sync.php?thread_student_id='+encodeURIComponent(String(tid))+'&after_message_id='+encodeURIComponent(String(after))), { credentials:'same-origin', headers:{Accept:'application/json'} })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d || !d.ok) return;
        if (Array.isArray(d.messages) && d.messages.length){
          mergeNewMessages(d.messages);
          renderMessages();
        }
        if (typeof d.unread_total === 'number') setUnread(d.unread_total);
        setTypingIndicator(d.typing || null);
      }).catch(function(){});
  }
  /** Close intervals; long-lived SSE is not used (PHP worker safety). */
  function stopMessagingRealtime(){
    if (state.poll){
      clearInterval(state.poll);
      state.poll = null;
    }
    if (state.pollSlow){
      clearInterval(state.pollSlow);
      state.pollSlow = null;
    }
    if (state.presencePoll){
      clearInterval(state.presencePoll);
      state.presencePoll = null;
    }
    if (state.stream){
      try { state.stream.close(); } catch (e1) {}
      state.stream = null;
    }
    setTypingIndicator(null);
  }
  /** Fast sync for new messages + typing; slower full refresh; presence poll matches admin_students.php (10s). */
  function startMessagingPolling(){
    stopMessagingRealtime();
    state.poll = setInterval(syncTick, 2300);
    state.pollSlow = setInterval(function(){
      if (!root.classList.contains('ere-msg--open')) return;
      refreshAll(false);
    }, 22000);
    state.presencePoll = setInterval(pollPresenceOnce, MSG_PRESENCE_MS);
    setTimeout(syncTick, 450);
    pollPresenceOnce();
  }
  function isMsgPanelOpen(){
    return root.classList.contains('ere-msg--open');
  }
  var ereMsgClosing = false;
  function openPanel(){
    ereMsgClosing = false;
    state.lastApiError = '';
    stopMessagingRealtime();
    root.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    root.classList.add('ere-msg--open');
    setHistoryOpen(false);
    if ('Notification' in window && Notification.permission === 'default') {
      try { Notification.requestPermission(); } catch (e1) {}
    }
    refreshAll(true).then(function(){ startMessagingPolling(); });
  }
  function closePanel(){
    if (!isMsgPanelOpen() || ereMsgClosing) return;
    ereMsgClosing = true;
    stopMessagingRealtime();
    setHistoryOpen(false);
    root.classList.remove('ere-msg--open');
    root.setAttribute('aria-hidden', 'true');
    var panel = root.querySelector('.ere-msg__panel');
    var finished = false;
    function done(){
      if (finished) return;
      finished = true;
      ereMsgClosing = false;
      document.body.style.overflow = '';
    }
    var tid = setTimeout(done, 480);
    function onTe(ev){
      if (!panel || ev.target !== panel || ev.propertyName !== 'transform') return;
      clearTimeout(tid);
      panel.removeEventListener('transitionend', onTe);
      done();
    }
    if (panel){
      panel.addEventListener('transitionend', onTe);
    } else {
      clearTimeout(tid);
      done();
    }
  }
  function isStaffRole(r){
    r = String(r || '');
    if (r === 'admin' || r === 'professor_admin') return true;
    if (initialIsStaff && (r === '')) return true;
    return false;
  }
  function getThreadStudentId(){
    if (isStaffRole(state.role)) return Number(state.selectedContactId || 0);
    return Number(state.userId || 0);
  }
  function getActiveContactParam(){
    if (isStaffRole(state.role)) return '&student_id=' + encodeURIComponent(String(state.selectedContactId || 0));
    return '&admin_id=' + encodeURIComponent(String(state.selectedContactId || 0));
  }
  function refreshAll(markRead){
    if (isMsgPanelOpen()) {
      if (!state.bootstrapped) {
        state.loadingThreads = true;
        state.loadingMessages = true;
        renderThreads();
        renderMessages();
      } else if (markRead) {
        state.loadingMessages = true;
        renderMessages();
      }
    }
    var q = '?scope=full' + getActiveContactParam() + (markRead ? '&mark_read=1' : '');
    return fetch(msgApiUrl('api/messages/bootstrap.php') + q, { credentials:'same-origin', headers:{Accept:'application/json'} })
      .then(function(r){
        return r.text().then(function(text){
          var d = null;
          try { d = JSON.parse(text); } catch (e) {
            state.lastApiError = 'Server returned non-JSON (HTTP '+r.status+'). Check that api/messages/bootstrap.php exists and PHP errors are disabled for APIs.';
            state.loadingThreads = false;
            state.loadingMessages = false;
            headEl.textContent = 'Could not load messages';
            renderThreads();
            renderMessages();
            return null;
          }
          return d;
        });
      })
      .then(function(d){
        if (!d) return;
        if (!d.ok){
          state.lastApiError = d.error || 'Request failed';
          state.loadingThreads = false;
          state.loadingMessages = false;
          headEl.textContent = 'Messages unavailable';
          state.messages = [];
          renderThreads();
          renderMessages();
          return;
        }
        state.lastApiError = '';
        state.role = d.role || state.role;
        state.userId = Number(d.user_id || state.userId || 0);
        state.threadStudentId = Number(d.thread_student_id || state.threadStudentId || 0);
        state.threads = Array.isArray(d.threads) ? d.threads : [];
        state.loadingThreads = false;
        if (!state.selectedContactId) state.selectedContactId = Number(d.active_contact_id || 0);
        if (d.active_contact_id) state.selectedContactId = Number(d.active_contact_id);
        state.messages = Array.isArray(d.messages) ? d.messages : [];
        if (cannedEl) cannedEl.hidden = !isStaffRole(state.role);
        if (awayBannerEl) awayBannerEl.hidden = isStaffRole(state.role);
        loadDraft();
        state.loadingMessages = false;
        state.bootstrapped = true;
        headEl.textContent = d.chat_title || 'Messages';
        setUnread(Number(d.unread_total || 0));
        renderThreads();
        renderMessages();
      }).catch(function(){
        state.lastApiError = 'Network error — could not reach api/messages/bootstrap.php.';
        state.loadingThreads = false;
        state.loadingMessages = false;
        headEl.textContent = 'Could not load messages';
        renderThreads();
        renderMessages();
      });
  }
  function postSendMessage(){
    if (Date.now() < state.rateLimitedUntil) {
      updateComposerMeta();
      return;
    }
    var body = String(input.value || '').trim();
    if (!hasSendablePayload()) { updateSendState(); return; }
    var fd = new FormData();
    fd.append('body', body);
    if (pendingVoiceBlob) {
      fd.append('attachment', pendingVoiceBlob, 'voice-message.webm');
    } else if (attachInputEl && attachInputEl.files && attachInputEl.files[0]) {
      fd.append('attachment', attachInputEl.files[0]);
    }
    if (isStaffRole(state.role)) fd.append('student_id', String(state.selectedContactId || 0));
    else fd.append('admin_id', String(state.selectedContactId || 0));
    fetch(msgApiUrl('api/messages/send.php'), { method:'POST', credentials:'same-origin', body:fd, headers:{Accept:'application/json'} })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d || !d.ok){
          state.lastApiError = (d && d.error) ? d.error : 'Could not send message.';
          if (d && d.retry_after_seconds) {
            state.rateLimitedUntil = Date.now() + (Number(d.retry_after_seconds) * 1000);
            updateComposerMeta();
          }
          renderThreads();
          return;
        }
        input.value = '';
        if (attachInputEl) attachInputEl.value = '';
        pendingVoiceBlob = null;
        clearAttachmentPreview();
        clearDraft();
        state.rateLimitedUntil = 0;
        state.lastApiError = '';
        autoResizeInput();
        updateComposerMeta();
        updateSendState();
        refreshAll(true);
      }).catch(function(){
        state.lastApiError = 'Could not send — network error.';
        renderThreads();
      });
  }
  function sendMessage(ev){
    if (ev && ev.preventDefault) ev.preventDefault();
    postSendMessage();
  }
  openBtns.forEach(function(btn){ btn.addEventListener('click', openPanel); });
  closeBtns.forEach(function(btn){ btn.addEventListener('click', closePanel); });
  if (form) form.addEventListener('submit', sendMessage);
  var typingDebounce = null;
  var lastTypingPost = 0;
  function notifyTyping(){
    if (!root.classList.contains('ere-msg--open')) return;
    var tid = getThreadStudentId();
    if (!tid) return;
    var now = Date.now();
    if (now - lastTypingPost < 1600) return;
    lastTypingPost = now;
    var fd = new FormData();
    fd.append('thread_student_id', String(tid));
    fetch(msgApiUrl('api/messages/typing.php'), { method:'POST', credentials:'same-origin', body: fd }).catch(function(){});
  }
  if (input){
    input.addEventListener('keydown', function(ev){
      if ((ev.ctrlKey || ev.metaKey) && ev.key === 'Enter') {
        ev.preventDefault();
        postSendMessage();
        return;
      }
      if (ev.key !== 'Enter' || ev.shiftKey) return;
      ev.preventDefault();
      postSendMessage();
    });
    input.addEventListener('input', function(){
      clearTimeout(typingDebounce);
      typingDebounce = setTimeout(notifyTyping, 260);
      clearTimeout(draftDebounce);
      draftDebounce = setTimeout(saveDraft, 180);
      autoResizeInput();
      updateSendState();
    });
    input.addEventListener('blur', function(){ clearTimeout(typingDebounce); });
  }
  if (attachBtnEl && attachInputEl) {
    attachBtnEl.addEventListener('click', function(){ attachInputEl.click(); });
    attachInputEl.addEventListener('change', function(){
      if (!attachInputEl.files || !attachInputEl.files[0]) { clearAttachmentPreview(); return; }
      var f = attachInputEl.files[0];
      renderAttachmentPreview(f);
      if (sendHintEl) sendHintEl.textContent = 'Attachment ready: ' + f.name + ' (' + Math.ceil(f.size/1024) + ' KB)';
      updateSendState();
    });
  }
  if (fmtBtnEl) fmtBtnEl.addEventListener('click', function(){
    if (fmtMenuEl && !fmtMenuEl.hidden) { closePopovers(); return; }
    showPopover('fmt');
  });
  if (emojiBtnEl) emojiBtnEl.addEventListener('click', function(){
    if (emojiMenuEl && !emojiMenuEl.hidden) { closePopovers(); return; }
    showPopover('emoji');
  });
  if (gifBtnEl) gifBtnEl.addEventListener('click', function(){
    if (gifMenuEl && !gifMenuEl.hidden) { closePopovers(); return; }
    showPopover('gif');
  });
  closePopovers();
  if (fmtMenuEl) fmtMenuEl.addEventListener('click', function(ev){
    var btn = ev.target.closest('[data-fmt]');
    if (!btn) return;
    var t = btn.getAttribute('data-fmt');
    if (t === 'bold') insertAtCursor('**bold text**');
    else if (t === 'code') insertAtCursor('`code`');
    else if (t === 'list') insertAtCursor('- item 1\n- item 2');
    closePopovers();
  });
  if (emojiMenuEl) emojiMenuEl.addEventListener('click', function(ev){
    var btn = ev.target.closest('[data-emoji]');
    if (!btn) return;
    insertAtCursor(btn.getAttribute('data-emoji') + ' ');
    closePopovers();
  });
  if (gifMenuEl) gifMenuEl.addEventListener('click', function(ev){
    var btn = ev.target.closest('[data-gif]');
    if (!btn) return;
    insertAtCursor(btn.getAttribute('data-gif'));
    closePopovers();
  });
  if (voiceBtnEl) {
    voiceBtnEl.addEventListener('click', async function(){
      if (mediaRecorder && mediaRecorder.state === 'recording') {
        mediaRecorder.stop();
        return;
      }
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof MediaRecorder === 'undefined') {
        if (sendHintEl) sendHintEl.textContent = 'Voice recording is not supported in this browser.';
        return;
      }
      try {
        var stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaChunks = [];
        mediaRecorder = new MediaRecorder(stream);
        mediaRecorder.ondataavailable = function(e){ if (e.data && e.data.size > 0) mediaChunks.push(e.data); };
        mediaRecorder.onstop = function(){
          var blob = new Blob(mediaChunks, { type: mediaRecorder.mimeType || 'audio/webm' });
          pendingVoiceBlob = blob;
          renderAttachmentPreview(new File([blob], 'voice-message.webm', { type: blob.type || 'audio/webm' }));
          if (voiceBtnEl) voiceBtnEl.classList.remove('is-recording');
          stream.getTracks().forEach(function(tr){ tr.stop(); });
          updateComposerMeta();
          updateSendState();
        };
        mediaRecorder.start();
        voiceBtnEl.classList.add('is-recording');
        if (sendHintEl) sendHintEl.textContent = 'Recording... click mic again to stop.';
      } catch (e1) {
        if (sendHintEl) sendHintEl.textContent = 'Microphone permission denied.';
      }
    });
  }
  if (cannedEl) {
    cannedEl.addEventListener('change', function(){
      var v = String(cannedEl.value || '');
      if (!v) return;
      var cur = String(input.value || '');
      input.value = cur ? (cur + '\n' + v) : v;
      saveDraft();
      cannedEl.value = '';
    });
  }
  if (threadSearchEl){
    threadSearchEl.addEventListener('input', function(){
      state.threadFilter = String(threadSearchEl.value || '');
      renderThreads();
    });
  }
  if (msgSearchEl){
    msgSearchEl.addEventListener('input', function(){
      state.messageFilter = String(msgSearchEl.value || '');
      state.matchIdx = 0;
      renderMessages();
      if (state.messageFilter.trim().length >= 2 && state.matchCount === 0) searchInThreadRemote();
    });
  }
  if (msgSearchPrevEl) msgSearchPrevEl.addEventListener('click', function(){ jumpToSearchMatch(-1); });
  if (msgSearchNextEl) msgSearchNextEl.addEventListener('click', function(){ jumpToSearchMatch(1); });
  if (historyBtnEl) historyBtnEl.addEventListener('click', function(){
    var open = !(historyPanelEl && !historyPanelEl.hidden);
    setHistoryOpen(open);
    if (open) loadHistory();
  });
  if (historyCloseEl) historyCloseEl.addEventListener('click', function(){ setHistoryOpen(false); });
  if (historyPanelEl) {
    historyPanelEl.addEventListener('click', function(ev){
      var d = ev.target.closest('[data-hdir]');
      if (d) {
        state.historyDir = d.getAttribute('data-hdir') || 'all';
        historyPanelEl.querySelectorAll('[data-hdir]').forEach(function(b){ b.classList.toggle('is-on', (b.getAttribute('data-hdir') === state.historyDir)); });
        renderHistory();
        return;
      }
      var k = ev.target.closest('[data-hkind]');
      if (k) {
        state.historyKind = k.getAttribute('data-hkind') || 'all';
        historyPanelEl.querySelectorAll('[data-hkind]').forEach(function(b){ b.classList.toggle('is-on', (b.getAttribute('data-hkind') === state.historyKind)); });
        renderHistory();
      }
    });
  }
  if (logEl){
    logEl.addEventListener('click', function(ev){
      var undo = ev.target.closest('[data-msg-undo]');
      if (!undo) return;
      var msgId = Number(undo.getAttribute('data-msg-undo') || 0);
      if (!msgId) return;
      var fd = new FormData();
      fd.append('message_id', String(msgId));
      fetch(msgApiUrl('api/messages/delete.php'), { method:'POST', credentials:'same-origin', body:fd, headers:{Accept:'application/json'} })
        .then(function(r){ return r.json(); })
        .then(function(d){ if (d && d.ok) refreshAll(true); })
        .catch(function(){});
    });
  }
  if (threadsEl) {
    threadsEl.addEventListener('click', function(ev){
      var pinBtn = ev.target.closest('[data-pin-thread]');
      if (pinBtn) {
        ev.preventDefault();
        ev.stopPropagation();
        var sid = Number(pinBtn.getAttribute('data-pin-thread') || 0);
        var pstate = pinBtn.getAttribute('data-pin-state') === '1' ? 0 : 1;
        var fd = new FormData();
        fd.append('thread_student_id', String(sid));
        fd.append('pin', String(pstate));
        fetch(msgApiUrl('api/messages/pin.php'), { method:'POST', credentials:'same-origin', body:fd, headers:{Accept:'application/json'} })
          .then(function(r){ return r.json(); })
          .then(function(d){ if (d && d.ok) refreshAll(false); })
          .catch(function(){});
        return;
      }
      var btn = ev.target.closest('[data-contact-id]');
      if (!btn) return;
      state.selectedContactId = Number(btn.getAttribute('data-contact-id') || 0);
      refreshAll(true);
    });
  }
  document.addEventListener('keydown', function(ev){
    if (ev.key === 'Escape' && isMsgPanelOpen()) { closePanel(); return; }
    if (ev.key === '/' && isMsgPanelOpen() && !ev.ctrlKey && !ev.metaKey && !ev.altKey) {
      ev.preventDefault();
      if (msgSearchEl) msgSearchEl.focus();
    }
  });
  document.addEventListener('click', function(ev){
    if (!root.classList.contains('ere-msg--open')) return;
    var inPop = (fmtMenuEl && fmtMenuEl.contains(ev.target)) || (emojiMenuEl && emojiMenuEl.contains(ev.target)) || (gifMenuEl && gifMenuEl.contains(ev.target));
    var onBtn = (fmtBtnEl && fmtBtnEl.contains(ev.target)) || (emojiBtnEl && emojiBtnEl.contains(ev.target)) || (gifBtnEl && gifBtnEl.contains(ev.target));
    if (!inPop && !onBtn) closePopovers();
    if (historyPanelEl && !historyPanelEl.hidden) {
      var inHistory = historyPanelEl.contains(ev.target);
      var onHistoryBtn = historyBtnEl && historyBtnEl.contains(ev.target);
      if (!inHistory && !onHistoryBtn) setHistoryOpen(false);
    }
  });
  document.addEventListener('visibilitychange', function(){
    if (document.hidden) return;
    if (root.classList.contains('ere-msg--open')) pollPresenceOnce();
    else tickUnreadBadge();
  });
  window.addEventListener('beforeunload', function(){
    stopMessagingRealtime();
    stopTopbarUnreadPolling();
  });
  setInterval(updateComposerMeta, 500);
  autoResizeInput();
  updateComposerMeta();
  updateSendState();
  refreshAll(false).then(function(){ startTopbarUnreadPolling(); });
})();
</script>

