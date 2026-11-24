<?php
/*
 * Student page: download course files, hand in an assignment, and see the receipt (/?s=<token>).
 */
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['student_name'] ?? '');
    $reg = strtoupper(trim($_POST['reg_number'] ?? ''));
    if ($name === '' || $reg === '') {
        flash('error', 'Enter your name and registration number.');
        go('/');
    }
    $saved = save_upload($_FILES['assignment'] ?? [], SUBMIT_EXT, 'sub_');
    if (is_string($saved)) {
        flash('error', $saved);
        go('/');
    }
    $token = bin2hex(random_bytes(10));
    $pdo->prepare("INSERT INTO submissions (student_name, reg_number, filename, original_name, token, status) VALUES (?, ?, ?, ?, ?, 'Submitted')")
        ->execute([mb_substr($name, 0, 150), mb_substr($reg, 0, 100), $saved[0], $saved[1], $token]);
    flash('success', 'Submitted. Keep this page\'s link to check your mark later.');
    go('/?s=' . $token);
}

header('Content-Type: text/html; charset=utf-8');
$flash = take_flash();
$sub = isset($_GET['s']) ? find_submission($pdo, $_GET['s']) : null;
page_head($sub ? 'Your submission · UniSubmit' : 'Submit assignment · UniSubmit');
top_bar('student');
?>
<main class="page narrow">
<?php if ($sub): ?>
    <a class="back" href="/">← Back</a>
    <?php flash_box($flash); ?>
    <section class="card">
        <h1>Submission received</h1>
        <table class="kv">
            <tr><th>Name</th><td><?= h($sub['student_name']) ?></td></tr>
            <tr><th>Reg. number</th><td class="mono"><?= h($sub['reg_number']) ?></td></tr>
            <tr><th>File</th><td><a href="/download.php?s=<?= h($sub['token']) ?>"><?= h($sub['original_name']) ?></a></td></tr>
            <tr><th>Submitted</th><td><?= h(date('j M Y, H:i', strtotime($sub['submitted_at']))) ?></td></tr>
            <tr><th>Status</th><td><span class="pill <?= is_marked($sub) ? 'approved' : 'queued' ?>"><?= is_marked($sub) ? 'Marked' : 'Submitted' ?></span></td></tr>
            <?php if (is_marked($sub)): ?>
                <tr><th>Mark</th><td><strong><?= (int)$sub['score'] ?> / 100</strong></td></tr>
                <?php if ($sub['lecturer_note']): ?><tr><th>Comment</th><td><?= nl2br(h($sub['lecturer_note'])) ?></td></tr><?php endif; ?>
            <?php endif; ?>
        </table>
    </section>
<?php elseif (isset($_GET['s'])): ?>
    <div class="card empty"><strong>Submission not found</strong>It may have been removed by the lecturer.</div>
<?php else:
    $materials = $pdo->query("SELECT * FROM materials ORDER BY uploaded_at DESC")->fetchAll();
?>
    <?php flash_box($flash); ?>
    <div class="page-head">
        <h1>Submit your assignment</h1>
        <p class="muted">Download the assignment below, then upload your work.</p>
    </div>

    <section class="card">
        <h2>Course files</h2>
        <?php if ($materials): ?>
        <ul class="files">
            <?php foreach ($materials as $m): ?>
                <li>
                    <span class="ext"><?= h(strtoupper(pathinfo($m['filename'], PATHINFO_EXTENSION))) ?></span>
                    <span class="f-name"><?= h($m['title']) ?></span>
                    <a class="btn small" href="/download.php?m=<?= h($m['token']) ?>">Download</a>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
            <p class="muted">No files shared yet.</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Hand in</h2>
        <form method="POST" enctype="multipart/form-data" class="stack-form">
            <div class="grid-2">
                <label class="field-label">Full name<input class="text-input" name="student_name" required maxlength="150"></label>
                <label class="field-label">Registration number<input class="text-input" name="reg_number" required maxlength="100" placeholder="e.g. EB1/12345/23"></label>
            </div>
            <label class="drop" for="file-input">
                <input id="file-input" class="sr-only" type="file" name="assignment" required accept="<?= h(implode(',', array_map(fn($e) => ".$e", SUBMIT_EXT))) ?>">
                <span class="drop-title">Choose your file, or drag it here</span>
                <span class="drop-types">PDF, Word, PowerPoint, Excel, text, code or ZIP · up to 25 MB</span>
                <span class="drop-file" id="drop-file"></span>
            </label>
            <button class="btn primary block" type="submit">Submit</button>
        </form>
    </section>
    <script>
    document.getElementById('file-input').addEventListener('change', function () {
        var f = this.files[0]; document.getElementById('drop-file').textContent = f ? f.name : '';
    });
    </script>
<?php endif; ?>
</main>
</body>
</html>
