<?php
session_start();

$confirmation = $_SESSION['registration_confirmation'] ?? null;
unset($_SESSION['registration_confirmation']);

if (!is_array($confirmation)) {
    header('Location: index.php');
    exit;
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173d2e">
    <title>Registration complete | My Generation Loves God</title>
    <link rel="icon" type="image/png" href="assets/mglg-favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { color-scheme: light; --forest: #173d2e; --leaf: #b7d67a; --paper: #f8f8f2; --ink: #1d2923; --muted: #68736b; --line: #dce2d9; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; display: grid; place-items: center; margin: 0; padding: 28px 18px; color: var(--ink); background: radial-gradient(ellipse at top right, rgba(183,214,122,.2), transparent 38%), var(--paper); font-family: 'DM Sans', sans-serif; }
        main { width: min(100%, 640px); padding: clamp(26px, 6vw, 52px); border: 1px solid var(--line); border-radius: 8px; background: white; box-shadow: 0 22px 65px rgba(23,61,46,.09); }
        .brand { color: var(--forest); font: 800 13px/1.35 Manrope, sans-serif; letter-spacing: .04em; }
        .check { width: 54px; height: 54px; display: grid; place-items: center; margin-top: 38px; border-radius: 50%; color: var(--forest); background: #edf3e5; font-size: 27px; font-weight: 700; }
        .eyebrow { margin: 25px 0 8px; color: #5e7746; font-size: 11px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; }
        h1 { margin: 0; color: var(--forest); font: 800 clamp(29px, 7vw, 42px)/1.12 Manrope, sans-serif; }
        .intro { margin: 14px 0 0; color: var(--muted); font-size: 15px; line-height: 1.7; }
        .email-status { margin: 22px 0 30px; padding: 13px 15px; border-left: 3px solid var(--forest); background: #edf3e5; color: #33433a; font-size: 13px; line-height: 1.6; }
        .email-status.pending { border-color: #d09546; background: #fff5e7; }
        h2 { margin: 0 0 13px; font: 800 16px Manrope, sans-serif; }
        .socials { display: grid; gap: 9px; }
        .socials a { min-height: 48px; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 0 14px; border: 1px solid var(--line); border-radius: 5px; color: var(--forest); font-size: 14px; font-weight: 700; text-decoration: none; transition: border-color .15s, background .15s; }
        .socials a:hover { border-color: #8aa17d; background: #f7f9f3; }
        .socials a:focus-visible, .back:focus-visible { outline: 3px solid rgba(82,123,84,.25); outline-offset: 3px; }
        .arrow { color: #71866a; font-size: 18px; }
        .back { display: inline-block; margin-top: 26px; color: var(--muted); font-size: 13px; text-underline-offset: 3px; }
    </style>
</head>
<body>
<main>
    <div class="brand">MY GENERATION<br>LOVES GOD</div>
    <div class="check" aria-hidden="true">&#10003;</div>
    <p class="eyebrow">Welcome to the movement</p>
    <h1>You're registered, <?= escape((string) ($confirmation['name'] ?? '')) ?>!</h1>
    <p class="intro">Your registration with My Generation Loves God is complete. We look forward to welcoming you and growing together in faith, purpose, and community.</p>

    <?php if (!empty($confirmation['invitation_sent'])): ?>
        <div class="email-status" role="status">Your event invitation has been sent to <?= escape((string) ($confirmation['email'] ?? 'your email address')) ?>.</div>
    <?php else: ?>
        <div class="email-status pending" role="status">Your registration is saved, but we could not send the event invitation email right now.</div>
    <?php endif; ?>

    <h2>Follow the movement</h2>
    <nav class="socials" aria-label="Official MGLG social media pages">
        <a href="https://www.instagram.com/my_generation_loves_god?stkn=MWwydHQ5aXg4bnN0dw==" target="_blank" rel="noopener noreferrer">Instagram <span class="arrow" aria-hidden="true">&#8599;</span></a>
        <a href="https://www.tiktok.com/@mygenerationlovesgod" target="_blank" rel="noopener noreferrer">TikTok <span class="arrow" aria-hidden="true">&#8599;</span></a>
        <a href="https://www.facebook.com/profile.php?id=61554887035998" target="_blank" rel="noopener noreferrer">Facebook <span class="arrow" aria-hidden="true">&#8599;</span></a>
        <a href="https://youtube.com/@generationlovesgod?si=68FRDVVMNksHJN6j" target="_blank" rel="noopener noreferrer">YouTube <span class="arrow" aria-hidden="true">&#8599;</span></a>
    </nav>
    <a class="back" href="index.php">Return to registration page</a>
</main>
</body>
</html>
