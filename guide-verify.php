<?php
declare(strict_types=1);

/**
 * TourSync — tour guide verification.
 *
 * What the QR code on a guide's ID opens. A visitor at a trailhead points their
 * camera at the card in front of them and this page answers one question: is
 * this person who the card says they are, and is the card still good?
 *
 * ALWAYS READ LIVE
 *
 * Nothing here is cached or precomputed. The office revokes an accreditation at
 * 9am and the card in that person's pocket fails at 9am — which is the entire
 * reason the code carries a URL instead of the guide's details.
 *
 * WHAT THE TOKEN IS
 *
 * 32 random hex characters, not the guide's record number. Somebody who scans
 * one card learns nothing about any other, and the printed accreditation number
 * on the front cannot be turned into a working verification URL.
 *
 * WHAT IS NOT SHOWN
 *
 * The office's reason for suspending or revoking somebody. This page says VALID
 * or it does not; a stranger holding a card is owed an answer, not a person's
 * disciplinary history. The certificate FILES are not linked either — their
 * names are enough to answer "what is this guide trained in", and the documents
 * themselves carry birth dates and are reachable only by signed-in staff.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\RateLimiter;
use App\Repositories\TourGuideRosterRepository as Roster;

$token = strtolower(trim((string) ($_GET['id'] ?? '')));
$guide = Roster::findByToken($token);

/* THE LIMIT COUNTS MISSES, NOT SCANS.
 *
 * Same reasoning as the booking receipt: a tour group of eight all scanning
 * their guide's card from one hotel's WiFi share an address, and rate-limiting
 * them for checking a card is the opposite of what this page is for. What is
 * worth limiting is somebody working through the token space, and that produces
 * nothing but misses. */
$allowed = true;

if ($guide === null) {
    $allowed = RateLimiter::allow('guide-verify-miss:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 30, 900);
    http_response_code(404);
}

$effective   = $guide !== null ? (string) $guide['effective_status'] : '';
$valid       = $effective === 'active';
$photo       = $guide !== null ? uploaded_url((string) ($guide['photo_path'] ?? '')) : null;
$credentials = $guide !== null ? Roster::credentialsFor((int) $guide['id']) : [];
$certificates = $guide !== null ? Roster::certificatesFor((int) $guide['id']) : [];

$officeName = trim((string) (setting('office_name', '') ?? '')) ?: 'Municipal Tourism Office';
$officePhone = trim((string) (setting('office_phone', '') ?? ''));

/* What a person reads, and how alarmed they should be. 'no_id' is deliberately
   not spelled out as a separate state to the public — a card that was never
   issued and a card that has lapsed are both "not valid" to somebody standing
   at a trailhead deciding whether to follow this person up a mountain. */
$verdict = match ($effective) {
    'active'    => ['label' => 'VALID',     'tone' => 'ok',  'note' => 'This guide is accredited by the ' . $officeName . '.'],
    'expired'   => ['label' => 'EXPIRED',   'tone' => 'bad', 'note' => 'This ID has passed its expiry date and is no longer valid.'],
    'suspended' => ['label' => 'SUSPENDED', 'tone' => 'bad', 'note' => 'This accreditation is currently suspended.'],
    'revoked'   => ['label' => 'REVOKED',   'tone' => 'bad', 'note' => 'This accreditation has been withdrawn.'],
    'no_id'     => ['label' => 'NOT VALID', 'tone' => 'bad', 'note' => 'No valid ID has been issued for this person.'],
    default     => ['label' => '',          'tone' => '',    'note' => ''],
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $guide === null ? 'Guide not found' : 'Verify ' . e((string) $guide['guide_code']) ?> — Tampakan Tourism</title>
<link rel="icon" href="<?= e(asset('img/tampakan_logo.png')) ?>" sizes="any">
<?php /* Self-contained. Scanned at a trailhead on one bar of signal, where a
         stylesheet fetched from a CDN is one more thing that does not arrive. */ ?>
<style>
    :root { --ink:#16211A; --muted:#5A6B60; --line:#D8E2DB; --forest:#123A1B; }
    * { box-sizing: border-box; }

    body {
        margin: 0 auto; padding: 1.25rem 1rem 3rem; max-width: 30rem;
        font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
        font-size: 16px; line-height: 1.6; color: var(--ink); background: #F4F6F5;
    }

    .sheet { background:#fff; border:1px solid var(--line); border-radius:12px; overflow:hidden; }

    header { display:flex; align-items:center; gap:.75rem; padding:1rem 1.25rem;
             border-bottom:1px solid var(--line); }
    header img { width:36px; height:36px; object-fit:contain; }
    header p { margin:0; font-size:.68rem; letter-spacing:.12em; text-transform:uppercase;
               color:var(--forest); font-weight:700; line-height:1.3; }

    /* The answer, before anything else and impossible to miss. Somebody is
       standing in front of a stranger deciding whether to trust them. */
    .verdict { padding:1.5rem 1.25rem; text-align:center; }
    .verdict--ok  { background:#E8F3E9; }
    .verdict--bad { background:#FDECEA; }
    .verdict__mark { font-size:2.4rem; line-height:1; }
    .verdict__label { margin:.4rem 0 0; font-size:1.6rem; font-weight:800; letter-spacing:.04em; }
    .verdict--ok  .verdict__label { color:#1B5E20; }
    .verdict--bad .verdict__label { color:#8E1F1B; }
    .verdict__note { margin:.5rem 0 0; font-size:.9rem; color:var(--muted); }

    .who { display:flex; gap:1rem; align-items:center; padding:1.25rem; border-bottom:1px solid var(--line); }
    .who img { width:84px; height:84px; border-radius:8px; object-fit:cover; flex-shrink:0; border:1px solid var(--line); }
    .who h1 { margin:0; font-size:1.25rem; line-height:1.25; color:var(--forest); }
    .who code { font-size:.9rem; color:var(--muted); }
    .who p { margin:.2rem 0 0; font-size:.8rem; letter-spacing:.12em;
             text-transform:uppercase; color:#B8801F; font-weight:700; }

    section { padding:1rem 1.25rem; border-bottom:1px solid var(--line); }
    h2 { font-size:.7rem; letter-spacing:.11em; text-transform:uppercase; color:var(--forest);
         margin:0 0 .5rem; }

    table { border-collapse:collapse; width:100%; font-size:.93rem; }
    th, td { text-align:left; padding:.35rem 0; vertical-align:top; }
    th { width:38%; font-weight:500; color:var(--muted); }

    ul { margin:0; padding-left:1.2rem; font-size:.93rem; }
    li { margin-bottom:.25rem; }

    footer { padding:1rem 1.25rem; font-size:.78rem; color:var(--muted); }

    .missing { text-align:center; padding:2.5rem 1.25rem; }
    .missing h1 { color:var(--forest); font-size:1.2rem; }
</style>
</head>
<body>

<?php if ($guide === null): ?>

    <div class="sheet missing">
        <img src="<?= e(asset('img/tampakan_logo.png')) ?>" alt="" width="44" height="44">
        <h1>This code is not recognised</h1>
        <p style="color:var(--muted); font-size:.93rem">
            <?= $allowed
                ? 'It may not be an official ' . e($officeName) . ' guide ID, or the card may have been replaced.'
                : 'Too many attempts from this connection. Please wait a few minutes and try again.' ?>
        </p>
        <?php if ($officePhone !== ''): ?>
            <p style="font-size:.93rem">
                If someone is presenting this card as official, please call the
                <?= e($officeName) ?> on <strong><?= e($officePhone) ?></strong>.
            </p>
        <?php endif; ?>
    </div>

<?php else: ?>

    <div class="sheet">
        <header>
            <img src="<?= e(asset('img/tampakan_logo.png')) ?>" alt="">
            <p><?= e($officeName) ?><br>Tour Guide Verification</p>
        </header>

        <div class="verdict verdict--<?= e($verdict['tone']) ?>">
            <div class="verdict__mark" aria-hidden="true"><?= $valid ? '&#10003;' : '&#10007;' ?></div>
            <p class="verdict__label"><?= e($verdict['label']) ?></p>
            <p class="verdict__note"><?= e($verdict['note']) ?></p>
        </div>

        <div class="who">
            <?php if ($photo !== null): ?>
                <img src="<?= e($photo) ?>" alt="Photograph of <?= e((string) $guide['full_name']) ?>">
            <?php endif; ?>
            <div>
                <h1><?= e((string) $guide['full_name']) ?></h1>
                <p>Tour Guide</p>
                <code><?= e((string) $guide['guide_code']) ?></code>
            </div>
        </div>

        <section>
            <h2>Accreditation</h2>
            <table>
                <tr>
                    <th>Tour Guide ID</th>
                    <td><?= e((string) $guide['guide_code']) ?></td>
                </tr>
                <tr>
                    <th>Valid until</th>
                    <td><?= $guide['valid_until']
                            ? e(format_date((string) $guide['valid_until'], 'F j, Y'))
                            : 'Not issued' ?></td>
                </tr>
                <?php if ($guide['mobile_number']): ?>
                    <tr><th>Contact</th><td><?= e((string) $guide['mobile_number']) ?></td></tr>
                <?php endif; ?>
                <?php if ($guide['address']): ?>
                    <tr><th>Address</th><td><?= e((string) $guide['address']) ?></td></tr>
                <?php endif; ?>
                <tr><th>Issued by</th><td><?= e($officeName) ?></td></tr>
            </table>
        </section>

        <?php if ($credentials !== []): ?>
            <section>
                <h2>Credentials</h2>
                <ul>
                    <?php foreach ($credentials as $c): ?>
                        <li>
                            <?= e((string) $c['label']) ?>
                            <?php if ($c['issuer']): ?>
                                <span style="color:var(--muted)">&mdash; <?= e((string) $c['issuer']) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if ($certificates !== []): ?>
            <section>
                <h2>Certificates on file</h2>
                <?php /* Names only. The documents themselves carry birth dates and
                         are reachable by signed-in office staff alone. */ ?>
                <ul>
                    <?php foreach ($certificates as $c): ?>
                        <li>
                            <?= e((string) $c['title']) ?>
                            <?php if ($c['issuer']): ?>
                                <span style="color:var(--muted)">&mdash; <?= e((string) $c['issuer']) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <footer>
            Checked <?= e(date('F j, Y \a\t g:i A')) ?> against the live record of the
            <?= e($officeName) ?>, Tampakan, South Cotabato.
            <?php if ($officePhone !== ''): ?>
                <br>Questions about this guide: <strong><?= e($officePhone) ?></strong>.
            <?php endif; ?>
        </footer>
    </div>

<?php endif; ?>

</body>
</html>
