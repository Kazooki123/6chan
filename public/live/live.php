<?php
/**
 * This page is restricted to moderators ONLY.
 */
declare(strict_types=1);
session_start();
require __DIR__ . '/auth.php';
require __DIR__ . '/turn.php';

if (isset($_GET['debug'])) { var_dump($_SESSION); exit; }

$modId = pioneer_require_mod();
if ($modId === null) {
    http_response_code(403);
    die('Mods only.');
}

$token = pioneer_mint_token($modId);
$relayUrl = PIONEER_RELAY_URL;
$turn = pioneer_turn_credentials();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Pioneer :: 6chan</title>
<link rel="icon" type="image/x-icon" href="../assets/pioneer.ico"/>
<link rel="stylesheet" href="style/style.css">
</head>
<body class="pioneer-mod">
<header class="bar">
  <span class="wordmark">pioneer</span>
  <span class="tag">beta · mod console</span>
</header>
<main class="mod-panel">
  <label class="field">
    <span>stream title</span>
    <input id="title" type="text" placeholder="untitled broadcast" maxlength="80">
  </label>

  <video id="preview" autoplay muted playsinline></video>

  <div class="controls">
    <button id="startBtn">go live</button>
    <button id="stopBtn" disabled>end stream</button>
  </div>

  <p id="statusLine" class="status">offline</p>
</main>

<script>
const RELAY = <?= json_encode($relayUrl) ?>;
const TOKEN = <?= json_encode($token) ?>;
const TURN  = <?= json_encode($turn) ?>;

const preview = document.getElementById('preview');
const startBtn = document.getElementById('startBtn');
const stopBtn = document.getElementById('stopBtn');
const statusLine = document.getElementById('statusLine');
const titleInput = document.getElementById('title');

let pc = null;
let localStream = null;

async function goLive() {
  startBtn.disabled = true;
  statusLine.textContent = 'requesting camera/mic…';

  localStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
  preview.srcObject = localStream;

  pc = new RTCPeerConnection({
    iceServers: [
      { urls: 'stun:stun.l.google.com:19302' },
      { urls: TURN.urls, username: TURN.username, credential: TURN.credential },
    ],
  });
  localStream.getTracks().forEach(track => pc.addTrack(track, localStream));

  const offer = await pc.createOffer();
  await pc.setLocalDescription(offer);
  await waitForIceGathering(pc);

  const title = encodeURIComponent(titleInput.value || 'untitled broadcast');
  const res = await fetch(`${RELAY}/whip?title=${title}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/sdp',
      'Authorization': `Bearer ${TOKEN}`,
    },
    body: pc.localDescription.sdp,
  });

  if (!res.ok) {
    statusLine.textContent = `failed to go live: ${res.status} ${await res.text()}`;
    startBtn.disabled = false;
    return;
  }

  const answerSDP = await res.text();
  await pc.setRemoteDescription({ type: 'answer', sdp: answerSDP });

  statusLine.textContent = 'live';
  stopBtn.disabled = false;
}

async function endStream() {
  stopBtn.disabled = true;
  await fetch(`${RELAY}/whip`, {
    method: 'DELETE',
    headers: { 'Authorization': `Bearer ${TOKEN}` },
  });
  if (pc) pc.close();
  if (localStream) localStream.getTracks().forEach(t => t.stop());
  preview.srcObject = null;
  statusLine.textContent = 'offline';
  startBtn.disabled = false;
}

function waitForIceGathering(pc) {
  if (pc.iceGatheringState === 'complete') return Promise.resolve();
  return new Promise(resolve => {
    pc.addEventListener('icegatheringstatechange', () => {
      if (pc.iceGatheringState === 'complete') resolve();
    });
  });
}

startBtn.addEventListener('click', goLive);
stopBtn.addEventListener('click', endStream);
</script>
</body>
</html>
