<script>
(function () {
    const closeLabel = <?= json_encode($LANG['close'] ?? 'Close', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const closeIcon = <?= json_encode(iconSvg('x', 'w-5 h-5'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function hasWorkingTopClose(panel) {
        return Array.from(panel.querySelectorAll('button')).some(function (button) {
            const action = button.getAttribute('onclick') || '';
            const classes = button.className || '';
            const isCloseButton = /closeModal\(|hide\(/.test(action);
            const isAbsoluteTopRight = /\babsolute\b/.test(classes) && /\btop-/.test(classes) && /\bright-/.test(classes);
            const isHeaderButton = button.parentElement === panel.firstElementChild;
            return isCloseButton && (isAbsoluteTopRight || isHeaderButton);
        });
    }

    document.querySelectorAll('[id$="Modal"]').forEach(function (modal) {
        const panel = modal.firstElementChild;
        if (!panel || hasWorkingTopClose(panel)) return;

        panel.classList.add('relative');

        const closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'absolute top-4 right-4 z-10 text-slate-400 hover:text-slate-600';
        closeButton.setAttribute('aria-label', closeLabel);
        closeButton.setAttribute('title', closeLabel);
        closeButton.innerHTML = closeIcon;
        closeButton.addEventListener('click', function () {
            closeModal(modal.id);
        });

        const title = panel.querySelector('h1, h2, h3');
        if (title) title.classList.add('pr-8');

        panel.insertBefore(closeButton, panel.firstChild);
    });
})();
</script>
