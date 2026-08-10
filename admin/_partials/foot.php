<?php
if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}
?>
        </main>

        <footer class="admin-foot">
            <p>
                <strong>TourSync</strong> &middot; Municipal Tourism Office, Tampakan, South Cotabato
                &nbsp;&middot;&nbsp; &copy; <?= date('Y') ?>
            </p>
            <p class="admin-foot__build">Phase 7 — Hardening &amp; Settings</p>
        </footer>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* Sidebar toggle for tablet and phone widths. */
(function () {
    const sidebar = document.getElementById('sidebar');
    const scrim   = document.getElementById('sidebarScrim');
    const toggle  = document.getElementById('sidebarToggle');
    if (!sidebar || !toggle) return;

    const open  = () => { sidebar.classList.add('is-open'); scrim.hidden = false; };
    const close = () => { sidebar.classList.remove('is-open'); scrim.hidden = true; };

    toggle.addEventListener('click', () => sidebar.classList.contains('is-open') ? close() : open());
    scrim.addEventListener('click', close);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
})();

/* Modules that do not exist yet explain themselves instead of doing nothing. */
document.querySelectorAll('.sidebar__link.is-pending').forEach((link) => {
    link.addEventListener('click', (e) => {
        e.preventDefault();
        const phase = link.querySelector('.sidebar__phase');
        alert('This module is scheduled for ' + (phase ? 'Phase ' + phase.textContent.replace('P', '') : 'a later phase') + ' and has not been built yet.');
    });
});
</script>
<?php if (!empty($pageScripts)) echo $pageScripts; ?>
</body>
</html>
