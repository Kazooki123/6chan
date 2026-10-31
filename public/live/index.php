<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require __DIR__ . '/turn.php';

$relayUrl = PIONEER_RELAY_URL;
$pocketbaseUrl = 'http://127.0.0.1:8090';
$turn = pioneer_turn_credentials();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pioneer</title>
<link rel="icon" type="image/x-icon" href="../assets/pioneer.ico"/>
<link rel="stylesheet" href="style/style.css">
<script src="../js/oneko.js" defer></script>
</head>
<body class="pioneer-viewer">
<header class="bar">
  <a class="wordmark" href="/">pioneer</a>
  <span class="tag">beta</span>
  <span id="liveBadge" class="badge offline">offline</span>
</header>

<main class="viewer-layout">
  <section class="stage">
    <video id="player" autoplay playsinline controls></video>
    <h1 id="streamTitle" class="stream-title">no stream right now</h1>
  </section>

  <aside class="chat">
    <div id="chatLog" class="chat-log"></div>
    <form id="chatForm" class="chat-form">
      <input id="chatName" type="text" placeholder="Name" maxlength="24">
      <input id="chatBody" type="text" placeholder="Say something…" maxlength="300" autocomplete="off" required>
      <button type="submit">send</button>
    </form>
  </aside>
</main>

<script src="pocketbase.umd.js"></script>
<script>
const RELAY = <?= json_encode($relayUrl) ?>;
const PB_URL = <?= json_encode($pocketbaseUrl) ?>;
const TURN  = <?= json_encode($turn) ?>;
const pb = new PocketBase(PB_URL);

const player = document.getElementById('player');
const liveBadge = document.getElementById('liveBadge');
const streamTitle = document.getElementById('streamTitle');
const chatLog = document.getElementById('chatLog');
const chatForm = document.getElementById('chatForm');

let currentSessionId = null;
let watching = false;

async function pollStatus() {
  try {
    const res = await fetch(`${RELAY}/status`);
    const s = await res.json();

    if (s.live && !watching) {
      watching = true;
      streamTitle.textContent = s.title || 'untitled broadcast';
      liveBadge.textContent = 'live';
      liveBadge.className = 'badge live';
      const ok = await connectWHEP();
      if (!ok) watching = false;
    } else if (!s.live && watching) {
      watching = false;
      liveBadge.textContent = 'offline';
      liveBadge.className = 'badge offline';
      streamTitle.textContent = 'no stream right now';
      player.srcObject = null;
    }
  } catch (e) {}
  setTimeout(pollStatus, 4000);
}

async function connectWHEP() {
  const pc = new RTCPeerConnection({
    iceServers: [
      { urls: 'stun:stun.l.google.com:19302' },
      { urls: TURN.urls, username: TURN.username, credential: TURN.credential },
    ],
   });
  pc.ontrack = (event) => {
    if (player.srcObject !== event.streams[0]) player.srcObject = event.streams[0];
  };
  pc.addTransceiver('video', { direction: 'recvonly' });
  pc.addTransceiver('audio', { direction: 'recvonly' });

  const offer = await pc.createOffer();
  await pc.setLocalDescription(offer);
  await waitForIceGathering(pc);

  const res = await fetch(`${RELAY}/whep`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/sdp' },
    body: pc.localDescription.sdp,
  });
  if (!res.ok) { pc.close(); return false; };
  const answerSDP = await res.text();
  await pc.setRemoteDescription({ type: 'answer', sdp: answerSDP });
  return true;
}

function waitForIceGathering(pc) {
  if (pc.iceGatheringState === 'complete') return Promise.resolve();
  return new Promise(resolve => {
    pc.addEventListener('icegatheringstatechange', () => {
      if (pc.iceGatheringState === 'complete') resolve();
    });
  });
}


function renderMessage(msg) {
  const line = document.createElement('p');
  line.className = 'chat-line';
  const name = msg.display_name?.trim() || 'anon';
  line.innerHTML = `<span class="chat-name">${escapeHTML(name)}</span> ${escapeHTML(msg.body)}`;
  chatLog.appendChild(line);
  chatLog.scrollTop = chatLog.scrollHeight;
}

function escapeHTML(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

async function loadRecentChat() {
  const records = await pb.collection('chat_messages').getList(1, 50, { sort: 'created' });
  records.items.forEach(renderMessage);
  pb.collection('chat_messages').subscribe('*', (e) => {
    if (e.action === 'create') renderMessage(e.record);
  });
}

chatForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  const name = document.getElementById('chatName').value;
  const body = document.getElementById('chatBody').value.trim();
  if (!body) return;
  document.getElementById('chatBody').value = '';
  await pb.collection('chat_messages').create({ display_name: name, body });
});

pollStatus();
loadRecentChat();
</script>
</body>
</html>
