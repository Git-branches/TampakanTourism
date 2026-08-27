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
<?php /* Served from this server rather than a CDN — see the note in the
         officer's foot. The manager confirms deletes and withdrawals through
         the same dialog, so it has to load here for the same reason. */ ?>
<script src="<?= e(asset('js/vendor/sweetalert2.all.min.js')) ?>"></script>
<script src="<?= e(asset('js/notify.js')) ?>"></script>
<?php /* The same shell script the officer's dashboard loads. Two copies of
         one sidebar toggle is two places for it to drift. */ ?>
<script src="<?= e(asset('js/admin.js')) ?>"></script>
</body>
</html>
