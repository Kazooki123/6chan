<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/space.php';

$db = get_db();
$space_id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare(
    "SELECT s.*, b.slug AS board_slug, b.name AS board_name
     FROM spaces s JOIN boards b ON b.id = s.board_id
     WHERE s.id = ?"
);
$stmt->execute([$space_id]);
$space = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$space) { http_response_code(404); die('Space not found.'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>🎙️ <?= htmlspecialchars($space['title']) ?> - @6chan Space</title>
<link rel="icon" type="image/x-icon" href="assets/favicon.svg">
<link rel="stylesheet" href="style/style.css">
<script src="js/settingsloader.js"></script>
<style>
.space-room { max-width: 700px; margin: 0 auto; padding: 12px; }
.space-header { background: var(--surface); border: 1px solid var(--border); padding: 14px 16px; margin-bottom: 14px; }
.space-header .badge-live { display: inline-flex; align-items: center; gap: 6px; background: #2a0010; color: var(--red); border: 1px solid var(--red); font-size: 11px; font-weight: bold; padding: 2px 8px; border-radius: 3px; letter-spacing: .5px; }
.space-header .badge-live .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--red); box-shadow: 0 0 6px var(--red); animation: stats-pulse 1.4s infinite; }
.space-header .badge-ended { background: #222; color: var(--grey); border: 1px solid var(--border); font-size: 11px; padding: 2px 8px; border-radius: 3px; }
.space-header h1 { font-size: 18px; color: var(--orange); margin: 8px 0 2px; }
.space-header .sub { font-size: 12px; color: var(--grey); }
.space-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 10px; margin-bottom: 16px; }
.space-avatar { background: var(--postbg); border: 1px solid var(--border); padding: 10px 6px; text-align: center; font-size: 12px; position: relative; }
.space-avatar.role-speaker, .space-avatar.role-host { border-color: var(--orange); box-shadow: 0 0 8px var(--orange2); }
.space-avatar .glyph { width: 42px; height: 42px; border-radius: 50%; background: var(--accent); color: #fff; display: flex; align-items: center; justify-content: center; margin: 0 auto 6px; font-weight: bold; font-size: 16px; }
.space-avatar.role-host .glyph { background: var(--orange); color: #000; }
.space-avatar .role-tag { font-size: 9px; color: var(--grey); text-transform: uppercase; letter-spacing: .5px; }
.space-avatar .muted-icon { position: absolute; top: 4px; right: 6px; font-size: 11px; }
.space-avatar .mod-btns { margin-top: 4px; display: flex; gap: 4px; justify-content: center; }
.space-avatar .mod-btns button { background: none; border: 1px solid var(--border); color: var(--grey); font-size: 10px; padding: 1px 5px; cursor: pointer; }
.space-avatar .mod-btns button:hover { color: var(--orange); border-color: var(--orange); }
.space-listeners-count { font-size: 12px; color: var(--grey); margin-bottom: 14px; }
.space-controls { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
.space-controls button { background: var(--surface); border: 1px solid var(--border); color: var(--white); font: 13px 'MapleMono', monospace; padding: 8px 14px; cursor: pointer; }
.space-controls button:hover { border-color: var(--orange); }
.space-controls button.danger { color: var(--red); border-color: var(--red); }
.space-requests { background: var(--surface); border: 1px solid var(--orange2); padding: 10px 12px; margin-bottom: 16px; font-size: 12px; }
.space-requests h4 { color: var(--orange); font-size: 12px; margin-bottom: 6px; }
.space-req-row { display: flex; align-items: center; gap: 8px; padding: 3px 0; }
.space-req-row button { background: none; border: 1px solid var(--border); color: var(--grey); font-size: 11px; padding: 1px 6px; cursor: pointer; }
.space-req-row button.accept:hover { color: #22aa44; border-color: #22aa44; }
.space-req-row button.deny:hover { color: var(--red); border-color: var(--red); }
.space-status-line { font-size: 11px; color: var(--grey); margin-top: 8px; }
</style>
</head>
<body class="page-space">
    <div id="topbar">
        <span class="site-name">@6chan</span>
        <a href="index.php">Home</a>
        <a href="board.php?b=<?= htmlspecialchars($space['board_slug']) ?>">/<?= htmlspecialchars($space['board_slug']) ?>/</a>
    </div>

    <div class="space-room">
        <div class="space-header">
            <?php if ($space['status'] === 'live'): ?>
                <span class="badge-live"><span class="dot"></span> LIVE</span>
            <?php else: ?>
                <span class="badge-ended">ENDED</span>
            <?php endif; ?>
            <h1><?= htmlspecialchars($space['title']) ?></h1>
            <div class="sub">
                hosted by <?= htmlspecialchars($space['host_author']) ?>
                <?php if ($space['host_trip']): ?><span class="tripcode"><?= htmlspecialchars($space['host_trip']) ?></span><?php endif; ?>
                — /<?= htmlspecialchars($space['board_slug']) ?>/
            </div>
        </div>

        <div id="space-requests" class="space-requests" style="display:none;">
            <h4>Raise hand requests</h4>
            <div id="space-requests-list"></div>
        </div>

        <div id="space-grid" class="space-grid"></div>
        <div class="space-listeners-count" id="space-listener-count"></div>

        <div class="space-controls" id="space-controls">
            <button id="btn-raise-hand" onclick="spaceRaiseHand()">Request to speak</button>
            <button id="btn-mute" onclick="spaceToggleMute()" style="display:none;">🔇 Mute</button>
            <button id="btn-end" class="danger" onclick="spaceEnd()" style="display:none;">End Space</button>
            <button id="btn-leave" onclick="spaceLeave()">Leave</button>
        </div>

        <div class="space-status-line" id="space-status">connecting…</div>
    </div>

    <script src="js/space.js"></script>
    <script>
        SpaceRoom.init({ spaceId: <?= (int)$space['id'] ?>, status: <?= json_encode($space['status']) ?> });
    </script>
</body>
</html>