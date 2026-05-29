(function() {
    const get = k => localStorage.getItem('6chan_' + k);

    const accent    = get('accent')   || '#ff6600';
    const bg        = get('bg')       || '#1a1a1a';
    const font      = get('font')     || "'MapleMono', monospace";
    const fontSize  = get('fontSize') || '14';
    const compact   = get('compact')  === 'true';
    const hideImgs  = get('hideImages') === 'true';
    const showNsfw  = get('showNsfw') === 'true';
    const tsFmt     = get('timestamps') || 'datetime';

    // Helpers
    function lighten(hex) {
        const n = parseInt(hex.slice(1), 16);
        const r = Math.min(255, (n >> 16) + 20);
        const g = Math.min(255, ((n >> 8) & 0xff) + 20);
        const b = Math.min(255, (n & 0xff) + 20);
        return '#' + [r,g,b].map(x => x.toString(16).padStart(2,'0')).join('');
    }
    function darken(hex) {
        const n = parseInt(hex.slice(1), 16);
        const r = Math.max(0, (n >> 16) - 40);
        const g = Math.max(0, ((n >> 8) & 0xff) - 40);
        const b = Math.max(0, (n & 0xff) - 40);
        return '#' + [r,g,b].map(x => x.toString(16).padStart(2,'0')).join('');
    }

    // Apply CSS variables
    const root = document.documentElement;
    root.style.setProperty('--orange',  accent);
    root.style.setProperty('--orange2', darken(accent));
    root.style.setProperty('--bg',      bg);
    root.style.setProperty('--surface', lighten(bg));
  
    document.addEventListener('DOMContentLoaded', () => {
        const gifbgUrl     = get('gifbgUrl')     || '';
        const gifbgOpacity = Number(get('gifbgOpacity') || 8);
        const gifbgSize = get('gifbgSize') || 'cover';
        const isBoard = document.body.classList.contains('page-board')
                   || document.body.classList.contains('page-thread');
    
        document.body.style.fontFamily = font;
        document.body.style.fontSize   = fontSize + 'px';

        if (compact) document.body.classList.add('compact');

        if (hideImgs) {
            document.querySelectorAll('.post-img img, .post-img video').forEach(el => {
                el.dataset.src = el.src;
                el.src = '';
                el.style.cursor = 'pointer';
                el.title = 'Click to load image';
                el.addEventListener('click', function() {
                    this.src = this.dataset.src;
                    this.style.cursor = '';
                    this.title = '';
                }, { once: true });
            });
        }

        if (showNsfw) {
            document.querySelectorAll('.nsfw-blur').forEach(el => {
                el.classList.remove('nsfw-blur');
                el.onclick = null;
            });
            document.querySelectorAll('.nsfw-label').forEach(el => {
                el.style.display = 'none';
            });
        }

        if (tsFmt === 'relative') {
            document.querySelectorAll('.postdate').forEach(el => {
                const raw = el.dataset.ts || el.textContent.trim();
                el.dataset.ts = raw;
                const d    = new Date(raw.replace(' ', 'T'));
                const diff = Math.floor((Date.now() - d) / 1000);
                if      (diff < 60)     el.textContent = 'just now';
                else if (diff < 3600)   el.textContent = Math.floor(diff/60) + 'm ago';
                else if (diff < 86400)  el.textContent = Math.floor(diff/3600) + 'h ago';
                else                    el.textContent = Math.floor(diff/86400) + 'd ago';
            });
        }

        if (isBoard && gifbgUrl) {
            const sizeVal  = gifbgSize === 'repeat' ? 'auto' : gifbgSize;
            const sizeMode = gifbgSize === 'repeat' ? 'repeat' : 'no-repeat';
    
            document.documentElement.style.setProperty(
                '--gifbg-url', 'url("' + gifbgUrl.replace(/"/g, '') + '")'
            );
            document.documentElement.style.setProperty(
                '--gifbg-opacity', (gifbgOpacity / 100).toFixed(2)
            );
            document.documentElement.style.setProperty(
                '--gifbg-size', sizeVal
            );
            document.body.classList.add('has-gifbg');
    
            // Performance: pause GIF when tab is not visible :3
            document.addEventListener('visibilitychange', () => {
                const el = document.querySelector('body::before');
                if (document.hidden) {
                    document.body.classList.remove('has-gifbg');
                } else {
                    document.body.classList.add('has-gifbg');
                }
            });
        }
    });
})();
