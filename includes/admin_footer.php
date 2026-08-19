    </main><!-- /page content -->
</div><!-- /main column -->
</div><!-- /flex wrapper -->

<script>
// ─── Sidebar toggle ───────────────────────────────────────────
function openSidebar() {
    document.getElementById('sidebar').classList.remove('-translate-x-full');
    document.getElementById('sidebar-overlay').classList.remove('hidden');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.add('-translate-x-full');
    document.getElementById('sidebar-overlay').classList.add('hidden');
}

// ─── Modal helpers ────────────────────────────────────────────
function openModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('hidden');
    el.classList.add('flex');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.add('hidden');
    el.classList.remove('flex');
    document.body.style.overflow = '';
}
// Close modal on backdrop click
document.addEventListener('click', function(e) {
    if (e.target.hasAttribute('data-modal-backdrop')) {
        closeModal(e.target.id);
    }
});
// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('[data-modal-backdrop]').forEach(m => {
            if (!m.classList.contains('hidden')) closeModal(m.id);
        });
    }
});

// ─── Auto-dismiss flash ────────────────────────────────────────
(function() {
    const flash = document.getElementById('flash-alert');
    if (flash) setTimeout(() => flash.style.opacity === '' && flash.remove(), 5000);
})();
</script>
<style>
/* Admin table delete confirmations: keep both existing actions visible and equal. */
#deleteModal form > .flex.gap-3,
[id$="DeleteModal"] form > .flex.gap-3 {
    display: flex !important;
    align-items: stretch;
    gap: 0.75rem !important;
    width: 100%;
}

#deleteModal form > .flex.gap-3 > button,
[id$="DeleteModal"] form > .flex.gap-3 > button {
    display: inline-flex !important;
    visibility: visible !important;
    opacity: 1 !important;
    flex: 1 1 0% !important;
    width: 0 !important;
    min-width: 0;
    align-items: center;
    justify-content: center;
}

#deleteModal form > .flex.gap-3 > button[type="button"],
[id$="DeleteModal"] form > .flex.gap-3 > button[type="button"] {
    background-color: #64748b !important;
    color: #fff !important;
}

#deleteModal form > .flex.gap-3 > button[type="button"]:hover,
[id$="DeleteModal"] form > .flex.gap-3 > button[type="button"]:hover {
    background-color: #475569 !important;
}
</style>
<?php require __DIR__ . '/admin_missing_modal_closers.php'; ?>
</body>
</html>
