<?php
/*
 * Lecturer (admin) page: share course files, see and download submissions, give marks, export a CSV.
 * Demo build: no login.
 */
require __DIR__ . '/lib.php';

// CSV export of all submissions.
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="unisubmit_submissions.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Reg Number', 'Student Name', 'File', 'Submitted At', 'Status', 'Mark', 'Comment']);
    foreach ($pdo->query("SELECT * FROM submissions ORDER BY submitted_at") as $r) {
        fputcsv($out, [$r['reg_number'], $r['student_name'], $r['original_name'], $r['submitted_at'],
            is_marked($r) ? 'Marked' : 'Submitted', is_marked($r) ? $r['score'] : '', $r['lecturer_note']]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = intval($_POST['id'] ?? 0);

    if ($action === 'mark') {
        $score = max(0, min(100, intval($_POST['score'] ?? 0)));
        $pdo->prepare("UPDATE submissions SET score = ?, lecturer_note = ?, status = 'Marked' WHERE id = ?")
            ->execute([$score, trim($_POST['comment'] ?? ''), $id]);
        flash('success', 'Mark saved.');
    }

    if ($action === 'delete_sub') {
        $st = $pdo->prepare("SELECT filename FROM submissions WHERE id = ?");
        $st->execute([$id]);
        if ($row = $st->fetch()) {
            @unlink(__DIR__ . '/uploads/' . basename($row['filename']));
            $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$id]);
            flash('success', 'Submission deleted.');
        }
    }

    if ($action === 'upload_material') {
        $saved = save_upload($_FILES['material'] ?? [], MATERIAL_EXT, 'mat_');
        if (is_string($saved)) {
            flash('error', $saved);
        } else {
            $title = trim($_POST['title'] ?? '') ?: pathinfo($saved[1], PATHINFO_FILENAME);
            $pdo->prepare("INSERT INTO materials (title, filename, original_name, token) VALUES (?, ?, ?, ?)")
                ->execute([mb_substr($title, 0, 200), $saved[0], $saved[1], bin2hex(random_bytes(10))]);
            flash('success', "Shared “{$title}” with students.");
        }
    }

    if ($action === 'delete_material') {
        $st = $pdo->prepare("SELECT filename FROM materials WHERE id = ?");
        $st->execute([$id]);
        if ($m = $st->fetch()) {
            @unlink(__DIR__ . '/uploads/' . basename($m['filename']));
            $pdo->prepare("DELETE FROM materials WHERE id = ?")->execute([$id]);
            flash('success', 'File removed.');
        }
    }
    go('/lecturer.php');
}

header('Content-Type: text/html; charset=utf-8');
$flash = take_flash();
$subs = $pdo->query("SELECT * FROM submissions ORDER BY submitted_at DESC")->fetchAll();
$materials = $pdo->query("SELECT * FROM materials ORDER BY uploaded_at DESC")->fetchAll();
$marked = count(array_filter($subs, 'is_marked'));
page_head('Lecturer · UniSubmit');
top_bar('lecturer');
?>
<main class="page wide">
    <?php flash_box($flash); ?>
    <div class="page-head row">
        <div>
            <h1>Submissions</h1>
            <p class="muted"><?= count($subs) ?> submitted · <?= $marked ?> marked</p>
        </div>
        <a class="btn" href="?export=csv">Export CSV</a>
    </div>

    <div class="dash">
        <section class="card flush">
            <?php if (!$subs): ?>
                <div class="empty"><strong>No submissions yet</strong>Students submit from the Student page.</div>
            <?php else: ?>
            <table class="results">
                <thead><tr><th>Student</th><th>File</th><th>Submitted</th><th>Mark</th><th><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($subs as $s): ?>
                    <tr>
                        <td><div class="s-name"><?= h($s['student_name']) ?></div><div class="mono muted small"><?= h($s['reg_number']) ?></div></td>
                        <td><a href="/download.php?s=<?= h($s['token']) ?>"><?= h($s['original_name']) ?></a></td>
                        <td class="muted small"><?= h(date('j M, H:i', strtotime($s['submitted_at']))) ?></td>
                        <td>
                            <form method="POST" class="mark-form">
                                <input type="hidden" name="action" value="mark"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <input class="text-input mark-input" type="number" name="score" min="0" max="100" required value="<?= is_marked($s) ? (int)$s['score'] : '' ?>" placeholder="0–100" aria-label="Mark for <?= h($s['student_name']) ?>">
                                <input class="text-input" name="comment" value="<?= h($s['lecturer_note']) ?>" placeholder="Comment (optional)" aria-label="Comment">
                                <button class="btn small primary" type="submit"><?= is_marked($s) ? 'Update' : 'Save' ?></button>
                            </form>
                        </td>
                        <td class="c-act">
                            <form method="POST" class="inline delete-form"><input type="hidden" name="action" value="delete_sub"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button type="submit" class="link danger">Delete</button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </section>

        <aside class="card">
            <h2>Course files</h2>
            <p class="muted small">Files you upload here can be downloaded by students.</p>
            <?php if ($materials): ?>
            <ul class="files compact">
                <?php foreach ($materials as $m): ?>
                    <li>
                        <span class="ext"><?= h(strtoupper(pathinfo($m['filename'], PATHINFO_EXTENSION))) ?></span>
                        <a class="f-name" href="/download.php?m=<?= h($m['token']) ?>"><?= h($m['title']) ?></a>
                        <form method="POST" class="delete-form"><input type="hidden" name="action" value="delete_material"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button type="submit" class="icon-btn" aria-label="Remove <?= h($m['title']) ?>">×</button></form>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data" class="stack-form">
                <input type="hidden" name="action" value="upload_material">
                <input class="text-input" name="title" placeholder="Title, e.g. Assignment 1" maxlength="200">
                <input class="file-input" type="file" name="material" required>
                <button class="btn block" type="submit">Upload</button>
            </form>
        </aside>
    </div>
</main>
<script>
document.querySelectorAll('.delete-form').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!confirm('Delete this? This cannot be undone.')) e.preventDefault(); });
});
</script>
</body>
</html>
