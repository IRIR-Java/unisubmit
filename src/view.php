<?php
/*
 * In-browser document viewer: a submission (/view.php?s=<token>) or a shared material (?m=<token>),
 * add &from=lecturer for the lecturer's view. PDFs render with pdf.js and Word files with mammoth.js
 * (both fetched as bytes from download.php); text and code are shown escaped. Nothing uploaded is
 * ever rendered as live HTML.
 */
require __DIR__ . '/lib.php';
header('Content-Type: text/html; charset=utf-8');

$mat = isset($_GET['m']) ? find_material($pdo, $_GET['m']) : null;
$sub = $mat ? null : find_by_token($pdo, $_GET['s'] ?? '');
$item = $mat ?: $sub;
$from_lecturer = ($_GET['from'] ?? '') === 'lecturer';
$online = ollama_online($ollama_host);

page_head($item ? ($mat ? $mat['title'] : $item['original_name']) . ' · UniSubmit' : 'Not found · UniSubmit');
top_bar($from_lecturer ? 'lecturer' : 'student', $online);

if (!$item || !is_file(__DIR__ . '/uploads/' . basename($item['filename']))): ?>
<main class="page narrow">
    <div class="card empty"><strong>Document not found</strong>It may have been removed by the lecturer.</div>
</main></body></html>
<?php exit; endif;

$path = __DIR__ . '/uploads/' . basename($item['filename']);
$ext = strtolower(pathinfo($item['filename'], PATHINFO_EXTENSION));
$kind = doc_kind($ext);
$kind_label = ['pdf' => 'PDF', 'docx' => 'Word document'][$kind] ?? strtoupper($ext) . ' file';
$dl = '/download.php?' . ($mat ? 'm=' : 's=') . rawurlencode($item['token']);
if ($from_lecturer) { $back = '/lecturer.php'; $back_label = 'Class overview'; }
elseif ($mat) { $back = '/'; $back_label = 'Hand-in page'; }
else { $back = '/?s=' . rawurlencode($sub['token']); $back_label = 'Your result'; }
// Plain text for text/code files, and as a fallback if a PDF/DOCX can't be rendered in this browser.
$text = $kind === 'none' ? '' : mb_substr(extract_text($path, $ext), 0, 200000);
$criteria = $sub ? (json_decode((string)$sub['criteria'], true) ?: []) : [];
// The student only sees marks that have been released; the lecturer always sees them.
$show_marks = $sub && $criteria && ($from_lecturer || in_array($sub['status'], ['Graded', 'Approved'], true));
$st = $sub ? status_info($sub['status'], $from_lecturer ? 'lecturer' : 'student') : null;
?>
<main class="viewer<?= $sub ? '' : ' single' ?>">
    <section class="card flush doc-panel" aria-label="Document">
        <div class="doc-bar">
            <a class="back inline" href="<?= h($back) ?>">← <?= h($back_label) ?></a>
            <div class="doc-title">
                <strong title="<?= h($item['original_name']) ?>"><?= h($mat ? $mat['title'] : $item['original_name']) ?></strong>
                <span class="muted small"><?= h($kind_label) ?> · <?= h(size_label(filesize($path))) ?><span id="doc-status"></span></span>
            </div>
            <a class="btn small" href="<?= h($dl) ?>"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v12M7 11l5 5 5-5M4 20h16"/></svg>Download</a>
        </div>
        <div class="doc-stage">
            <?php if ($kind === 'none'): ?>
                <div class="card empty" style="max-width:460px;margin:48px auto">
                    <strong>No preview for <?= h(strtoupper($ext)) ?> files</strong>Download it to open it on your device.
                    <div style="margin-top:16px"><a class="btn primary" href="<?= h($dl) ?>">Download</a></div>
                </div>
            <?php elseif ($kind === 'text'): ?>
                <pre class="code-sheet"><?= $text === '' ? 'This file is empty.' : h($text) ?></pre>
            <?php else: ?>
                <div id="doc" class="<?= $kind === 'pdf' ? 'pdf-pages' : 'docx-sheet' ?>" aria-live="polite"><p class="doc-loading"><span class="spinner" aria-hidden="true"></span> Loading document…</p></div>
                <div id="fallback" hidden>
                    <div class="banner flagged slim" style="max-width:860px;margin:0 auto 16px"><div>This document can't be displayed in your browser, so its text is shown instead. Download it for the original layout.</div></div>
                    <pre class="code-sheet"><?= h($text) ?></pre>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($sub): ?>
    <aside class="card side-panel" aria-label="Marks">
        <div class="who small"><?= avatar($sub['student_name']) ?><div><strong><?= h($sub['student_name']) ?></strong><div class="mono muted small"><?= has_reg($sub['reg_number']) ? h($sub['reg_number']) : 'no reg. number' ?></div></div></div>
        <?php if ($show_marks): ?>
            <div class="side-score"><span class="big <?= band($sub['score']) ?>"><?= (int)$sub['score'] ?></span><span class="muted">/ 100 · <?= grade_letter((int)$sub['score']) ?></span><span class="pill <?= h($st['class']) ?>"><?= h($st['label']) ?></span></div>
            <?php if ($from_lecturer && ($flags = json_decode((string)$sub['flags'], true))): ?>
                <div class="flags block"><?php foreach ($flags as $f): ?><span class="flag"><?= h($f) ?></span><?php endforeach; ?></div>
            <?php endif; ?>
            <?php foreach ($criteria as $c): ?>
                <div class="mini-crit">
                    <div class="crit-top"><span><?= h($c['name']) ?> <span class="muted">· <?= (int)$c['weight'] ?>%</span></span><strong><?= (int)$c['score'] ?></strong></div>
                    <div class="bar"><span class="<?= band($c['score']) ?>" style="width:<?= (int)$c['score'] ?>%"></span></div>
                    <?php if ($c['comment'] !== ''): ?><p><?= h($c['comment']) ?></p><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="mini-fb good"><strong>Did well</strong><?= h($sub['strength']) ?></div>
            <div class="mini-fb warn"><strong>To improve</strong><?= h($sub['gap']) ?></div>
        <?php else: ?>
            <p style="margin-top:16px"><span class="pill <?= h($st['class']) ?>"><?= h($st['label']) ?></span></p>
            <p class="muted small"><?= $sub['status'] === 'Pending' ? 'The marker is working on this.' : 'Marks appear here once your lecturer confirms them.' ?></p>
        <?php endif; ?>
        <a class="link" href="/?s=<?= h($sub['token']) ?>">Full report →</a>
    </aside>
    <?php endif; ?>
</main>

<?php if ($kind === 'pdf'): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<?php elseif ($kind === 'docx'): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js"></script>
<?php endif; ?>
<?php if ($kind === 'pdf' || $kind === 'docx'): ?>
<script>
(function () {
    var src = <?= json_encode($dl) ?>, kind = <?= json_encode($kind) ?>;
    var doc = document.getElementById('doc'), status = document.getElementById('doc-status');
    function fail() { doc.hidden = true; document.getElementById('fallback').hidden = false; }
    function bytes() { return fetch(src, { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.arrayBuffer(); }); }

    if (kind === 'pdf') {
        if (!window.pdfjsLib) return fail();
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        bytes().then(function (buf) {
            // isEvalSupported:false keeps pdf.js from compiling font code from the file.
            return pdfjsLib.getDocument({ data: buf, isEvalSupported: false }).promise;
        }).then(async function (pdf) {
            doc.innerHTML = '';
            status.textContent = ' · ' + pdf.numPages + (pdf.numPages === 1 ? ' page' : ' pages');
            var dpr = Math.min(window.devicePixelRatio || 1, 2);
            for (var i = 1; i <= pdf.numPages; i++) {
                var page = await pdf.getPage(i);
                var width = Math.min(doc.clientWidth, 860);
                var vp = page.getViewport({ scale: (width / page.getViewport({ scale: 1 }).width) * dpr });
                var canvas = document.createElement('canvas');
                canvas.className = 'pdf-page';
                canvas.width = vp.width; canvas.height = vp.height;
                canvas.style.maxWidth = width + 'px';
                canvas.setAttribute('aria-label', 'Page ' + i);
                doc.appendChild(canvas);
                await page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
            }
        }).catch(fail);
    } else {
        if (!window.mammoth) return fail();
        bytes().then(function (buf) { return mammoth.convertToHtml({ arrayBuffer: buf }); }).then(function (res) {
            // Mammoth emits plain semantic HTML; still drop anything active before showing it.
            var t = document.createElement('template');
            t.innerHTML = res.value;
            t.content.querySelectorAll('script,style,iframe,object,embed,form').forEach(function (n) { n.remove(); });
            t.content.querySelectorAll('*').forEach(function (n) {
                Array.from(n.attributes).forEach(function (a) {
                    var v = a.value.trim().toLowerCase();
                    if (a.name.indexOf('on') === 0 || ((a.name === 'href' || a.name === 'src') && v.indexOf('javascript:') === 0)) n.removeAttribute(a.name);
                });
                if (n.tagName === 'A') { n.target = '_blank'; n.rel = 'noopener noreferrer'; }
            });
            doc.innerHTML = '';
            doc.appendChild(t.content);
            if (!doc.textContent.trim() && !doc.querySelector('img')) fail();
        }).catch(fail);
    }
})();
</script>
<?php endif; ?>
</body>
</html>
