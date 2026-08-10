<?php
/**
 * TourSync — server error page.
 *
 * Deliberately does NOT bootstrap the application. If the database or the
 * config is the thing that failed, a page that needs them would fail too and
 * the visitor would see a raw stack trace naming file paths and credentials.
 * Plain HTML always renders.
 */

http_response_code(500);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Service Temporarily Unavailable — Tampakan Tourism</title>
<style>
    body {
        margin: 0;
        min-height: 100vh;
        display: grid;
        place-items: center;
        padding: 2rem 1.5rem;
        background: #F2F6F3;
        color: #16211A;
        font-family: 'Poppins', -apple-system, 'Segoe UI', Roboto, sans-serif;
        text-align: center;
        line-height: 1.65;
    }
    .card {
        max-width: 460px;
        background: #fff;
        border: 1px solid #DCE5DE;
        border-radius: 14px;
        padding: 2.4rem 2rem;
        box-shadow: 0 2px 14px rgba(22, 33, 26, .08);
    }
    .mark {
        width: 66px; height: 66px;
        margin: 0 auto 1.2rem;
        border-radius: 50%;
        background: #FEF6E7;
        color: #B45309;
        display: grid;
        place-items: center;
        font-size: 1.8rem;
        font-weight: 700;
    }
    h1 { font-size: 1.3rem; margin: 0 0 .6rem; }
    p  { font-size: .92rem; color: #43514A; margin: 0 0 .8rem; }
    .home {
        display: inline-block;
        margin-top: .8rem;
        padding: .7rem 1.5rem;
        border-radius: 9px;
        background: linear-gradient(135deg, #2E7D32, #1B5E20);
        color: #fff;
        text-decoration: none;
        font-weight: 600;
        font-size: .92rem;
    }
    .note { font-size: .78rem; color: #6B7A72; margin-top: 1.2rem; }
</style>
</head>
<body>
    <div class="card">
        <div class="mark">!</div>
        <h1>The service is temporarily unavailable</h1>
        <p>
            Something on our side is not responding. This is not a problem with your device or
            your connection.
        </p>
        <p>Please try again in a few minutes.</p>
        <a class="home" href="/">Return to the tourism portal</a>
        <p class="note">
            Municipal Tourism Office &middot; Tampakan, South Cotabato<br>
            If this continues, please report it to the Tourism Office.
        </p>
    </div>
</body>
</html>
