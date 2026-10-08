<?php
use PHPMailer\PHPMailer\PHPMailer;

session_start();

require __DIR__ . '/config.php';

$showEntrySplash = empty($_SESSION['site_splash_seen']);
$_SESSION['site_splash_seen'] = true;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$fields = ['name', 'institution', 'phone', 'email', 'participant_type', 'course', 'student_year', 'attachment_seeking', 'employment_status', 'area_of_specification', 'area_of_residence', 'occupation', 'hometown'];
$values = array_fill_keys($fields, '');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($fields as $field) {
        $values[$field] = trim((string) ($_POST[$field] ?? ''));
    }

    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    }

    if (!empty($_POST['website'])) {
        $errors[] = 'Unable to process this registration.';
    }

    foreach (['name', 'institution', 'phone', 'email', 'participant_type', 'area_of_residence', 'occupation', 'hometown'] as $required) {
        if ($values[$required] === '') {
            $errors[] = 'Please complete all required fields.';
            break;
        }
    }

    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }

    if (($_POST['consent'] ?? '') !== 'yes') {
        $errors[] = 'Please agree to the event communication consent.';
    }

    if (!in_array($values['participant_type'], ['student', 'non_student'], true)) {
        $errors[] = 'Choose whether you are a student.';
    } elseif ($values['participant_type'] === 'student' && $values['course'] === '') {
        $errors[] = 'Enter your course of study.';
    } elseif ($values['participant_type'] === 'student' && !in_array($values['student_year'], ['Year 1', 'Year 2', 'Year 3', 'Year 4', 'Year 5', 'Year 6', 'Other'], true)) {
        $errors[] = 'Choose your year of study.';
    } elseif ($values['participant_type'] === 'student' && !in_array($values['attachment_seeking'], ['yes', 'no'], true)) {
        $errors[] = 'Tell us whether you are seeking attachment.';
    } elseif ($values['participant_type'] === 'non_student' && $values['area_of_specification'] === '') {
        $errors[] = 'Enter your area of specification.';
    } elseif ($values['participant_type'] === 'non_student' && !in_array($values['employment_status'], ['seeking_job', 'employed', 'not_seeking'], true)) {
        $errors[] = 'Choose your current employment status.';
    }

    if (!$errors) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';port=' . (defined('DB_PORT') ? DB_PORT : 3306) . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASSWORD,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
            );

            $statement = $pdo->prepare(
                'INSERT INTO event_registrations
                          (name, institution, phone, email, participant_type, course, student_year, attachment_seeking, employment_status, area_of_specification, area_of_residence, occupation, hometown)
                 VALUES
                          (:name, :institution, :phone, :email, :participant_type, :course, :student_year, :attachment_seeking, :employment_status, :area_of_specification, :area_of_residence, :occupation, :hometown)'
            );
            $statement->execute([
                'name' => $values['name'],
                'institution' => $values['institution'],
                'phone' => $values['phone'],
                'email' => $values['email'],
                'participant_type' => $values['participant_type'],
                'course' => $values['course'] !== '' ? $values['course'] : null,
                'student_year' => $values['participant_type'] === 'student' ? $values['student_year'] : null,
                'attachment_seeking' => $values['participant_type'] === 'student' ? $values['attachment_seeking'] : null,
                'employment_status' => $values['participant_type'] === 'non_student' ? $values['employment_status'] : null,
                'area_of_specification' => $values['participant_type'] === 'non_student' ? $values['area_of_specification'] : null,
                'area_of_residence' => $values['area_of_residence'],
                'occupation' => $values['occupation'],
                'hometown' => $values['hometown'],
            ]);

            $invitationSent = false;
            $autoload = __DIR__ . '/vendor/autoload.php';
            if (is_file($autoload)) {
                try {
                    require_once $autoload;
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host = SMTP_HOST;
                    $mail->SMTPAuth = true;
                    $mail->Username = SMTP_USER;
                    $mail->Password = SMTP_PASSWORD;
                    $mail->Port = SMTP_PORT;
                    if (SMTP_ENCRYPTION !== '') {
                        $mail->SMTPSecure = SMTP_ENCRYPTION;
                    }
                    $mail->CharSet = 'UTF-8';
                    $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
                    $mail->addAddress($values['email'], $values['name']);
                    $mail->isHTML(true);
                    $mail->Subject = 'You are invited: My Generation Loves God';

                    $safeName = htmlspecialchars($values['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $mail->Body = '<div style="font-family:Arial,sans-serif;color:#18261f;line-height:1.6;max-width:560px">'
                        . '<p>Hello ' . $safeName . ',</p>'
                        . '<p>Thank you for registering with <strong>My Generation Loves God (MGLG)</strong>. We would love to welcome you into the movement and see you at our upcoming gathering.</p>'
                        . '<p><strong>Sunday, 18 October 2026<br>From 1:00 PM<br>Thika Town</strong></p>'
                        . '<p>Come ready to connect, worship, and grow with a generation that loves God. We look forward to seeing you.</p>'
                        . '<p>With love,<br><strong>My Generation Loves God</strong></p></div>';
                    $mail->AltBody = "Hello {$values['name']},\n\nThank you for registering with My Generation Loves God (MGLG). We would love to welcome you into the movement and see you at our upcoming gathering.\n\nSunday, 18 October 2026\nFrom 1:00 PM\nThika Town\n\nCome ready to connect, worship, and grow with a generation that loves God. We look forward to seeing you.\n\nWith love,\nMy Generation Loves God";
                    $mail->send();
                    $invitationSent = true;
                } catch (Throwable $mailError) {
                    $invitationSent = false;
                    error_log('MGLG invitation email failed: ' . $mailError->getMessage());
                }
            } else {
                error_log('MGLG invitation email skipped: vendor/autoload.php is missing.');
            }

            $_SESSION['registration_confirmation'] = [
                'name' => $values['name'],
                'email' => $values['email'],
                'invitation_sent' => $invitationSent,
            ];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header('Location: success.php');
            exit;
        } catch (PDOException $databaseError) {
            error_log('MGLG registration database error: ' . $databaseError->getMessage());
            if ($databaseError->getCode() === '23000') {
                $errors[] = 'This email address is already registered.';
            } else {
                $errors[] = 'We could not save your registration. Please try again later.';
            }
        }
    }
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
    <meta name="theme-color" content="#cfac21">
    <title>Register | My Generation Loves God</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            color-scheme: light;
            --forest: #173d2e;
            --forest-deep: #102b21;
            --leaf: #b7d67a;
            --paper: #f8f8f2;
            --ink: #1d2923;
            --muted: #68736b;
            --line: #dce2d9;
            --coral: #e88064;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--paper); color: var(--ink); font-family: 'DM Sans', sans-serif; }
        button, input { font: inherit; }
        .layout { min-height: 100vh; min-height: 100svh; display: grid; grid-template-columns: minmax(0, 0.85fr) minmax(0, 1.15fr); }
        .story { min-height: 100vh; position: sticky; top: 0; height: 100vh; padding: 34px clamp(28px, 5vw, 72px) 40px; display: flex; flex-direction: column; justify-content: space-between; overflow: hidden; isolation: isolate; color: white; background: var(--forest); }
        .story::before { content: ''; position: absolute; inset: 0; z-index: -2; background: linear-gradient(180deg, rgba(11,34,25,.36), rgba(11,34,25,.84)), url('assets/registration-background.jpeg') center/cover; }
        .story::after { content: ''; position: absolute; z-index: -1; width: 320px; height: 320px; right: -180px; bottom: 13%; border: 1px solid rgba(255,255,255,.2); border-radius: 50%; box-shadow: 0 0 0 34px rgba(255,255,255,.035), 0 0 0 68px rgba(255,255,255,.025); }
        .brand { display: flex; align-items: center; gap: 12px; font-family: Manrope, sans-serif; font-weight: 800; letter-spacing: 0; }
        .brand-mark { width: 38px; height: 38px; display: grid; place-items: center; border: 1px solid rgba(255,255,255,.6); border-radius: 50%; color: var(--leaf); font-size: 17px; }
        .brand-name { max-width: 180px; font-size: 13px; line-height: 1.25; }
        .story-copy { max-width: 570px; padding: 54px 0 66px; animation: arrive .65s ease-out both; }
        .eyebrow { display: inline-flex; align-items: center; gap: 10px; color: var(--leaf); text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 1.6px; }
        .eyebrow::before { content: ''; width: 24px; height: 1px; background: currentColor; }
        h1 { margin: 20px 0 18px; max-width: 540px; font-family: Manrope, sans-serif; font-weight: 800; font-size: clamp(42px, 5vw, 68px); line-height: 1.03; letter-spacing: 0; }
        h1 span { color: var(--leaf); }
        .story-copy p { max-width: 400px; margin: 0; color: rgba(255,255,255,.82); font-size: 16px; line-height: 1.7; }
        .event-meta { display: flex; flex-wrap: wrap; gap: 10px 26px; margin-top: 34px; }
        .meta-item { display: grid; gap: 5px; min-width: 130px; }
        .meta-label { color: rgba(255,255,255,.64); font-size: 10px; text-transform: uppercase; letter-spacing: 1.4px; }
        .meta-value { font-family: Manrope, sans-serif; font-size: 15px; font-weight: 700; }
        .story-footer { color: rgba(255,255,255,.65); font-size: 12px; }
        .form-side { min-width: 0; padding: 28px clamp(26px, 6vw, 88px) 48px; background: radial-gradient(ellipse at top right, rgba(183,214,122,.15), transparent 36%), var(--paper); }
        .mobile-brand { display: none; }
        .form-wrap { width: min(100%, 720px); margin: 0 auto; animation: arrive .55s .08s ease-out both; }
        .form-heading { padding: 10px 0 22px; border-bottom: 1px solid var(--line); }
        .form-logo { display: block; width: min(250px, 72vw); height: auto; margin: 0 0 17px; object-fit: contain; }
        .form-heading .eyebrow { color: #5e7746; }
        h2 { margin: 11px 0 6px; font-family: Manrope, sans-serif; font-size: 29px; line-height: 1.2; letter-spacing: 0; }
        .form-heading p { margin: 0; color: var(--muted); font-size: 14px; line-height: 1.55; }
        .notice { margin: 18px 0 0; padding: 12px 14px; border-left: 3px solid var(--forest); background: #edf3e5; font-size: 13px; line-height: 1.5; }
        .notice.error { border-color: var(--coral); background: #fff0e9; }
        form { padding-top: 23px; }
        .section-title { display: flex; align-items: center; gap: 11px; margin: 0 0 16px; font-family: Manrope, sans-serif; font-size: 14px; font-weight: 800; }
        .section-title span { color: #82907f; font-size: 11px; font-weight: 600; }
        .fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: 18px; row-gap: 15px; }
        .field { display: grid; gap: 7px; min-width: 0; }
        .field.full { grid-column: 1 / -1; }
        label, legend { color: #39453d; font-size: 12px; font-weight: 700; }
        .required { color: #c15b43; }
        input[type=text], input[type=email], input[type=tel], select { width: 100%; min-height: 46px; padding: 0 13px; color: var(--ink); border: 1px solid #cfd8ce; border-radius: 4px; outline: none; background: rgba(255,255,255,.82); transition: border-color .16s, box-shadow .16s; }
        input::placeholder { color: #9aa29a; }
        input:focus { border-color: #527b54; box-shadow: 0 0 0 3px rgba(82,123,84,.15); }
        fieldset { min-width: 0; margin: 20px 0 18px; padding: 0; border: 0; }
        fieldset legend { margin-bottom: 10px; }
        .choice-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .choice { position: relative; }
        .choice input { position: absolute; opacity: 0; inset: 0; }
        .choice label { display: flex; align-items: center; gap: 10px; min-height: 46px; padding: 10px 13px; border: 1px solid #cfd8ce; border-radius: 4px; background: rgba(255,255,255,.7); cursor: pointer; }
        .choice label::before { content: ''; width: 15px; height: 15px; flex: 0 0 15px; border: 1px solid #a4afa4; border-radius: 50%; box-shadow: inset 0 0 0 3px white; }
        .choice input:checked + label { border-color: var(--forest); background: #edf3e5; }
        .choice input:checked + label::before { background: var(--forest); border-color: var(--forest); }
        .choice input:focus-visible + label { outline: 3px solid rgba(82,123,84,.22); }
        .conditional[hidden] { display: none; }
        .consent { display: flex; align-items: flex-start; gap: 10px; margin: 21px 0 17px; color: var(--muted); font-size: 12px; line-height: 1.55; }
        .consent input { width: 16px; height: 16px; margin: 1px 0 0; accent-color: var(--forest); flex: 0 0 auto; }
        .submit { width: 100%; min-height: 50px; display: flex; align-items: center; justify-content: space-between; padding: 0 18px; color: white; border: 0; border-radius: 4px; background: var(--forest); font-family: Manrope, sans-serif; font-weight: 800; cursor: pointer; transition: background .15s, transform .15s; }
        .submit:hover { background: #24563f; transform: translateY(-1px); }
        .submit:focus-visible { outline: 3px solid #91ae69; outline-offset: 3px; }
        .submit span:last-child { color: var(--leaf); font-size: 20px; }
        .privacy { margin: 12px 0 0; text-align: center; color: #849087; font-size: 11px; line-height: 1.5; }
        .entry-splash { position: fixed; inset: 0; z-index: 1000; display: grid; place-items: center; padding: 24px; background: #07130e; }
        .entry-splash[hidden] { display: none; }
        .entry-splash video { width: min(100%, 1100px); max-height: calc(100vh - 48px); object-fit: contain; }
        .splash-skip { position: absolute; top: 20px; right: 20px; padding: 10px 16px; color: white; border: 1px solid rgba(255,255,255,.65); border-radius: 4px; background: rgba(0,0,0,.5); font-weight: 700; cursor: pointer; }
        .honeypot { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
        @keyframes arrive { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        @media (max-width: 1040px) {
            .layout { grid-template-columns: 1fr; }
            .story { position: relative; top: auto; height: auto; min-height: 360px; padding: 22px clamp(22px, 5vw, 48px) 27px; }
            .story-copy { padding: 55px 0 28px; }
            h1 { max-width: 600px; font-size: 47px; }
            .story-footer { display: none; }
            .form-side { padding: 24px clamp(22px, 6vw, 72px) 42px; }
            .form-heading { padding-top: 20px; }
        }
        @media (max-width: 520px) {
            .story { min-height: 365px; padding: 19px 21px 24px; }
            .story-copy { padding-top: 42px; }
            h1 { margin-top: 15px; font-size: 40px; }
            .story-copy p { font-size: 14px; }
            .event-meta { gap: 18px; margin-top: 25px; }
            .meta-value { font-size: 14px; }
            .form-side { padding: 8px 20px 32px; }
            .form-heading { padding-top: 14px; }
            .form-logo { width: min(215px, 72vw); margin-bottom: 14px; }
            h2 { font-size: 25px; }
            .fields { grid-template-columns: 1fr; row-gap: 13px; }
            .field.full { grid-column: auto; }
            .choice-row { gap: 8px; }
            .choice label { padding-inline: 10px; font-size: 11px; }
        }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { scroll-behavior: auto !important; animation-duration: .01ms !important; transition-duration: .01ms !important; } }
    </style>
</head>
<body>
<?php if ($showEntrySplash): ?>
<div class="entry-splash" id="entry-splash" role="dialog" aria-label="Welcome to My Generation Loves God" aria-modal="true">
    <video id="entry-video" autoplay muted playsinline preload="auto" aria-label="My Generation Loves God welcome video"><source src="assets/WhatsApp%20Video%202026-07-17%20at%2012.31.12%20PM.mp4" type="video/mp4"></video>
    <button class="splash-skip" type="button" id="splash-skip" autofocus>Skip video</button>
</div>
<script>
(() => {
    const splash = document.getElementById('entry-splash');
    const video = document.getElementById('entry-video');
    const close = () => { splash.hidden = true; video.pause(); };
    document.getElementById('splash-skip').addEventListener('click', close);
    video.addEventListener('ended', close);
    video.addEventListener('error', close);
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') close(); }, { once: true });
    video.play().catch(() => {});
})();
</script>
<?php endif; ?>
<div class="layout">
    <aside class="story" aria-label="MGLG event details">
        <div class="brand"><span class="brand-mark" aria-hidden="true">M</span><span class="brand-name">MY GENERATION<br>LOVES GOD</span></div>
        <div class="story-copy">
            <span class="eyebrow">A generation gathered</span>
            <p>Come as you are. Meet a community of young people growing in faith, purpose, and love for God.</p>
            <div class="event-meta">
                <div class="meta-item"><span class="meta-label">When</span><span class="meta-value">18 October 2026</span></div>
                <div class="meta-item"><span class="meta-label">Time</span><span class="meta-value">From 1:00 PM</span></div>
                <div class="meta-item"><span class="meta-label">Where</span><span class="meta-value">Thika Town</span></div>
            </div>
        </div>
        <div class="story-footer">Faith. Community. Purpose.</div>
    </aside>

    <main class="form-side" id="registration">
        <div class="form-wrap">
            <header class="form-heading">
                <img class="form-logo" src="assets/mglg-favicon.png" alt="My Generation Loves God" width="669" height="376">
                <span class="eyebrow">Join us in Thika</span>
                <h2>Register for the gathering</h2>
                <p>Share a few details so we can welcome you and send your event invitation.</p>
            </header>

            <?php if ($errors): ?>
                <div class="notice error" role="alert"><?= escape(implode(' ', array_unique($errors))) ?></div>
            <?php endif; ?>

            <form method="post" action="#registration">
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                <div class="honeypot" aria-hidden="true"><label for="website">Leave this field empty</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
                <h3 class="section-title">Your details <span>All fields required</span></h3>
                <div class="fields">
                    <div class="field">
                        <label for="name">Full name <span class="required">*</span></label>
                        <input id="name" name="name" type="text" autocomplete="name" maxlength="160" placeholder="Enter Name" value="<?= escape($values['name']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="institution">Institution / organization / Church<span class="required">*</span></label>
                        <input id="institution" name="institution" type="text" maxlength="180" placeholder="Where you study or work" value="<?= escape($values['institution']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="phone">Phone number <span class="required">*</span></label>
                        <input id="phone" name="phone" type="tel" autocomplete="tel" maxlength="40" placeholder="Enter phone number 0712 345 678" value="<?= escape($values['phone']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="email">Email address <span class="required">*</span></label>
                        <input id="email" name="email" type="email" autocomplete="email" maxlength="254" placeholder="Enter your Email @gmail.com" value="<?= escape($values['email']) ?>" required>
                    </div>

                    <div class="field">
                        <label for="area_of_residence">Area of residence <span class="required">*</span></label>
                        <input id="area_of_residence" name="area_of_residence" type="text" maxlength="160" placeholder="Estate, town or area" value="<?= escape($values['area_of_residence']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="hometown">Home town <span class="required">*</span></label>
                        <input id="hometown" name="hometown" type="text" maxlength="160" placeholder="Where is home for you?" value="<?= escape($values['hometown']) ?>" required>
                    </div>
                    <div class="field full">
                        <label for="occupation">What do you do? <span class="required">*</span></label>
                        <input id="occupation" name="occupation" type="text" maxlength="180" placeholder="e.g. Student, designer, business owner" value="<?= escape($values['occupation']) ?>" required>
                    </div>
                </div>

                <fieldset>
                    <legend>Are you currently a student? <span class="required">*</span></legend>
                    <div class="choice-row">
                        <div class="choice"><input id="student" name="participant_type" type="radio" value="student" <?= $values['participant_type'] === 'student' ? 'checked' : '' ?> required><label for="student">Yes, I am a student</label></div>
                        <div class="choice"><input id="non_student" name="participant_type" type="radio" value="non_student" <?= $values['participant_type'] === 'non_student' ? 'checked' : '' ?> required><label for="non_student">No, I am not</label></div>
                    </div>
                </fieldset>
                <div class="fields">
                    <div class="field full conditional" id="course-field" <?= $values['participant_type'] === '' ? 'hidden' : '' ?>>
                        <label id="course-label" for="course">Course of study <span class="required">*</span></label>
                        <input id="course" name="course" type="text" maxlength="180" placeholder="What are you studying?" value="<?= escape($values['course']) ?>" <?= $values['participant_type'] === 'student' ? 'required' : '' ?>>
                    </div>
                    <div class="field conditional" id="year-field" <?= $values['participant_type'] !== 'student' ? 'hidden' : '' ?>>
                        <label for="student_year">Year of study <span class="required">*</span></label>
                        <select id="student_year" name="student_year" <?= $values['participant_type'] === 'student' ? 'required' : '' ?>>
                            <option value="">Select year</option>
                            <?php foreach (['Year 1', 'Year 2', 'Year 3', 'Year 4', 'Year 5', 'Year 6', 'Other'] as $year): ?>
                                <option value="<?= escape($year) ?>" <?= $values['student_year'] === $year ? 'selected' : '' ?>><?= escape($year) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <fieldset class="field full conditional" id="attachment-field" <?= $values['participant_type'] !== 'student' ? 'hidden' : '' ?>>
                        <legend>Are you seeking attachment? <span class="required">*</span></legend>
                        <div class="choice-row">
                            <div class="choice"><input id="attachment_yes" name="attachment_seeking" type="radio" value="yes" <?= $values['attachment_seeking'] === 'yes' ? 'checked' : '' ?> <?= $values['participant_type'] === 'student' ? 'required' : '' ?>><label for="attachment_yes">Yes</label></div>
                            <div class="choice"><input id="attachment_no" name="attachment_seeking" type="radio" value="no" <?= $values['attachment_seeking'] === 'no' ? 'checked' : '' ?> <?= $values['participant_type'] === 'student' ? 'required' : '' ?>><label for="attachment_no">No</label></div>
                        </div>
                    </fieldset>
                    <div class="field full conditional" id="specification-field" <?= $values['participant_type'] !== 'non_student' ? 'hidden' : '' ?>>
                        <label for="area_of_specification">Area of specification <span class="required">*</span></label>
                        <input id="area_of_specification" name="area_of_specification" type="text" maxlength="180" placeholder="Your profession or area of expertise" value="<?= escape($values['area_of_specification']) ?>" <?= $values['participant_type'] === 'non_student' ? 'required' : '' ?>>
                    </div>
                    <div class="field full conditional" id="employment-field" <?= $values['participant_type'] !== 'non_student' ? 'hidden' : '' ?>>
                        <label for="employment_status">Employment status <span class="required">*</span></label>
                        <select id="employment_status" name="employment_status" <?= $values['participant_type'] === 'non_student' ? 'required' : '' ?>>
                            <option value="">Select status</option>
                            <option value="seeking_job" <?= $values['employment_status'] === 'seeking_job' ? 'selected' : '' ?>>Seeking a job</option>
                            <option value="employed" <?= $values['employment_status'] === 'employed' ? 'selected' : '' ?>>Currently employed</option>
                            <option value="not_seeking" <?= $values['employment_status'] === 'not_seeking' ? 'selected' : '' ?>>Not seeking work</option>
                        </select>
                    </div>
                </div>

                <label class="consent"><input type="checkbox" name="consent" value="yes" required><span>I agree that MGLG may use these details to manage my event registration and send me event-related communication.</span></label>
                <button class="submit" type="submit"><span>Complete registration</span><span aria-hidden="true">&#8594;</span></button>
                <p class="privacy">Your details will only be used for MGLG community and event communication.</p>
            </form>
        </div>
    </main>
</div>
<script>
    const studentChoice = document.getElementById('student');
    const nonStudentChoice = document.getElementById('non_student');
    const courseField = document.getElementById('course-field');
    const yearField = document.getElementById('year-field');
    const attachmentField = document.getElementById('attachment-field');
    const specificationField = document.getElementById('specification-field');
    const employmentField = document.getElementById('employment-field');
    const courseInput = document.getElementById('course');
    const courseLabel = document.getElementById('course-label');
    const studentYear = document.getElementById('student_year');
    const attachmentOptions = document.querySelectorAll('input[name="attachment_seeking"]');
    const employmentStatus = document.getElementById('employment_status');
    const specificationInput = document.getElementById('area_of_specification');

    function updateParticipantFields() {
        const isStudent = studentChoice.checked;
        const isNonStudent = nonStudentChoice.checked;
        courseField.hidden = !isStudent && !isNonStudent;
        yearField.hidden = !isStudent;
        attachmentField.hidden = !isStudent;
        specificationField.hidden = !isNonStudent;
        employmentField.hidden = !isNonStudent;
        courseInput.required = isStudent;
        courseLabel.innerHTML = isStudent ? 'Course of study <span class="required">*</span>' : 'Course previously studied (optional)';
        courseInput.placeholder = isStudent ? 'What are you studying?' : 'Course you studied, if applicable';
        studentYear.required = isStudent;
        attachmentOptions.forEach((option) => { option.required = isStudent; });
        specificationInput.required = isNonStudent;
        employmentStatus.required = isNonStudent;
    }

    studentChoice.addEventListener('change', updateParticipantFields);
    nonStudentChoice.addEventListener('change', updateParticipantFields);
    updateParticipantFields();
</script>
</body>
</html>
