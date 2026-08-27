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
<?php
/* SERVED FROM THIS SERVER, NOT A CDN.
 *
 * This one is deliberately not jsdelivr like the line above it. A confirmation
 * box that fails to load does not fail loudly — the click just goes through,
 * and the thing being confirmed is a delete, a void, or a revoked account. On a
 * municipal connection that drops, a CDN miss would mean records disappearing
 * with nobody asked. admin.js falls back on its own dialog if this file is
 * missing, but the file should not be missing. */
?>
<script src="<?= e(asset('js/vendor/sweetalert2.all.min.js')) ?>"></script>
<script src="<?= e(asset('js/notify.js')) ?>"></script>
<?php /* Shell behaviour lives in assets/js/admin.js and is shared with the
         manager shell. It used to be inline here and inline again in the
         manager foot, which is two copies of the same sidebar toggle waiting
         to disagree with each other. */ ?>
<?php /* The bell's endpoint and this session's token, handed to the script
         rather than hardcoded — the same reason the chat widget is given its
         URLs. Changing a read state is a POST, and a POST here carries the
         token like every other one in the system. */ ?>
<script>
    window.TourSyncBell = {
        url:   <?= json_encode(base_url('/api/admin/notifications.php')) ?>,
        token: <?= json_encode(App\Core\Csrf::token()) ?>,
        every: 20000
    };
</script>
<script src="<?= e(asset('js/admin.js')) ?>"></script>
<?php if (!empty($pageScripts)) echo $pageScripts; ?>
</body>
</html>
