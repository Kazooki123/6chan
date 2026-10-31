<?php

define('LATEX_KATEX_CSS', 'services/katex/katex.min.css');
define('LATEX_KATEX_JS', 'services/katex/katex.min.js');
define('LATEX_AUTO_RENDER_JS', 'services/katex/contrib/auto-render.min.js');

function render_latex_assets(): string {
    return <<<HTML
<link rel="stylesheet" href="services/katex/katex.min.css">
<script src="services/katex/katex.min.js"></script>
<script src="services/katex/contrib/auto-render.min.js"></script>
<script>
(function () {
    function renderPostMath() {
        if (typeof renderMathInElement !== 'function') return;
        document.querySelectorAll('.post-body').forEach(function (el) {
            renderMathInElement(el, {
                delimiters: [
                    {left: '$$', right: '$$', display: true},
                    {left: '\\[', right: '\\]', display: true},
                    {left: '\\(', right: '\\)', display: false},
                    {left: '$', right: '$', display: false}
                ],
                throwOnError: false,
                errorColor: '#cc2200',
                ignoredTags: ['script', 'noscript', 'style', 'textarea', 'pre', 'code', 'option'],
                ignoredClasses: ['prettyprint', 'source-code']
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderPostMath, { once: true });
    } else {
        renderPostMath();
    }
})();
</script>
HTML;
}
