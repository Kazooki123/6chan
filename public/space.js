const SpaceRoom = (() => {
    let cfg = null, sid = null, myRole = 'listener', turn = null;
    let lastSignalId = 0, localStream = null, polling = false, ended = false;

    const peers = new Map();
    const pendingIce = new Map();
    const participants = new Map();

    function sessionId() {
        let s = sessionStorage.getItem('space_sid');
        if (!s) { s = crypto.randomUUID(); sessionStorage.setItem('space_sid', s); }
        return s;
    }

    async function init(opts) {
        cfg = opts;
        sid = sessionId();

        if (cfg.status !== 'live') {
            setStatus('This Space has ended.');
            document.getElementById('space-controls').style.display = 'none';
            return;
        }

        const nameField = prompt('Join as (name#pwrd, optional - blank for Anon):', '') || '';
        const form = new FormData();
        form.append('sid', sid);
        form.append('space_id', cfg.spaceId);
        form.append('author', nameField);

        const res  = await fetch('services/space_join.php', { method: 'POST', body: form });
        const data = await res.json();
        if (!data.ok) { setStatus('Could not join: ' + data.error); return; }

        myRole        = data.role;
        turn          = data.turn;
        lastSignalId  = data.last_signal_id;
        cfg.myAuthor  = data.me.author;

        data.participants.forEach(p => { if (p.session_id !== sid) participants.set(p.session_id, p); });

        renderGrid();
        updateControlsForRole();
        if (myRole !== 'listener') await acquireMic();

        for (const [otherSid, p] of participants) considerConnecting(otherSid, p.role);

        setStatus('Connected.');
        pollLoop();
    }

    function iceServers() {
        return turn.uris.map(u => ({ urls: u, username: turn.username, credential: turn.credential }));
    }

    async function acquireMic() {
        try { localStream = await navigator.mediaDevices.getUserMedia({ audio: true }); }
        catch (e) { setStatus('Mic access denied - you can still listen.'); }
    }

    function considerConnecting(otherSid, otherRole) {
        if (peers.has(otherSid)) return;
        if (myRole === 'listener') {
            if (otherRole !== 'listener') connectRecvOnly(otherSid);
            return;
        }
        if (otherRole !== 'listener' && sid < otherSid) connectSendRecv(otherSid);
    }

    function newPeerConnection(otherSid) {
        const pc = new RTCPeerConnection({ iceServers: iceServers() });
        peers.set(otherSid, pc);
        pc.onicecandidate = (e) => { if (e.candidate) sendSignal('ice', otherSid, { candidate: e.candidate }); };
        pc.ontrack = (e) => {
            let el = document.getElementById('remote-audio-' + otherSid);
            if (!el) {
                el = document.createElement('audio');
                el.id = 'remote-audio-' + otherSid;
                el.autoplay = true;
                document.body.appendChild(el);
            }
            el.srcObject = e.streams[0];
        };
        pc.onconnectionstatechange = () => {
            if (['failed', 'closed', 'disconnected'].includes(pc.connectionState)) teardownPeer(otherSid);
        };
        return pc;
    }

    async function connectRecvOnly(otherSid) {
        const pc = newPeerConnection(otherSid);
        pc.addTransceiver('audio', { direction: 'recvonly' });
        const offer = await pc.createOffer();
        await pc.setLocalDescription(offer);
        sendSignal('offer', otherSid, { sdp: pc.localDescription });
    }

    async function connectSendRecv(otherSid) {
        const pc = newPeerConnection(otherSid);
        if (localStream) localStream.getTracks().forEach(t => pc.addTrack(t, localStream));
        const offer = await pc.createOffer();
        await pc.setLocalDescription(offer);
        sendSignal('offer', otherSid, { sdp: pc.localDescription });
    }

    async function handleOffer(fromSid, sdp) {
        let pc = peers.get(fromSid);
        if (!pc) {
            pc = newPeerConnection(fromSid);
            if (myRole !== 'listener' && localStream) localStream.getTracks().forEach(t => pc.addTrack(t, localStream));
            else if (myRole === 'listener') pc.addTransceiver('audio', { direction: 'recvonly' });
        }
        await pc.setRemoteDescription(sdp);
        await flushIce(fromSid, pc);
        const answer = await pc.createAnswer();
        await pc.setLocalDescription(answer);
        sendSignal('answer', fromSid, { sdp: pc.localDescription });
    }

    async function handleAnswer(fromSid, sdp) {
        const pc = peers.get(fromSid);
        if (!pc) return;
        await pc.setRemoteDescription(sdp);
        await flushIce(fromSid, pc);
    }

    async function handleIce(fromSid, candidate) {
        const pc = peers.get(fromSid);
        if (!pc || !pc.remoteDescription) {
            const q = pendingIce.get(fromSid) || [];
            q.push(candidate);
            pendingIce.set(fromSid, q);
            return;
        }
        await pc.addIceCandidate(candidate);
    }

    async function flushIce(otherSid, pc) {
        const q = pendingIce.get(otherSid);
        if (!q) return;
        for (const c of q) await pc.addIceCandidate(c);
        pendingIce.delete(otherSid);
    }

    function teardownPeer(otherSid) {
        const pc = peers.get(otherSid);
        if (pc) { pc.close(); peers.delete(otherSid); }
        const el = document.getElementById('remote-audio-' + otherSid);
        if (el) el.remove();
    }

    function sendSignal(type, to, payload) {
        const form = new FormData();
        form.append('sid', sid);
        form.append('space_id', cfg.spaceId);
        form.append('type', type);
        if (to) form.append('to', to);
        form.append('payload', JSON.stringify(payload));
        fetch('services/space_signal.php', { method: 'POST', body: form });
    }

    async function pollLoop() {
        if (polling || ended) return;
        polling = true;
        try {
            while (!ended) {
                const url = `services/space_poll.php?space_id=${cfg.spaceId}&sid=${sid}&since=${lastSignalId}`;
                const res  = await fetch(url);
                const data = await res.json();
                if (!data.ok) break;
                lastSignalId = data.last_id;
                for (const sig of data.signals) await handleSignal(sig);
            }
        } finally { polling = false; }
    }

    async function handleSignal(sig) {
        const payload = typeof sig.payload === 'string' ? JSON.parse(sig.payload) : sig.payload;
        switch (sig.type) {
            case 'offer':  await handleOffer(sig.from_session, payload.sdp); break;
            case 'answer': await handleAnswer(sig.from_session, payload.sdp); break;
            case 'ice':    await handleIce(sig.from_session, payload.candidate); break;

            case 'join':
                participants.set(sig.from_session, {
                    session_id: sig.from_session, author: payload.author,
                    tripcode: payload.tripcode, role: payload.role || 'listener', muted: false
                });
                considerConnecting(sig.from_session, payload.role || 'listener');
                renderGrid();
                break;

            case 'ended': onEnded(); break;

            case 'leave':
                participants.delete(sig.from_session);
                teardownPeer(sig.from_session);
                renderGrid();
                break;

            case 'promote': {
                const p = participants.get(payload.target);
                if (p) p.role = 'speaker';
                if (payload.target === sid) {
                    myRole = 'speaker';
                    await acquireMic();
                    updateControlsForRole();
                    for (const [otherSid, op] of participants) if (op.role !== 'listener') connectSendRecv(otherSid);
                } else if (p) {
                    considerConnecting(payload.target, 'speaker');
                }
                renderGrid();
                break;
            }

            case 'demote': {
                const p = participants.get(payload.target);
                if (p) p.role = 'listener';
                if (payload.target === sid) { myRole = 'listener'; updateControlsForRole(); }
                teardownPeer(payload.target);
                renderGrid();
                break;
            }

            case 'mute':
            case 'unmute': {
                const p = participants.get(payload.target);
                if (p) p.muted = (sig.type === 'mute');
                renderGrid();
                break;
            }

            case 'hand_raise':
                if (myRole === 'host') addHandRaise(sig.from_session, payload.author);
                break;
        }
    }

    function onEnded() {
        ended = true;
        setStatus('Host ended this Space.');
        for (const s of peers.keys()) teardownPeer(s);
        document.getElementById('space-controls').style.display = 'none';
    }

    function renderGrid() {
        const grid = document.getElementById('space-grid');
        grid.innerHTML = '';
        const all = [{ session_id: sid, author: cfg.myAuthor || 'You', role: myRole, muted: false }, ...participants.values()];
        for (const p of all) {
            const div = document.createElement('div');
            div.className = 'space-avatar role-' + p.role;
            div.innerHTML = `
                <div class="glyph">${(p.author || 'A')[0].toUpperCase()}</div>
                ${p.muted ? '<span class="muted-icon">🔇</span>' : ''}
                <div>${escapeHtml(p.author || 'Anon')}</div>
                <div class="role-tag">${p.role}</div>`;
            if (myRole === 'host' && p.session_id !== sid && p.role === 'speaker') {
                const btns = document.createElement('div');
                btns.className = 'mod-btns';
                btns.innerHTML = `<button onclick="SpaceRoom.mute('${p.session_id}',true)">mute</button>
                                   <button onclick="SpaceRoom.demote('${p.session_id}')">demote</button>`;
                div.appendChild(btns);
            }
            grid.appendChild(div);
        }
        document.getElementById('space-listener-count').textContent =
            all.filter(p => p.role === 'listener').length + ' listening';
    }

    function addHandRaise(fromSid, author) {
        const box = document.getElementById('space-requests');
        const list = document.getElementById('space-requests-list');
        box.style.display = 'block';
        if (document.getElementById('req-' + fromSid)) return;
        const row = document.createElement('div');
        row.className = 'space-req-row';
        row.id = 'req-' + fromSid;
        row.innerHTML = `<span>${escapeHtml(author)}</span>
            <button class="accept" onclick="SpaceRoom.approveSpeak('${fromSid}')">accept</button>
            <button class="deny" onclick="SpaceRoom.denySpeak('${fromSid}')">deny</button>`;
        list.appendChild(row);
    }

    function updateControlsForRole() {
        document.getElementById('btn-raise-hand').style.display = myRole === 'listener' ? '' : 'none';
        document.getElementById('btn-mute').style.display       = myRole !== 'listener' ? '' : 'none';
        document.getElementById('btn-end').style.display        = myRole === 'host' ? '' : 'none';
    }

    function setStatus(msg) { const el = document.getElementById('space-status'); if (el) el.textContent = msg; }
    function escapeHtml(s) { return (s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function raiseHand() {
        const form = new FormData();
        form.append('sid', sid);
        form.append('space_id', cfg.spaceId);
        fetch('services/space_request_speak.php', { method: 'POST', body: form });
        setStatus('Hand raised — waiting for the host.');
    }

    let muted = false;
    function toggleMute() {
        if (!localStream) return;
        muted = !muted;
        localStream.getAudioTracks().forEach(t => t.enabled = !muted);
        document.getElementById('btn-mute').textContent = muted ? '🔈 Unmute' : '🔇 Mute';
    }

    function moderate(action, target) {
        const form = new FormData();
        form.append('sid', sid);
        form.append('space_id', cfg.spaceId);
        form.append('action', action);
        if (target) form.append('target', target);
        fetch('services/space_moderate.php', { method: 'POST', body: form });
        document.getElementById('req-' + target)?.remove();
    }

    function leave() {
        const form = new FormData();
        form.append('sid', sid);
        form.append('space_id', cfg.spaceId);
        navigator.sendBeacon('services/space_leave.php', form);
        window.location.href = 'board.php';
    }

    return {
        init, raiseHand, toggleMute,
        mute: (t, on) => moderate(on ? 'mute' : 'unmute', t),
        demote: (t) => moderate('demote', t),
        approveSpeak: (t) => moderate('approve_speaker', t),
        denySpeak: (t) => moderate('deny_speaker', t),
        end: () => moderate('end'),
        leave,
    };
})();

function spaceRaiseHand() { SpaceRoom.raiseHand(); }
function spaceToggleMute() { SpaceRoom.toggleMute(); }
function spaceEnd() { if (confirm('End this Space for everyone?')) SpaceRoom.end(); }
function spaceLeave() { SpaceRoom.leave(); }