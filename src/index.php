<?php
/*
 * Student page: read the assignment, hand in work, and get a result back (/?s=<token>).
 * Students only ever see their own result; marks held for review stay hidden until confirmed.
 */
require __DIR__ . '/lib.php';
header('Content-Type: text/html; charset=utf-8');

$rubric = get_setting($pdo, 'rubric', DEFAULT_RUBRIC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A file over post_max_size arrives as an empty POST.
    if (!$_POST && !$_FILES && ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('error', 'That file is larger than the 25 MB limit.');
        go('/');
    }
    set_time_limit(120);
    $file = $_FILES['assignment'] ?? ['error' => UPLOAD_ERR_NO_FILE, 'name' => ''];
    $orig_name = basename($file['name']);
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    if ($file['error'] !== UPLOAD_ERR_OK) {
        flash('error', $file['error'] === UPLOAD_ERR_NO_FILE ? 'Choose a file first.' : "Upload failed (error code {$file['error']}).");
        go('/');
    }
    if (!in_array($ext, ALLOWED_EXT, true)) {
        flash('error', ($ext === '' ? 'That file has no extension.' : "“.$ext” files aren't accepted.") . ' Upload a PDF, Word document, text, Markdown or source file.');
        go('/');
    }
    $safe_name = uniqid('sub_', true) . '.' . $ext;
    $dest = __DIR__ . '/uploads/' . $safe_name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        flash('error', 'Your file could not be saved. Please try again.');
        go('/');
    }
    $g = grade_submission(extract_text($dest, $ext), $rubric, $orig_name, $ollama_host, brief_text($pdo));
    $token = bin2hex(random_bytes(10));
    $pdo->prepare("INSERT INTO submissions (reg_number, student_name, score, status, strength, gap, summary, criteria, members, flags,
                   filename, original_name, token, attempts, graded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)")
        ->execute([$g['reg_number'], $g['student_name'], $g['score'], $g['status'], $g['strength'], $g['gap'], $g['summary'],
            $g['criteria'] ? json_encode($g['criteria']) : null, $g['members'] ? json_encode($g['members']) : null,
            $g['flags'] ? json_encode($g['flags']) : null, $safe_name, $orig_name, $token, $g['ok'] ? date('Y-m-d H:i:s') : null]);
    flash('success', 'Handed in. Keep this page\'s link to come back to your result.');
    go('/?s=' . $token);
}

$flash = take_flash();
$online = ollama_online($ollama_host);
$sub = isset($_GET['s']) ? find_by_token($pdo, $_GET['s']) : null;

page_head($sub ? 'Your result · UniSubmit' : 'Hand in · UniSubmit');
top_bar('student', $online);
?>
<main class="page narrow">
<?php if (isset($_GET['s']) && !$sub): ?>
    <a class="back" href="/">← Back</a>
    <div class="card empty"><strong>Submission not found</strong>This link may have been removed by your lecturer.</div>

<?php elseif ($sub):
    $st = status_info($sub['status'], 'student');
    $released = in_array($sub['status'], ['Graded', 'Approved'], true);
    $criteria = json_decode((string)$sub['criteria'], true) ?: [];
    $members = json_decode((string)$sub['members'], true) ?: [];
    $score = (int)$sub['score'];
?>
    <a class="back" href="/">← Hand in another file</a>
    <?php flash_box($flash); ?>

    <section class="card result-hero">
        <div class="who">
            <?= avatar($sub['student_name'], 'lg') ?>
            <div>
                <h1><?= h($sub['student_name']) ?></h1>
                <div class="muted"><?= has_reg($sub['reg_number']) ? '<span class="mono">' . h($sub['reg_number']) . '</span> · ' : '' ?>handed in <?= h(date('j M, H:i', strtotime($sub['submitted_at']))) ?></div>
                <div class="file-row">
                    <span class="file-chip"><?= h($sub['original_name']) ?></span>
                    <a class="btn small" href="/view.php?s=<?= h($sub['token']) ?>">Open</a>
                    <a class="link small" href="/download.php?s=<?= h($sub['token']) ?>">Download</a>
                </div>
            </div>
        </div>
        <div class="score-block">
            <?php if ($released): ?>
                <div class="ring <?= band($score) ?>">
                    <svg viewBox="0 0 120 120" aria-hidden="true"><circle class="track" cx="60" cy="60" r="52"/><circle class="fill" cx="60" cy="60" r="52" pathLength="100" stroke-dasharray="<?= $score ?> 100"/></svg>
                    <div class="ring-text"><strong><?= $score ?></strong><span>/ 100 · <?= grade_letter($score) ?></span></div>
                </div>
            <?php endif; ?>
            <span class="pill <?= h($st['class']) ?>"><?= h($st['label']) ?></span>
        </div>
    </section>

    <?php if ($sub['status'] === 'Pending'): ?>
        <div class="banner queued"><span class="spinner" aria-hidden="true"></span><div><strong>Your work is being marked.</strong> This page updates on its own; it usually takes under a minute.</div></div>
        <script>setTimeout(function () { location.reload(); }, 8000);</script>
    <?php elseif ($sub['status'] === 'Flagged'): ?>
        <div class="banner flagged"><div><strong>Your lecturer is checking this one.</strong> Your work has been received and marked, and your result will appear here as soon as it is confirmed.</div></div>
    <?php elseif ($sub['status'] === 'Approved'): ?>
        <div class="banner approved"><div><strong>Final mark.</strong> Your lecturer has confirmed this result.</div></div>
    <?php else: ?>
        <div class="banner graded"><div><strong>Marked automatically against the rubric.</strong> Your lecturer supervises every result and may still adjust it.</div></div>
    <?php endif; ?>

    <?php if ($sub['lecturer_note'] && $released): ?>
        <section class="card">
            <h2>Note from your lecturer</h2>
            <p class="note"><?= nl2br(h($sub['lecturer_note'])) ?></p>
        </section>
    <?php endif; ?>

    <?php if ($released && $criteria): ?>
    <section class="card">
        <h2>How you were marked</h2>
        <div class="crit-list">
            <?php foreach ($criteria as $c): ?>
                <div class="crit">
                    <div class="crit-top"><span class="crit-name"><?= h($c['name']) ?> <span class="muted">· <?= (int)$c['weight'] ?>%</span></span><span class="crit-score"><?= (int)$c['score'] ?></span></div>
                    <div class="bar"><span class="<?= band($c['score']) ?>" style="width:<?= (int)$c['score'] ?>%"></span></div>
                    <?php if ($c['comment'] !== ''): ?><p><?= h($c['comment']) ?></p><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="two-up">
        <div class="card tone good"><h2>What you did well</h2><p><?= h($sub['strength']) ?></p></div>
        <div class="card tone warn"><h2>What to improve</h2><p><?= h($sub['gap']) ?></p></div>
    </section>

    <?php if ($sub['summary']): ?>
    <section class="card">
        <h2>Overall comments</h2>
        <p class="lead"><?= nl2br(h($sub['summary'])) ?></p>
    </section>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($members): $dups = duplicate_regs($members); ?>
    <section class="card">
        <h2>Group members <span class="count"><?= count($members) ?></span></h2>
        <?php if ($dups): ?>
            <?php foreach ($dups as $reg => $names): ?>
                <div class="banner flagged slim"><div>Registration number <span class="mono"><?= h($reg) ?></span> is listed for <?= h(implode(' and ', $names)) ?>. Please correct it.</div></div>
            <?php endforeach; ?>
        <?php endif; ?>
        <ul class="people">
            <?php foreach ($members as $m): ?>
                <li><?= avatar($m['name'] ?: $m['reg'], 'sm') ?><span class="p-name"><?= h($m['name'] ?: '(name not found)') ?></span><span class="mono muted<?= isset($dups[$m['reg']]) ? ' bad' : '' ?>"><?= h($m['reg']) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <script>
    // Remember this submission on this device so the student can find it again from the hand-in page.
    try {
        var key = 'unisubmit.mine', list = JSON.parse(localStorage.getItem(key) || '[]');
        var me = <?= json_encode(['s' => $sub['token'], 'file' => $sub['original_name'], 'at' => $sub['submitted_at']]) ?>;
        list = list.filter(function (x) { return x.s !== me.s; });
        list.unshift(me);
        localStorage.setItem(key, JSON.stringify(list.slice(0, 20)));
    } catch (e) {}
    </script>

<?php else:
    $brief = current_brief($pdo);
    $others = $pdo->query("SELECT * FROM materials WHERE is_brief = 0 ORDER BY uploaded_at DESC")->fetchAll();
?>
    <?php flash_box($flash); ?>
    <div class="page-head">
        <h1>Hand in your work</h1>
        <p class="muted">Upload your file and get your mark and feedback in about a minute.</p>
    </div>

    <section class="card assignment">
        <div class="assignment-top">
            <span class="doc-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 2h8l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/><path d="M14 2v5h5M8 13h8M8 17h6"/></svg></span>
            <div class="assignment-title">
                <span class="eyebrow">Assignment · <?= h(COURSE) ?></span>
                <h2><?= $brief ? h($brief['title']) : 'Coursework submission' ?></h2>
            </div>
            <?php if ($brief): ?>
                <div class="actions">
                    <?php if (doc_kind(strtolower(pathinfo($brief['filename'], PATHINFO_EXTENSION))) !== 'none'): ?><a class="btn" href="/view.php?m=<?= h($brief['token']) ?>">Open brief</a><?php endif; ?>
                    <a class="link" href="/download.php?m=<?= h($brief['token']) ?>">Download</a>
                </div>
            <?php endif; ?>
        </div>
        <div class="rubric-chips" aria-label="Marking rubric">
            <?php foreach (parse_rubric($rubric) as $c): ?>
                <span class="chip" title="<?= h($c['detail']) ?>"><strong><?= (int)$c['weight'] ?>%</strong> <?= h($c['name']) ?></span>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card">
        <form id="upload-form" method="POST" enctype="multipart/form-data">
            <label class="drop" id="dropzone" for="file-input">
                <input id="file-input" class="sr-only" type="file" name="assignment" required
                       accept="<?= h(implode(',', array_map(fn($e) => ".$e", ALLOWED_EXT))) ?>">
                <span class="drop-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg></span>
                <span class="drop-title">Drag your file here, or <u>browse</u></span>
                <span class="drop-types">PDF, Word, text or code · up to 25 MB · put your name and reg. number on the cover</span>
                <span class="drop-file" id="drop-file" aria-live="polite"></span>
            </label>
            <button id="upload-btn" class="btn primary block" type="submit">Hand in</button>
        </form>
    </section>

    <?php if ($others): ?>
    <section class="card">
        <h2>Course materials</h2>
        <ul class="files">
            <?php foreach ($others as $m): $mext = strtolower(pathinfo($m['filename'], PATHINFO_EXTENSION)); ?>
                <li>
                    <span class="ext"><?= h(strtoupper($mext)) ?></span>
                    <span class="f-name"><?= h($m['title']) ?></span>
                    <?php if (doc_kind($mext) !== 'none'): ?><a class="link" href="/view.php?m=<?= h($m['token']) ?>">Open</a><?php endif; ?>
                    <a class="link" href="/download.php?m=<?= h($m['token']) ?>">Download</a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <section class="card" id="mine-panel" hidden>
        <h2>Your submissions</h2>
        <ul class="files" id="mine"></ul>
    </section>

    <div class="processing" id="processing" hidden>
        <div class="processing-card">
            <span class="spinner big" aria-hidden="true"></span>
            <strong>Marking your work…</strong>
            <ol class="steps"><li class="on">Reading your file</li><li>Checking it against the rubric</li><li>Writing your feedback</li></ol>
        </div>
    </div>

    <script>
    (function () {
        var drop = document.getElementById('dropzone'), input = document.getElementById('file-input'), label = document.getElementById('drop-file');
        function showFile() { var f = input.files && input.files[0]; label.textContent = f ? f.name + ' · ' + (f.size / 1048576).toFixed(1) + ' MB' : ''; drop.classList.toggle('has-file', !!f); }
        input.addEventListener('change', showFile);
        ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
        ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function () { drop.classList.remove('is-over'); }); });
        drop.addEventListener('drop', function (e) { e.preventDefault(); if (e.dataTransfer.files.length) { input.files = e.dataTransfer.files; showFile(); } });
        document.getElementById('upload-form').addEventListener('submit', function () {
            document.getElementById('upload-btn').disabled = true;
            var p = document.getElementById('processing'), steps = p.querySelectorAll('.steps li'), i = 0;
            p.hidden = false;
            setInterval(function () { if (i < steps.length - 1) { steps[i].className = 'done'; steps[++i].className = 'on'; } }, 2500);
        });

        var list = [];
        try { list = JSON.parse(localStorage.getItem('unisubmit.mine') || '[]'); } catch (e) {}
        if (list.length) {
            var ul = document.getElementById('mine');
            list.forEach(function (x) {
                var li = document.createElement('li'), a = document.createElement('a'), when = document.createElement('span');
                a.href = '/?s=' + encodeURIComponent(x.s); a.textContent = x.file; a.className = 'f-name';
                when.className = 'muted'; when.textContent = (x.at || '').slice(0, 16);
                li.appendChild(a); li.appendChild(when); ul.appendChild(li);
            });
            document.getElementById('mine-panel').hidden = false;
        }
    })();
    </script>
<?php endif; ?>
</main>
</body>
</html>
