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
            <p class="admin-foot__build">Destination Manager</p>
        </footer>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* Sidebar toggle for tablet and phone widths. Managers work from a phone at the
   destination more often than from a desk, so this is the common case here. */
(function () {
    const sidebar = document.getElementById('sidebar');
    const scrim   = document.getElementById('sidebarScrim');
    const toggle  = document.getElementById('sidebarToggle');
    if (!sidebar || !toggle || !scrim) return;

    const open  = () => { sidebar.classList.add('is-open'); scrim.hidden = false; };
    const close = () => { sidebar.classList.remove('is-open'); scrim.hidden = true; };

    toggle.addEventListener('click', () => sidebar.classList.contains('is-open') ? close() : open());
    scrim.addEventListener('click', close);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
})();
</script>
</body>
</html>
