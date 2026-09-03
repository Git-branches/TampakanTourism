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

<?php /* The same dialog the officer's shell uses, and for the same reason: one
         implementation of "open this page in a modal" rather than a manager
         copy that drifts. The compliance evidence form is fetched into it. */ ?>
<?php require __DIR__ . '/../../admin/_partials/page-modal.php'; ?>

<?php /* Loaded after #pageModal, so the evidence viewer is the later dialog and
         the top layer stacks it above the one the photograph was clicked in. */ ?>
<?php require __DIR__ . '/lightbox.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php /* Served from this server rather than a CDN — see the note in the
         officer's foot. The manager confirms deletes and withdrawals through
         the same dialog, so it has to load here for the same reason. */ ?>
<script src="<?= e(asset('js/vendor/sweetalert2.all.min.js')) ?>"></script>
<script src="<?= e(asset('js/notify.js')) ?>"></script>
<?php /* THE MANAGER'S OWN STREAM, HANDED TO THE SHARED SCRIPT.
         admin.js reads window.TourSyncBell and knows nothing else about who is
         signed in — so pointing it at the manager endpoint is the entire
         difference between the two bells. The officer's foot sets the same
         object to /api/admin/notifications.php. */ ?>
<script>
    window.TourSyncBell = {
        url:   <?= json_encode(base_url('/api/manager/notifications.php')) ?>,
        token: <?= json_encode(App\Core\Csrf::token()) ?>,
        every: 20000
    };
</script>
<?php /* The same shell script the officer's dashboard loads. Two copies of
         one sidebar toggle is two places for it to drift. */ ?>
<script src="<?= e(asset('js/admin.js')) ?>"></script>
</body>
</html>
