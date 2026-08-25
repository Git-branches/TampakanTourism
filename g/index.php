<?php
declare(strict_types=1);

/**
 * =============================================================================
 *  TourSync — what the QR on a tour guide's ID opens.
 * -----------------------------------------------------------------------------
 *  Reached by scanning the card: /g/{token}
 *
 *  A ROUTE THAT EXISTS ONLY TO BE SHORT.
 *
 *  Everything here is a thin wrapper over guide-verify.php, which holds the
 *  actual page. The only reason this file exists is the length of the address
 *  it replaces:
 *
 *      /guide-verify.php?id=<32 hex>    69 characters   49x49 modules
 *      /g/<32 hex>                      52 characters   41x41 modules
 *
 *  At the 18 mm the card can spare, that is the difference between 0.37 mm and
 *  0.44 mm per module — and a printed code is scanned by whatever phone the
 *  visitor has, in whatever light a trailhead offers, off a card that has been
 *  in a pocket. The destination signage made the same trade for the same
 *  reason; see the rewrite rules in the document root .htaccess.
 *
 *  WORKS WITHOUT mod_rewrite. The rule maps /g/<token> onto this file with ?t=,
 *  but the file is a real path, so /g/index.php?t=<token> reaches it directly on
 *  a host where rewriting is unavailable.
 * =============================================================================
 */

/* Normalised to the parameter guide-verify.php reads, so there is one
   implementation of the page and one place a rule about it can be written. */
$_GET['id'] = (string) ($_GET['t'] ?? $_GET['token'] ?? $_GET['id'] ?? '');

require dirname(__DIR__) . '/guide-verify.php';
