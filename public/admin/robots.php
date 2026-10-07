<?php
session_start();
if (!($_SESSION['admin'] ?? false)) {
    header('Location: login.php');
    exit;
}

require_once '../../config/db.php';
require_once '../../config/robot9k.php';
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    header('Content-Type: application/json');

    $board_id = (int)($_POST['board_id'] ?? 0);
    if ($board_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid board.']);
        exit;
    }

    $stmt = $db->prepare(
        'UPDATE boards SET robot9k_enabled = NOT robot9k_enabled WHERE id = ? RETURNING robot9k_enabled'
    );
    $stmt->execute([$board_id]);
    $new_state = $stmt->fetchColumn();

    if ($new_state === false) {
        http_response_code(404);
        echo json_encode(['error' => 'Board not found.']);
        exit;
    }

    echo json_encode(['ok' => true, 'enabled' => r9k_bool($new_state)]);
    exit;
}

$boards = $db->query('SELECT id, slug, name, robot9k_enabled FROM boards ORDER BY slug ASC')
             ->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Handle R9K</title>
<link rel="icon" type="image/x-icon" href="../assets/favicon.ico">
<link rel="stylesheet" href="../style.css">
<style>
.panel-box {
    max-width: 560px; margin: 50px auto;
    background: var(--surface);
    border: 1px solid var(--orange);
    padding: 20px 24px;
}
.panel-box h2 { color: var(--orange); margin-bottom: 4px; font-size: 15px; }
.panel-box .hint { color: var(--grey); font-size: 12px; margin-bottom: 16px; }

.r9k-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.r9k-table th {
    text-align: left; color: var(--grey); font-size: 11px;
    text-transform: uppercase; letter-spacing: .5px;
    padding: 6px 8px; border-bottom: 1px solid var(--border);
}
.r9k-table td { padding: 8px; border-bottom: 1px solid var(--border); }
.r9k-table tr:last-child td { border-bottom: none; }

.r9k-slug { color: var(--white); }
.r9k-name { color: var(--grey); }
.r9k-status { font-size: 11px; }
.r9k-status.on  { color: #3ecf5f; }
.r9k-status.off { color: #ff4444; }

.r9k-toggle { position: relative; display: inline-block; width: 42px; height: 22px; }
.r9k-toggle input { opacity: 0; width: 0; height: 0; }
.r9k-slider {
    position: absolute; inset: 0; cursor: pointer;
    background: #ff4444;
    transition: background .15s;
}
.r9k-slider::before {
    content: '';
    position: absolute;
    height: 16px; width: 16px;
    left: 3px; bottom: 3px;
    background: #fff;
    transition: transform .15s;
}
.r9k-toggle input:checked + .r9k-slider { background: #3ecf5f; }
.r9k-toggle input:checked + .r9k-slider::before { transform: translateX(20px); }
.r9k-toggle input:disabled + .r9k-slider { opacity: .5; cursor: default; }
</style>
</head>
<body>
<div id="topbar">
    <span class="site-name">Admin</span>
    <a href="panel.php">[Panel]</a>
    <a href="../index.php">[Home]</a>
</div>

<div class="panel-box">
    <h2>⬡ Robot9k</h2>
    <div class="hint">
        Toggle per-board uniqueness enforcement. When on, a board rejects
        any post whose text (normalized) has already been posted there ^_^
    </div>

    <table class="r9k-table">
        <thead>
            <tr><th>Board</th><th>Name</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($boards as $b): $r9k_on = r9k_bool($b['robot9k_enabled']); ?>
            <tr>
                <td class="r9k-slug">/<?= htmlspecialchars($b['slug']) ?>/</td>
                <td class="r9k-name"><?= htmlspecialchars($b['name']) ?></td>
                <td>
                    <span class="r9k-status <?= $r9k_on ? 'on' : 'off' ?>"
                          id="status-<?= $b['id'] ?>">
                        <?= $r9k_on ? 'Enabled' : 'Disabled' ?>
                    </span>
                </td>
                <td>
                    <label class="r9k-toggle">
                        <input type="checkbox"
                               data-board-id="<?= $b['id'] ?>"
                               <?= $r9k_on ? 'checked' : '' ?>>
                        <span class="r9k-slider"></span>
                    </label>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$boards): ?>
            <tr><td colspan="4" style="color:var(--grey);">No boards found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
document.querySelectorAll('.r9k-toggle input').forEach(cb => {
    cb.addEventListener('change', async function() {
        const boardId  = this.dataset.boardId;
        const statusEl = document.getElementById('status-' + boardId);
        this.disabled = true;

        try {
            const res  = await fetch('robots.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=toggle&board_id=' + encodeURIComponent(boardId),
            });
            const data = await res.json();

            if (!data.ok) {
                alert(data.error || 'Failed to toggle.');
                this.checked = !this.checked;
            } else {
                this.checked = data.enabled;
                if (statusEl) {
                    statusEl.textContent = data.enabled ? 'Enabled' : 'Disabled';
                    statusEl.className = 'r9k-status ' + (data.enabled ? 'on' : 'off');
                }
            }
        } catch (e) {
            alert('Network error, try again.');
            this.checked = !this.checked;
        } finally {
            this.disabled = false;
        }
    });
});
</script>
</body>
</html>
