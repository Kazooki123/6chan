<?php
require_once '../../config/db.php';
$db     = get_db();
$boards = $db->query("SELECT slug, name FROM boards ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Settings — @6chan</title>
<link rel="icon" type="image/x-icon" href="../assets/favicon.ico">
<link rel="stylesheet" href="../style.css">
<style>
.settings-wrap  { max-width: 600px; margin: 0 auto; padding: 12px; }
.setting-group  {
    background: var(--surface);
    border: 1px solid var(--border);
    padding: 14px 18px;
    margin-bottom: 14px;
}
.setting-group h2 {
    color: var(--orange);
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 1px;
    border-bottom: 1px solid var(--border);
    padding-bottom: 4px;
    margin-bottom: 12px;
}
.setting-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 6px 0;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
}
.setting-row:last-child { border-bottom: none; }
.setting-label { color: var(--white); }
.setting-desc  { color: var(--grey); font-size: 11px; margin-top: 2px; }
.setting-control { flex-shrink: 0; margin-left: 16px; }

/* Color swatches */
.swatch-row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
.swatch {
    width: 32px; height: 32px;
    border-radius: 3px;
    border: 2px solid transparent;
    cursor: pointer;
    transition: border-color .15s;
}
.swatch.active,
.swatch:hover { border-color: var(--white); }

.font-option {
    background: #111;
    border: 1px solid var(--border);
    color: var(--white);
    padding: 6px 10px;
    cursor: pointer;
    font-size: 13px;
    margin-bottom: 4px;
    display: block;
    width: 100%;
    text-align: left;
    transition: border-color .15s;
}
.font-option.active,
.font-option:hover { border-color: var(--orange); color: var(--orange); }

input[type=range] {
    accent-color: var(--orange);
    width: 140px;
}

.save-btn {
    background: var(--orange);
    border: none;
    color: #000;
    font: bold 13px 'Courier New', monospace;
    padding: 8px 24px;
    cursor: pointer;
    width: 100%;
    margin-top: 4px;
}
.save-btn:hover { background: var(--orange2); }
.save-confirm {
    color: #6f6;
    font-size: 12px;
    text-align: center;
    margin-top: 8px;
    display: none;
}

.preview-box {
    background: var(--surface);
    border: 1px solid var(--border);
    padding: 12px;
    margin-top: 8px;
    font-size: 13px;
}
.preview-box .preview-header {
    font-size: 12px;
    color: var(--grey);
    margin-bottom: 4px;
}
.preview-box .preview-subject { color: #89c4ff; font-weight: bold; margin-right: 6px; }
.preview-box .preview-author  { color: var(--orange); font-weight: bold; margin-right: 6px; }
.preview-box .preview-num     { color: var(--grey); font-size: 12px; }
.preview-box .preview-body    { margin-top: 6px; line-height: 1.5; }
.preview-box .preview-gt      { color: #789922; }
</style>
</head>
<body>
<div id="topbar">
    <span class="site-name">@6chan</span>
    <a href="../index.php">Home</a>
    <a href="../rules.php">[Rules]</a>
    <a href="../search.php">[Search]</a>
    <a href="../support.php">[Support]</a>
    <a href="../contact.php">[Contact]</a>
</div>

<div class="settings-wrap">
    <div class="page-header">
        <h1>Settings</h1>
        <p>Preferences are saved locally in your browser.</p>
    </div>

    <div class="setting-group">
        <h2>Color Scheme</h2>
        <div class="setting-row">
            <div>
                <div class="setting-label">Accent color</div>
                <div class="setting-desc">The primary orange can be retuned.</div>
            </div>
        </div>
        <div class="swatch-row" id="accent-swatches">
            <div class="swatch" data-color="#ff6600" style="background:#ff6600;" title="Default Orange"></div>
            <div class="swatch" data-color="#ff3300" style="background:#ff3300;" title="Red Orange"></div>
            <div class="swatch" data-color="#ffaa00" style="background:#ffaa00;" title="Amber"></div>
            <div class="swatch" data-color="#00cc88" style="background:#00cc88;" title="Mint"></div>
            <div class="swatch" data-color="#4a9eff" style="background:#4a9eff;" title="Blue"></div>
            <div class="swatch" data-color="#cc44ff" style="background:#cc44ff;" title="Purple"></div>
            <div class="swatch" data-color="#ff4488" style="background:#ff4488;" title="Pink"></div>
            <div class="swatch" data-color="#ffffff" style="background:#ffffff;" title="White"></div>
        </div>

        <div class="setting-row" style="margin-top:12px;">
            <div>
                <div class="setting-label">Background</div>
                <div class="setting-desc">Page background darkness.</div>
            </div>
            <div class="setting-control">
                <select id="bg-select" style="background:#111;border:1px solid var(--border);
                        color:var(--white);font:13px 'Courier New',monospace;padding:4px 8px;">
                    <option value="#1a1a1a">Dark (default)</option>
                    <option value="#111111">Darker</option>
                    <option value="#242424">Slightly lighter</option>
                    <option value="#f0ebe3">Light (paper)</option>
                    <option value="#0d0d0d">OLED Black</option>
                </select>
            </div>
        </div>
    </div>

    <div class="setting-group">
        <h2>Font</h2>
        <div id="font-options">
            <button class="font-option" data-font="'Courier New', Courier, monospace"
                    style="font-family:'Courier New',monospace;">
                Courier New
            </button>
            <button class="font-option" data-font="'Arial', sans-serif"
                    style="font-family:Arial,sans-serif;">
                Arial
            </button>
            <button class="font-option" data-font="'Georgia', serif"
                    style="font-family:Georgia,serif;">
                Georgia
            </button>
            <button class="font-option" data-font="'Trebuchet MS', sans-serif"
                    style="font-family:'Trebuchet MS',sans-serif;">
                Trebuchet MS
            </button>
            <button class="font-option" data-font="'Verdana', sans-serif"
                    style="font-family:Verdana,sans-serif;">
                Verdana
            </button>
            <button class="font-option"  data-font="'Minecraft', monospace"
                    style="font-family:Minecraft, monospace;">
                Minecraft
            </button>
            <button class="font-option" data-font="'SuperMario256', monospace"
                    style="font-family:SuperMario256, monospace;">
                Super Mario 256
            </button>
            <?php
            $custom_fonts = [
                'MapleMono' => "'MapleMono', monospace"  
            ];
            foreach ($custom_fonts as $label => $family): ?>
            <button class="font-option" data-font="<?= htmlspecialchars($family) ?>"
                    style="font-family:<?= htmlspecialchars($family) ?>;">
                <?= htmlspecialchars($label) ?> — Custom
            </button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="setting-group">
        <h2>Font Size</h2>
        <div class="setting-row">
            <div>
                <div class="setting-label">Post text size</div>
                <div class="setting-desc" id="size-label">14px (default)</div>
            </div>
            <div class="setting-control">
                <input type="range" id="font-size" min="11" max="20" value="14" step="1">
            </div>
        </div>
    </div>

    <div class="setting-group">
        <h2>Layout</h2>
        <div class="setting-row">
            <div>
                <div class="setting-label">Compact mode</div>
                <div class="setting-desc">Reduce padding between posts.</div>
            </div>
            <div class="setting-control">
                <input type="checkbox" id="compact-mode"
                       style="accent-color:var(--orange);width:16px;height:16px;">
            </div>
        </div>
        <div class="setting-row">
            <div>
                <div class="setting-label">Hide images by default</div>
                <div class="setting-desc">All images collapsed until clicked.</div>
            </div>
            <div class="setting-control">
                <input type="checkbox" id="hide-images"
                       style="accent-color:var(--orange);width:16px;height:16px;">
            </div>
        </div>
        <div class="setting-row">
            <div>
                <div class="setting-label">Show NSFW content</div>
                <div class="setting-desc">Skip blur on NSFW marked posts.</div>
            </div>
            <div class="setting-control">
                <input type="checkbox" id="show-nsfw"
                       style="accent-color:var(--orange);width:16px;height:16px;">
            </div>
        </div>
        <div class="setting-row">
            <div>
                <div class="setting-label">Timestamps</div>
                <div class="setting-desc">How dates display on posts.</div>
            </div>
            <div class="setting-control">
                <select id="timestamp-fmt" style="background:#111;border:1px solid var(--border);
                        color:var(--white);font:13px 'Courier New',monospace;padding:4px 8px;">
                    <option value="datetime">2026-05-18 14:32 (default)</option>
                    <option value="relative">3 hours ago</option>
                    <option value="date">2026-05-18</option>
                </select>
            </div>
        </div>
    </div>

    <div class="setting-group">
        <h2>Live Preview</h2>
        <div class="preview-box" id="preview-box">
            <div class="preview-header">
                <span class="preview-subject">Cool Thread</span>
                <span class="preview-author">Anon</span>
                <span class="preview-num">No.42</span>
                <span style="font-size:11px;color:var(--grey);margin-left:6px;"
                      id="preview-date">2026-05-18 14:32</span>
            </div>
            <div class="preview-body">
                This is what your post text looks like.<br>
                <span class="preview-gt">&gt;greentexting is a core feature</span><br>
                Regular text continues here after the greentext.
            </div>
        </div>
    </div>

    <div class="setting-group">
        <h2>Board &amp; Thread Background</h2>
    
        <div class="setting-row">
            <div>
                <div class="setting-label">GIF / Image background</div>
                <div class="setting-desc">
                    Shown only on board and thread pages. Paste a direct URL to
                    any GIF or image. Leave blank to disable.
                </div>
            </div>
        </div>
    
        <div style="margin-top:8px;">
            <input type="text" id="gifbg-url"
                   placeholder="https://example.com/cool.gif"
                   style="width:100%;background:#111;border:1px solid var(--border);
                          color:var(--white);font:12px 'Courier New',monospace;
                          padding:5px 8px;box-sizing:border-box;">
        </div>
    
        <div class="setting-row" style="margin-top:10px;">
            <div>
                <div class="setting-label">Opacity</div>
                <div class="setting-desc" id="gifbg-opacity-label">8% (subtle)</div>
            </div>
            <div class="setting-control">
                <input type="range" id="gifbg-opacity"
                       min="2" max="40" value="8" step="1">
            </div>
        </div>
    
        <div class="setting-row">
            <div>
                <div class="setting-label">Size</div>
                <div class="setting-desc">How the image fills the background.</div>
            </div>
            <div class="setting-control">
                <select id="gifbg-size"
                        style="background:#111;border:1px solid var(--border);
                               color:var(--white);font:13px 'Courier New',monospace;
                               padding:4px 8px;">
                    <option value="cover">Cover (fill, crop edges)</option>
                    <option value="contain">Contain (fit, show borders)</option>
                    <option value="repeat">Tile (repeat pattern)</option>
                    <option value="200px">Small tile (200px)</option>
                    <option value="400px">Medium tile (400px)</option>
                </select>
            </div>
        </div>
    
        <div id="gifbg-preview"
             style="margin-top:10px;height:80px;border:1px solid var(--border);
                    background:#111;position:relative;overflow:hidden;
                    display:flex;align-items:center;justify-content:center;">
            <div id="gifbg-preview-bg"
                 style="position:absolute;inset:0;background-size:cover;
                        background-position:center;opacity:0.08;
                        will-change:opacity;transform:translateZ(0);"></div>
            <span style="position:relative;font-size:12px;color:var(--grey);z-index:1;">
                Preview — paste a URL above
            </span>
        </div>
    
        <div style="font-size:11px;color:var(--grey);margin-top:6px;line-height:1.6;">
            ⚠ Large GIFs can impact performance on older devices.
            Keep files under 2MB for best results.
            The background only shows on board and thread pages.
        </div>
    </div>

    <div class="setting-group">
        <button class="save-btn" onclick="saveSettings()">Save Settings</button>
        <div class="save-confirm" id="save-confirm">✔ Settings saved!</div>
        <div style="text-align:center;margin-top:10px;">
            <a href="#" onclick="resetSettings();return false;"
               style="color:var(--grey);font-size:12px;">[Reset to defaults]</a>
        </div>
    </div>
</div>

<footer>@6chan — <a href="../index.php">Home</a></footer>

<script id="gifbg-settings-script">
const DEFAULTS = {
    accent:      '#ff6600',
    bg:          '#1a1a1a',
    font:        "'MapleMono', monospace",
    fontSize:    14,
    compact:     false,
    hideImages:  false,
    showNsfw:    false,
    timestamps:  'datetime',
    gifbgUrl:     '',
    gifbgOpacity: 8,
    gifbgSize:    'cover',
};

function loadSettings() {
    const s = {};
    for (const [k, def] of Object.entries(DEFAULTS)) {
        const raw = localStorage.getItem('6chan_' + k);
        if (raw === null) { s[k] = def; continue; }
        if (typeof def === 'boolean') s[k] = raw === 'true';
        else if (typeof def === 'number') s[k] = Number(raw);
        else s[k] = raw;
    }
    return s;
}

function applySettings(s) {
    const root = document.documentElement;
    root.style.setProperty('--orange',  s.accent);
    root.style.setProperty('--orange2', darken(s.accent));
    root.style.setProperty('--bg',      s.bg);
    root.style.setProperty('--surface', lighten(s.bg));
    document.body.style.fontFamily = s.font;
    document.body.style.fontSize   = s.fontSize + 'px';

    if (s.compact)
        document.body.classList.add('compact');
    else
        document.body.classList.remove('compact');
}

function populateControls(s) {
    document.querySelectorAll('#accent-swatches .swatch').forEach(sw => {
        sw.classList.toggle('active', sw.dataset.color === s.accent);
    });

    document.getElementById('bg-select').value = s.bg;
    document.querySelectorAll('.font-option').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.font === s.font);
    });

    const sizeEl = document.getElementById('font-size');
    sizeEl.value = s.fontSize;
    document.getElementById('size-label').textContent = s.fontSize + 'px';

    document.getElementById('compact-mode').checked  = s.compact;
    document.getElementById('hide-images').checked   = s.hideImages;
    document.getElementById('show-nsfw').checked     = s.showNsfw;
    document.getElementById('timestamp-fmt').value = s.timestamps;
}

function saveSettings() {
    const s = collectControls();
    for (const [k, v] of Object.entries(s)) {
        localStorage.setItem('6chan_' + k, v);
    }
    applySettings(s);
    const confirm = document.getElementById('save-confirm');
    confirm.style.display = 'block';
    setTimeout(() => confirm.style.display = 'none', 2500);
}

function collectControls() {
    const activeAccent = document.querySelector('#accent-swatches .swatch.active');
    const activeFont   = document.querySelector('.font-option.active');
    return {
        accent:     activeAccent ? activeAccent.dataset.color : DEFAULTS.accent,
        bg:         document.getElementById('bg-select').value,
        font:       activeFont ? activeFont.dataset.font : DEFAULTS.font,
        fontSize:   Number(document.getElementById('font-size').value),
        compact:    document.getElementById('compact-mode').checked,
        hideImages: document.getElementById('hide-images').checked,
        showNsfw:   document.getElementById('show-nsfw').checked,
        timestamps: document.getElementById('timestamp-fmt').value,
        gifbgUrl:     document.getElementById('gifbg-url').value.trim(),
        gifbgOpacity: Number(document.getElementById('gifbg-opacity').value),
        gifbgSize:    document.getElementById('gifbg-size').value,
    };
}

function updateGifPreview(url, opacity, size) {
    const bg = document.getElementById('gifbg-preview-bg');
    const sizeVal = size === 'repeat' ? 'auto' : size;
    const sizeMode = size === 'repeat' ? 'repeat' : 'no-repeat';

    if (url) {
        bg.style.backgroundImage    = 'url(' + CSS.escape(url) + ')';
        bg.style.backgroundSize     = sizeVal;
        bg.style.backgroundRepeat   = sizeMode;
        bg.style.opacity            = opacity / 100;
    } else {
        bg.style.backgroundImage    = 'none';
        bg.style.opacity            = 0.08;
    }
}

function resetSettings() {
    for (const k of Object.keys(DEFAULTS)) {
        localStorage.removeItem('6chan_' + k);
    }
    populateControls(DEFAULTS);
    applySettings(DEFAULTS);
    const confirm = document.getElementById('save-confirm');
    confirm.style.display = 'block';
    confirm.textContent = '✔ Reset to defaults.';
    setTimeout(() => { confirm.style.display = 'none'; confirm.textContent = 'Settings saved!'; }, 2000);
}

function darken(hex) {
    const n = parseInt(hex.slice(1), 16);
    const r = Math.max(0, (n >> 16) - 40);
    const g = Math.max(0, ((n >> 8) & 0xff) - 40);
    const b = Math.max(0, (n & 0xff) - 40);
    return '#' + [r,g,b].map(x => x.toString(16).padStart(2,'0')).join('');
}
function lighten(hex) {
    const n = parseInt(hex.slice(1), 16);
    const r = Math.min(255, (n >> 16) + 20);
    const g = Math.min(255, ((n >> 8) & 0xff) + 20);
    const b = Math.min(255, (n & 0xff) + 20);
    return '#' + [r,g,b].map(x => x.toString(16).padStart(2,'0')).join('');
}

document.querySelectorAll('#accent-swatches .swatch').forEach(sw => {
    sw.addEventListener('click', function() {
        document.querySelectorAll('#accent-swatches .swatch')
                .forEach(s => s.classList.remove('active'));
        this.classList.add('active');
        document.documentElement.style.setProperty('--orange', this.dataset.color);
        document.documentElement.style.setProperty('--orange2', darken(this.dataset.color));
    });
});

document.getElementById('bg-select').addEventListener('change', function() {
    document.documentElement.style.setProperty('--bg', this.value);
    document.documentElement.style.setProperty('--surface', lighten(this.value));
});

document.querySelectorAll('.font-option').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.font-option').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        document.body.style.fontFamily = this.dataset.font;
        document.getElementById('preview-box').style.fontFamily = this.dataset.font;
    });
});

document.getElementById('font-size').addEventListener('input', function() {
    const size = this.value;
    document.getElementById('size-label').textContent = size + 'px';
    document.getElementById('preview-box').style.fontSize = size + 'px';
});

document.getElementById('compact-mode').addEventListener('change', function() {
    document.body.classList.toggle('compact', this.checked);
});

document.getElementById('gifbg-url').addEventListener('input', function() {
    const opacity = document.getElementById('gifbg-opacity').value;
    const size    = document.getElementById('gifbg-size').value;
    updateGifPreview(this.value.trim(), opacity, size);
});

document.getElementById('gifbg-opacity').addEventListener('input', function() {
    const url  = document.getElementById('gifbg-url').value.trim();
    const size = document.getElementById('gifbg-size').value;
    document.getElementById('gifbg-opacity-label').textContent =
        this.value + '% opacity';
    updateGifPreview(url, this.value, size);
});

document.getElementById('gifbg-size').addEventListener('change', function() {
    const url     = document.getElementById('gifbg-url').value.trim();
    const opacity = document.getElementById('gifbg-opacity').value;
    updateGifPreview(url, opacity, this.value);
});

const s = loadSettings();
applySettings(s);
populateControls(s);
</script>
</body>
</html>
