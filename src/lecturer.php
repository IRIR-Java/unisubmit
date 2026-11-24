<?php
/*
 * Lecturer page: supervise the system. Marking and release happen automatically; the lecturer clears the
 * "Needs your attention" queue, can adjust any mark, shares the brief and materials, and exports grades.
 * Demo build: no login, reached with the Student/Lecturer switch.
 */
require __DIR__ . '/lib.php';

$rubric = get_setting($pdo, 'rubric', DEFAULT_RUBRIC);

/* ---------- CSV export ---------- */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="unisubmit_cohort_grades.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Rank', 'Reg Number', 'Student Name', 'Score (%)', 'Grade', 'Status', 'Strength', 'Gap', 'Overall Comments', 'Lecturer Note', 'Group Members', 'Original File', 'Submitted At']);
    $rank = 1;
    foreach ($pdo->query("SELECT * FROM submissions " . ORDER_SQL) as $row) {
        $pending = $row['status'] === 'Pending';
        $members = implode('; ', array_map(fn($m) => trim("{$m['name']} {$m['reg']}"), json_decode((string)$row['members'], true) ?: []));
        fputcsv($out, [$pending ? '' : $rank++, $row['reg_number'], $row['student_name'], $pending ? '' : $row['score'],
            $pending ? '' : grade_letter((int)$row['score']), status_info($row['status'], 'lecturer')['label'],
            $row['strength'], $row['gap'], $row['summary'], $row['lecturer_note'], $members, $row['original_name'], $row['submitted_at']]);
    }
    fclose($out);
    exit;
}

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = intval($_POST['id'] ?? 0);

    // A file over post_max_size arrives as an empty POST.
    if (!$_POST && !$_FILES && ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('error', 'That file is larger than the 25 MB limit.');
    }

    if ($action === 'approve') {
        $pdo->prepare("UPDATE submissions SET status = 'Approved', flags = NULL WHERE id = ?")->execute([$id]);
        flash('success', 'Approved and released to the student.');
    }

    if ($action === 'edit_override') {
        $pdo->prepare("UPDATE submissions SET student_name = ?, reg_number = ?, score = ?, strength = ?, gap = ?, lecturer_note = ?,
                       status = 'Approved', flags = NULL WHERE id = ?")
            ->execute([trim($_POST['student_name']), trim($_POST['reg_number']), max(0, min(100, intval($_POST['score']))),
                trim($_POST['strength'] ?? ''), trim($_POST['gap'] ?? ''), trim($_POST['lecturer_note'] ?? ''), $id]);
        flash('success', 'Saved. ' . trim($_POST['student_name']) . ' now sees this as their final mark.');
    }

    if ($action === 'regrade') {
        set_time_limit(120);
        $st = $pdo->prepare("SELECT * FROM submissions WHERE id = ?");
        $st->execute([$id]);
        if ($row = $st->fetch()) {
            $g = grade_stored($pdo, $row, $ollama_host);
            $g['ok'] ? flash('success', "Re-marked {$g['student_name']}: {$g['score']}/100" . ($g['status'] === 'Flagged' ? ' (needs review).' : '.'))
                     : flash('warning', "Not re-marked: {$g['reason']} The worker will keep trying.");
        }
    }

    if ($action === 'delete_sub') {
        $st = $pdo->prepare("SELECT filename, original_name FROM submissions WHERE id = ?");
        $st->execute([$id]);
        if ($row = $st->fetch()) {
            @unlink(__DIR__ . '/uploads/' . basename($row['filename']));
            $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$id]);
            flash('success', "Removed {$row['original_name']}.");
        }
    }

    if ($action === 'save_rubric') {
        $new = trim($_POST['rubric'] ?? '');
        set_setting($pdo, 'rubric', $new !== '' ? $new : DEFAULT_RUBRIC);
        flash('success', 'Rubric saved. New submissions are marked against it.');
    }

    if ($action === 'upload_material') {
        $file = $_FILES['material'] ?? ['error' => UPLOAD_ERR_NO_FILE, 'name' => ''];
        $orig = basename($file['name']);
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if ($file['error'] !== UPLOAD_ERR_OK) {
            flash('error', $file['error'] === UPLOAD_ERR_NO_FILE ? 'Choose a file to share.' : "Upload failed (error code {$file['error']}).");
        } elseif (!in_array($ext, MATERIAL_EXT, true)) {
            flash('error', ($ext === '' ? 'That file has no extension.' : "“.$ext” files can't be shared.") . ' Allowed: ' . implode(', ', MATERIAL_EXT) . '.');
        } else {
            $stored = uniqid('mat_', true) . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], __DIR__ . '/uploads/' . $stored)) {
                $title = mb_substr(trim($_POST['title'] ?? ''), 0, 200) ?: pathinfo($orig, PATHINFO_FILENAME);
                $is_brief = !empty($_POST['is_brief']) && doc_kind($ext) !== 'none';
                if ($is_brief) $pdo->exec("UPDATE materials SET is_brief = 0");
                $pdo->prepare("INSERT INTO materials (title, filename, original_name, token, is_brief) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$title, $stored, $orig, bin2hex(random_bytes(10)), $is_brief ? 1 : 0]);
                flash('success', $is_brief ? "“{$title}” is now the assignment: students see it, and the marker uses it." : "Shared “{$title}” with students.");
            } else {
                flash('error', 'The file could not be saved.');
            }
        }
    }

    if ($action === 'set_brief') {
        $pdo->exec("UPDATE materials SET is_brief = 0");
        if (!empty($_POST['on'])) $pdo->prepare("UPDATE materials SET is_brief = 1 WHERE id = ?")->execute([$id]);
        flash('success', !empty($_POST['on']) ? 'Set as the assignment brief.' : 'No assignment brief set; marking uses the rubric only.');
    }

    if ($action === 'delete_material') {
        $st = $pdo->prepare("SELECT * FROM materials WHERE id = ?");
        $st->execute([$id]);
        if ($m = $st->fetch()) {
            @unlink(__DIR__ . '/uploads/' . basename($m['filename']));
            $pdo->prepare("DELETE FROM materials WHERE id = ?")->execute([$m['id']]);
            flash('success', "Removed “{$m['title']}”.");
        }
    }
    go('/lecturer.php');
}

/* ---------- page ---------- */
header('Content-Type: text/html; charset=utf-8');
$flash = take_flash();
$online = ollama_online($ollama_host);
$subs = $pdo->query("SELECT * FROM submissions " . ORDER_SQL)->fetchAll();
$marked = array_values(array_filter($subs, fn($s) => $s['status'] !== 'Pending'));
$flagged = array_values(array_filter($subs, fn($s) => $s['status'] === 'Flagged'));
$auto = count(array_filter($subs, fn($s) => $s['status'] === 'Graded'));
$approved = count(array_filter($subs, fn($s) => $s['status'] === 'Approved'));
$queued = count($subs) - count($marked);
$avg = $marked ? round(array_sum(array_column($marked, 'score')) / count($marked)) : null;
$materials = $pdo->query("SELECT * FROM materials ORDER BY is_brief DESC, uploaded_at DESC")->fetchAll();

// One row's data for the Adjust dialog.
function sub_json($s) {
    return h(json_encode(['id' => (int)$s['id'], 'student_name' => $s['student_name'],
        'reg_number' => has_reg($s['reg_number']) ? $s['reg_number'] : '', 'score' => $s['status'] === 'Pending' ? '' : (int)$s['score'],
        'strength' => $s['strength'], 'gap' => $s['gap'], 'note' => (string)$s['lecturer_note']]));
}

page_head('Lecturer · UniSubmit');
top_bar('lecturer', $online);
?>
<main class="page wide">
    <?php flash_box($flash); ?>
    <div class="page-head row">
        <div>
            <h1>Class overview</h1>
            <p class="muted">UniSubmit marks and releases work on its own. You only step in where it asks.</p>
        </div>
        <a class="btn" href="?export=csv"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v12M7 11l5 5 5-5M4 20h16"/></svg>Export grades</a>
    </div>

    <section class="kpis" aria-label="Summary">
        <div class="kpi"><span class="kpi-v"><?= count($subs) ?></span><span class="kpi-l">Submissions</span></div>
        <div class="kpi"><span class="kpi-v"><?= $avg === null ? '–' : $avg ?></span><span class="kpi-l">Class average</span></div>
        <div class="kpi"><span class="kpi-v"><?= $auto + $approved ?></span><span class="kpi-l">Released to students</span></div>
        <div class="kpi <?= $flagged ? 'attn' : '' ?>"><span class="kpi-v"><?= count($flagged) ?></span><span class="kpi-l">Need your review</span></div>
    </section>

    <div class="dash">
        <div class="dash-main">
            <section class="card">
                <div class="card-head">
                    <h2>Needs your attention <span class="count<?= $flagged ? ' attn' : '' ?>"><?= count($flagged) ?></span></h2>
                    <?php if ($queued): ?><span class="pill queued"><span class="spinner xs" aria-hidden="true"></span><?= $queued ?> being marked</span><?php endif; ?>
                </div>
                <?php if (!$flagged): ?>
                    <div class="all-clear"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg><div><strong>All clear.</strong> Every marked submission has been released to its student.</div></div>
                <?php else: ?>
                    <ul class="attn-list">
                    <?php foreach ($flagged as $s): $flags = json_decode((string)$s['flags'], true) ?: ['Needs review']; ?>
                        <li>
                            <?= avatar($s['student_name']) ?>
                            <div class="attn-body">
                                <div class="attn-top"><a class="s-name" href="/view.php?s=<?= h($s['token']) ?>&amp;from=lecturer"><?= h($s['student_name']) ?></a>
                                    <?php if ($s['criteria']): ?><span class="score-tag <?= band($s['score']) ?>"><?= (int)$s['score'] ?></span><?php endif; ?></div>
                                <div class="flags"><?php foreach ($flags as $f): ?><span class="flag"><?= h($f) ?></span><?php endforeach; ?></div>
                            </div>
                            <div class="attn-actions">
                                <a class="btn small" href="/view.php?s=<?= h($s['token']) ?>&amp;from=lecturer">Open</a>
                                <button type="button" class="btn small review-btn" data-sub="<?= sub_json($s) ?>">Adjust</button>
                                <?php if ($s['criteria']): ?>
                                <form method="POST"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn small primary" type="submit">Approve</button></form>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="card flush">
                <div class="card-head pad"><h2>All results</h2><span class="muted small"><?= $auto ?> auto-released · <?= $approved ?> approved by you</span></div>
                <?php if (!$subs): ?>
                    <div class="empty"><strong>No submissions yet</strong>Students hand in from the Student page.</div>
                <?php else: ?>
                <table class="results">
                    <thead><tr><th class="c-rank">#</th><th>Student</th><th>Mark</th><th class="c-fb">Feedback</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                    <?php $rank = 1; foreach ($subs as $s): $st = status_info($s['status'], 'lecturer'); $hasMark = $s['status'] !== 'Pending' && $s['criteria']; ?>
                        <tr>
                            <td class="c-rank"><?= $s['status'] === 'Pending' ? '–' : $rank++ ?></td>
                            <td>
                                <div class="who-cell"><?= avatar($s['student_name'], 'sm') ?><div>
                                    <a class="s-name" href="/view.php?s=<?= h($s['token']) ?>&amp;from=lecturer"><?= h($s['student_name']) ?></a>
                                    <div class="mono muted small"><?= has_reg($s['reg_number']) ? h($s['reg_number']) : '<span class="bad">no reg. number</span>' ?></div>
                                </div></div>
                            </td>
                            <td class="c-mark">
                                <?php if ($hasMark): ?>
                                    <div class="mark-num"><?= (int)$s['score'] ?> <span class="muted"><?= grade_letter((int)$s['score']) ?></span></div>
                                    <div class="bar"><span class="<?= band($s['score']) ?>" style="width:<?= (int)$s['score'] ?>%"></span></div>
                                <?php else: ?><span class="muted">–</span><?php endif; ?>
                            </td>
                            <td class="c-fb"><?= $hasMark ? h($s['gap']) : '<span class="muted">' . ($s['status'] === 'Pending' ? 'Being marked…' : 'Mark by hand') . '</span>' ?></td>
                            <td><span class="pill <?= h($st['class']) ?>"><?= h($st['label']) ?></span></td>
                            <td class="c-act">
                                <button type="button" class="link review-btn" data-sub="<?= sub_json($s) ?>">Adjust</button>
                                <form method="POST" class="inline regrade-form"><input type="hidden" name="action" value="regrade"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button type="submit" class="link">Re-mark</button></form>
                                <form method="POST" class="inline delete-form"><input type="hidden" name="action" value="delete_sub"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button type="submit" class="link danger">Delete</button></form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </section>
        </div>

        <aside class="dash-side">
            <section class="card">
                <h2>Assignment &amp; materials</h2>
                <?php if ($materials): ?>
                <ul class="files compact">
                    <?php foreach ($materials as $m): $mext = strtolower(pathinfo($m['filename'], PATHINFO_EXTENSION)); ?>
                        <li>
                            <span class="ext"><?= h(strtoupper($mext)) ?></span>
                            <div class="f-main">
                                <a class="f-name" href="/view.php?m=<?= h($m['token']) ?>&amp;from=lecturer"><?= h($m['title']) ?></a>
                                <span class="muted small"><?= $m['is_brief'] ? '<span class="tag">Assignment brief</span>' : 'Shared file' ?></span>
                            </div>
                            <form method="POST" class="delete-form" data-confirm="Stop sharing this file and delete it?"><input type="hidden" name="action" value="delete_material"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button type="submit" class="icon-btn" title="Remove" aria-label="Remove <?= h($m['title']) ?>">×</button></form>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <details class="more"<?= $materials ? '' : ' open' ?>>
                    <summary>Share a file</summary>
                    <form method="POST" enctype="multipart/form-data" class="stack-form">
                        <input type="hidden" name="action" value="upload_material">
                        <input class="text-input" name="title" placeholder="Title, e.g. Assignment brief" maxlength="200">
                        <input class="file-input" type="file" name="material" required accept="<?= h(implode(',', array_map(fn($e) => ".$e", MATERIAL_EXT))) ?>">
                        <label class="check"><input type="checkbox" name="is_brief" value="1" checked> This is the assignment brief (the marker reads it)</label>
                        <button class="btn block" type="submit">Share with students</button>
                    </form>
                </details>
            </section>

            <section class="card">
                <h2>Marking rubric</h2>
                <ul class="rubric-mini">
                    <?php foreach (parse_rubric($rubric) as $c): ?><li><span><?= h($c['name']) ?></span><strong><?= (int)$c['weight'] ?>%</strong></li><?php endforeach; ?>
                </ul>
                <details class="more">
                    <summary>Edit rubric</summary>
                    <form method="POST" class="stack-form">
                        <input type="hidden" name="action" value="save_rubric">
                        <textarea name="rubric" rows="5" aria-label="Rubric, one criterion per line"><?= h($rubric) ?></textarea>
                        <span class="muted small">One per line, e.g. “40% Depth: analysis and evidence”.</span>
                        <button class="btn block" type="submit">Save rubric</button>
                    </form>
                </details>
            </section>

            <section class="card system">
                <h2>How it runs</h2>
                <ul class="sys">
                    <li><span class="dot <?= $online ? 'on' : 'off' ?>"></span>Marker <?= $online ? 'online' : 'offline (work waits in the queue)' ?></li>
                    <li><span class="dot on"></span>Background worker re-tries queued work</li>
                    <li><span class="dot on"></span>Unusual results are held for you</li>
                </ul>
                <p class="muted small">Model: <?= h(MODEL) ?></p>
            </section>
        </aside>
    </div>
</main>

<dialog id="review" aria-labelledby="review-title">
    <form method="POST">
        <div class="dlg-head">
            <h2 id="review-title">Adjust and approve</h2>
            <p class="muted small">Saving makes this the student's final mark.</p>
        </div>
        <div class="dlg-body">
            <input type="hidden" name="action" value="edit_override">
            <input type="hidden" id="r-id" name="id">
            <div class="grid-2">
                <label>Student name<input id="r-name" name="student_name" required></label>
                <label>Reg. number<input id="r-reg" name="reg_number" required></label>
            </div>
            <label>Mark (0–100)<input id="r-score" name="score" type="number" min="0" max="100" required></label>
            <label>Strength<input id="r-strength" name="strength"></label>
            <label>To improve<input id="r-gap" name="gap"></label>
            <label>Note to the student (optional)<textarea id="r-note" name="lecturer_note" rows="3"></textarea></label>
        </div>
        <div class="dlg-foot">
            <button type="button" class="btn" id="r-cancel">Cancel</button>
            <button type="submit" class="btn primary">Save and approve</button>
        </div>
    </form>
</dialog>

<script>
(function () {
    document.querySelectorAll('.regrade-form').forEach(function (f) {
        f.addEventListener('submit', function () { var b = f.querySelector('button'); b.disabled = true; b.textContent = 'Marking…'; });
    });
    document.querySelectorAll('.delete-form').forEach(function (f) {
        f.addEventListener('submit', function (e) { if (!confirm(f.dataset.confirm || 'Delete this submission and its file?')) e.preventDefault(); });
    });
    var dlg = document.getElementById('review');
    document.querySelectorAll('.review-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            var s = JSON.parse(b.dataset.sub);
            document.getElementById('r-id').value = s.id;
            document.getElementById('r-name').value = s.student_name;
            document.getElementById('r-reg').value = s.reg_number;
            document.getElementById('r-score').value = s.score;
            document.getElementById('r-strength').value = s.strength || '';
            document.getElementById('r-gap').value = s.gap || '';
            document.getElementById('r-note').value = s.note || '';
            dlg.showModal();
        });
    });
    document.getElementById('r-cancel').addEventListener('click', function () { dlg.close(); });
    // While work is queued, refresh so results appear without a click.
    <?php if ($queued): ?>setTimeout(function () { if (!dlg.open) location.reload(); }, 10000);<?php endif; ?>
})();
</script>
</body>
</html>
