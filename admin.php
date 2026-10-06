<?php
use PHPMailer\PHPMailer\PHPMailer;

require __DIR__ . '/config.php';

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Strict',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

function adminEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function openDatabase(): PDO
{
    return new PDO(
        'mysql:host=' . DB_HOST . ';port=' . (defined('DB_PORT') ? DB_PORT : 3306) . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
}

function validSegment(string $segment): string
{
    $segments = ['all', 'students', 'non_students', 'seeking_attachment', 'seeking_job', 'employed', 'not_seeking'];
    return in_array($segment, $segments, true) ? $segment : 'all';
}

function findMembers(PDO $pdo, string $segment, string $search): array
{
    $conditions = [];
    $parameters = [];
    $segmentConditions = [
        'students' => "participant_type = 'student'",
        'non_students' => "participant_type = 'non_student'",
        'seeking_attachment' => "participant_type = 'student' AND attachment_seeking = 'yes'",
        'seeking_job' => "participant_type = 'non_student' AND employment_status = 'seeking_job'",
        'employed' => "participant_type = 'non_student' AND employment_status = 'employed'",
        'not_seeking' => "participant_type = 'non_student' AND employment_status = 'not_seeking'",
    ];

    if (isset($segmentConditions[$segment])) {
        $conditions[] = $segmentConditions[$segment];
    }

    if ($search !== '') {
        $searchFields = ['name', 'email', 'phone', 'institution', 'course', 'student_year', 'area_of_specification', 'area_of_residence', 'occupation', 'hometown'];
        $searchConditions = [];
        foreach ($searchFields as $field) {
            $parameter = 'search_' . $field;
            $searchConditions[] = $field . ' LIKE :' . $parameter;
            $parameters[$parameter] = '%' . $search . '%';
        }
        $conditions[] = '(' . implode(' OR ', $searchConditions) . ')';
    }

    $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
    $statement = $pdo->prepare(
        'SELECT id, name, institution, phone, email, participant_type, course, student_year,
                attachment_seeking, employment_status, area_of_specification,
                area_of_residence, occupation, hometown, created_at
         FROM event_registrations' . $where . ' ORDER BY created_at DESC, id DESC'
    );
    $statement->execute($parameters);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function segmentLabel(string $segment): string
{
    return [
        'all' => 'All members',
        'students' => 'Students',
        'non_students' => 'Non-students',
        'seeking_attachment' => 'Seeking attachment',
        'seeking_job' => 'Seeking a job',
        'employed' => 'Employed',
        'not_seeking' => 'Not seeking work',
    ][$segment] ?? 'All members';
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$loginError = '';
$pageError = '';
$message = '';
$messageSubject = '';
$isAuthenticated = !empty($_SESSION['mglg_admin_authenticated']);
$action = (string) ($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
        if ($isAuthenticated) {
            $pageError = 'Your session expired. Refresh the page and try again.';
        } else {
            $loginError = 'Your session expired. Refresh the page and try again.';
        }
    } elseif ($action === 'login' && !$isAuthenticated) {
        $lockedUntil = (int) ($_SESSION['admin_locked_until'] ?? 0);
        if ($lockedUntil > time()) {
            $loginError = 'Too many attempts. Please wait a few minutes and try again.';
        } elseif (MGLG_ADMIN_USERNAME === '' || MGLG_ADMIN_PASSWORD_HASH === '') {
            $loginError = 'Admin access is not configured. Set the admin credentials in the server environment.';
        } else {
            $username = (string) ($_POST['username'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            if (hash_equals(MGLG_ADMIN_USERNAME, $username) && password_verify($password, MGLG_ADMIN_PASSWORD_HASH)) {
                session_regenerate_id(true);
                $_SESSION['mglg_admin_authenticated'] = true;
                $_SESSION['admin_login_splash'] = true;
                $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
                $_SESSION['admin_login_failures'] = 0;
                header('Location: admin.php');
                exit;
            }

            $_SESSION['admin_login_failures'] = (int) ($_SESSION['admin_login_failures'] ?? 0) + 1;
            if ($_SESSION['admin_login_failures'] >= 5) {
                $_SESSION['admin_locked_until'] = time() + 300;
                $_SESSION['admin_login_failures'] = 0;
            }
            $loginError = 'The username or password was not recognized.';
        }
    } elseif ($action === 'logout' && $isAuthenticated) {
        $_SESSION = [];
        session_destroy();
        header('Location: admin.php');
        exit;
    } elseif ($action === 'delete_member' && $isAuthenticated) {
        $memberId = filter_var($_POST['member_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $segment = validSegment((string) ($_POST['segment'] ?? 'all'));
        $search = substr(trim((string) ($_POST['search'] ?? '')), 0, 120);

        if ($memberId === false) {
            $pageError = 'Choose a valid member to delete.';
        } else {
            try {
                $delete = openDatabase()->prepare('DELETE FROM event_registrations WHERE id = :id');
                $delete->execute(['id' => $memberId]);
                $_SESSION['admin_flash'] = $delete->rowCount() === 1
                    ? 'Member registration deleted.'
                    : 'That member was not found; the list may have changed.';
                $_SESSION['admin_flash_type'] = 'notice';
                header('Location: admin.php?' . http_build_query(['segment' => $segment, 'q' => $search]));
                exit;
            } catch (Throwable $deleteError) {
                error_log('MGLG member deletion failed: ' . $deleteError->getMessage());
                $pageError = 'Could not delete this member. Check the server error log and try again.';
            }
        }
    } elseif ($action === 'send_message' && $isAuthenticated) {
        $segment = validSegment((string) ($_POST['segment'] ?? 'all'));
        $search = substr(trim((string) ($_POST['search'] ?? '')), 0, 120);
        $audience = (string) ($_POST['audience'] ?? 'filtered');
        $messageSubject = trim((string) ($_POST['subject'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));

        if ($messageSubject === '' || strlen($messageSubject) > 200 || preg_match('/[\r\n]/', $messageSubject)) {
            $pageError = 'Enter a subject of 1 to 200 characters without line breaks.';
        } elseif ($message === '' || strlen($message) > 5000) {
            $pageError = 'Enter a message of 1 to 5,000 characters.';
        } elseif (!in_array($audience, ['all', 'filtered'], true)) {
            $pageError = 'Choose a valid message audience.';
        } elseif (!is_file(__DIR__ . '/vendor/autoload.php')) {
            $pageError = 'The email library is not installed. Run composer install first.';
        } elseif (SMTP_HOST === '' || SMTP_USER === '' || SMTP_PASSWORD === '' || !filter_var(MAIL_FROM_ADDRESS, FILTER_VALIDATE_EMAIL)) {
            $pageError = 'Email delivery is not configured. Add the SMTP settings before sending.';
        } else {
            try {
                require_once __DIR__ . '/vendor/autoload.php';
                $pdo = openDatabase();
                $recipients = findMembers($pdo, $audience === 'all' ? 'all' : $segment, $audience === 'all' ? '' : $search);

                if (!$recipients) {
                    $pageError = 'There are no members in this audience yet.';
                } else {
                    set_time_limit(120);
                    $sent = 0;
                    $mailFailureDetails = [];
                    foreach ($recipients as $recipient) {
                        try {
                            $mailer = new PHPMailer(true);
                            $mailer->isSMTP();
                            $mailer->Host = SMTP_HOST;
                            $mailer->SMTPAuth = true;
                            $mailer->Username = SMTP_USER;
                            $mailer->Password = SMTP_PASSWORD;
                            $mailer->Port = SMTP_PORT;
                            if (SMTP_ENCRYPTION !== '') {
                                $mailer->SMTPSecure = SMTP_ENCRYPTION;
                            }
                            $mailer->CharSet = 'UTF-8';
                            $mailer->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
                            $mailer->addAddress($recipient['email'], $recipient['name']);
                            $mailer->isHTML(true);
                            $mailer->Subject = $messageSubject;
                            $mailer->Body = '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#1d2923">'
                                . '<p>Hello ' . adminEscape($recipient['name']) . ',</p><p>'
                                . nl2br(adminEscape($message)) . '</p><p>My Generation Loves God</p></div>';
                            $mailer->AltBody = "Hello {$recipient['name']},\n\n{$message}\n\nMy Generation Loves God";
                            $mailer->send();
                            $sent++;
                        } catch (Throwable $mailError) {
                            error_log('MGLG bulk email failed: ' . $mailError->getMessage());
                            if (!$mailFailureDetails) {
                                $smtpDetail = isset($mailer) && $mailer instanceof PHPMailer
                                    ? trim($mailer->ErrorInfo)
                                    : '';
                                $mailFailureDetails[] = substr($smtpDetail !== '' ? $smtpDetail : $mailError->getMessage(), 0, 500);
                            }
                            continue;
                        }
                    }

                    $failed = count($recipients) - $sent;
                    $_SESSION['admin_flash'] = $failed === 0
                        ? 'Message sent to all ' . $sent . ' members.'
                        : 'Message sent to ' . $sent . ' of ' . count($recipients) . ' members; ' . $failed . ' failed. SMTP error: ' . ($mailFailureDetails[0] ?? 'unknown error');
                    $_SESSION['admin_flash_type'] = $sent === count($recipients) ? 'success' : 'notice';
                    $query = http_build_query(['segment' => $segment, 'q' => $search]);
                    header('Location: admin.php?' . $query);
                    exit;
                }
            } catch (Throwable $sendError) {
                $diagnostic = substr($sendError->getMessage(), 0, 300);
                error_log('MGLG bulk email setup/query failed: ' . $diagnostic);
                $pageError = 'The message could not be sent. Admin diagnostic: ' . $diagnostic;
            }
        }
    }
}

$isAuthenticated = !empty($_SESSION['mglg_admin_authenticated']);
$flash = $_SESSION['admin_flash'] ?? '';
$flashType = $_SESSION['admin_flash_type'] ?? 'success';
unset($_SESSION['admin_flash'], $_SESSION['admin_flash_type']);
$showAdminLoginSplash = !empty($_SESSION['admin_login_splash']);
unset($_SESSION['admin_login_splash']);

if (!$isAuthenticated): ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173d2e">
    <title>Admin sign in | My Generation Loves God</title>
    <link rel="icon" type="image/png" href="assets/mglg-favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --forest: #173d2e; --leaf: #b7d67a; --paper: #f8f8f2; --ink: #1d2923; --muted: #68736b; --line: #dce2d9; --coral: #e88064; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 24px; color: var(--ink); background: radial-gradient(ellipse at 15% 10%, rgba(183,214,122,.2), transparent 35%), var(--paper); font-family: 'DM Sans', sans-serif; }
        .login { width: min(100%, 410px); padding: 34px; border: 1px solid var(--line); border-radius: 5px; background: white; box-shadow: 0 18px 48px rgba(23,61,46,.08); }
        .brand { color: var(--forest); font: 800 13px/1.3 Manrope, sans-serif; }
        .brand-logo { display: block; width: min(230px, 100%); height: auto; }
        .eyebrow { margin: 30px 0 8px; color: #5e7746; text-transform: uppercase; font-size: 10px; font-weight: 700; letter-spacing: 1.4px; }
        h1 { margin: 0; font: 800 28px/1.2 Manrope, sans-serif; }
        p { color: var(--muted); font-size: 13px; line-height: 1.6; }
        form { display: grid; gap: 15px; margin-top: 24px; }
        label { display: grid; gap: 7px; font-size: 12px; font-weight: 700; }
        input { width: 100%; min-height: 45px; padding: 0 12px; border: 1px solid #cfd8ce; border-radius: 4px; font: inherit; }
        input:focus { outline: 3px solid rgba(82,123,84,.17); border-color: #527b54; }
        button { min-height: 48px; margin-top: 4px; border: 0; border-radius: 4px; color: white; background: var(--forest); font: 800 13px Manrope, sans-serif; cursor: pointer; }
        .notice { margin: 18px 0 0; padding: 11px 13px; border-left: 3px solid var(--coral); background: #fff0e9; color: #53372f; font-size: 12px; line-height: 1.5; }
        .welcome-splash { position: fixed; inset: 0; z-index: 1000; display: grid; place-items: center; padding: 24px; background: #07130e; }
        .welcome-splash[hidden] { display: none; }
        .welcome-splash video { width: min(100%, 1100px); max-height: calc(100vh - 48px); object-fit: contain; }
        .splash-skip { position: absolute; top: 20px; right: 20px; padding: 10px 16px; color: white; border: 1px solid rgba(255,255,255,.65); border-radius: 4px; background: rgba(0,0,0,.5); font: 700 13px 'DM Sans', sans-serif; cursor: pointer; }
        .back { display: inline-block; margin-top: 19px; color: var(--forest); font-size: 12px; font-weight: 700; text-decoration-thickness: 1px; text-underline-offset: 3px; }
    </style>
</head>
<body>
    <?php if ($showAdminLoginSplash): ?>
    <div class="welcome-splash" id="welcome-splash" role="dialog" aria-label="Welcome" aria-modal="true">
        <video id="welcome-video" autoplay muted playsinline preload="auto" aria-label="Welcome video"><source src="assets/WhatsApp%20Video%202026-07-17%20at%2012.31.12%20PM.mp4" type="video/mp4"></video>
        <button class="splash-skip" type="button" id="splash-skip">Skip video</button>
    </div>
    <script>
    (() => {
        const splash = document.getElementById('welcome-splash');
        const video = document.getElementById('welcome-video');
        const close = () => { splash.hidden = true; video.pause(); };
        document.getElementById('splash-skip').addEventListener('click', close);
        video.addEventListener('ended', close);
        video.addEventListener('error', close);
        video.play().catch(() => {});
    })();
    </script>
    <?php endif; ?>
    <main class="login">
        <div class="brand"><img class="brand-logo" src="assets/mglg-favicon.png" alt="My Generation Loves God"></div>
        <div class="eyebrow">MGLG administration</div>
        <h1>Sign in</h1>
        <p>Manage event registrations and member communication.</p>
        <?php if ($loginError !== ''): ?><div class="notice" role="alert"><?= adminEscape($loginError) ?></div><?php endif; ?>
        <form method="post" action="admin.php">
            <input type="hidden" name="csrf_token" value="<?= adminEscape($_SESSION['admin_csrf']) ?>">
            <input type="hidden" name="action" value="login">
            <label for="username">Admin username<input id="username" name="username" autocomplete="username" required></label>
            <label for="password">Password<input id="password" name="password" type="password" autocomplete="current-password" required></label>
            <button type="submit">Sign in</button>
        </form>
        <a class="back" href="index.php">Back to event registration</a>
    </main>
</body>
</html>
<?php exit; endif;

$segment = validSegment((string) ($_GET['segment'] ?? 'all'));
$search = substr(trim((string) ($_GET['q'] ?? '')), 0, 120);
$members = [];
$stats = ['total' => 0, 'students' => 0, 'non_students' => 0, 'attachments' => 0, 'job_seekers' => 0, 'employed' => 0];
$dbError = '';

try {
    $pdo = openDatabase();
    $members = findMembers($pdo, $segment, $search);
    $stats = $pdo->query(
        "SELECT COUNT(*) AS total,
                SUM(participant_type = 'student') AS students,
                SUM(participant_type = 'non_student') AS non_students,
                SUM(attachment_seeking = 'yes') AS attachments,
                SUM(employment_status = 'seeking_job') AS job_seekers,
                SUM(employment_status = 'employed') AS employed
         FROM event_registrations"
    )->fetch(PDO::FETCH_ASSOC) ?: $stats;
} catch (Throwable $databaseError) {
    $dbError = 'Could not load member records. Start MySQL and confirm the event_registrations table is installed.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173d2e">
    <title>Members | My Generation Loves God</title>
    <link rel="icon" type="image/png" href="assets/mglg-favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --forest: #173d2e; --leaf: #b7d67a; --paper: #f8f8f2; --ink: #1d2923; --muted: #68736b; --line: #dce2d9; --coral: #e88064; --white: #fff; }
        * { box-sizing: border-box; }
        body { margin: 0; color: var(--ink); background: var(--paper); font-family: 'DM Sans', sans-serif; }
        button, input, select, textarea { font: inherit; }
        .shell { width: min(100% - 48px, 1540px); margin: 0 auto; }
        .topbar { min-height: 76px; display: flex; align-items: center; justify-content: space-between; gap: 18px; border-bottom: 1px solid var(--line); }
        .brand { color: var(--forest); font: 800 13px/1.3 Manrope, sans-serif; }
        .brand-logo { display: block; width: min(230px, 100%); height: auto; }
        .top-actions { display: flex; align-items: center; gap: 16px; color: var(--muted); font-size: 12px; }
        .text-button { padding: 8px 12px; color: var(--forest); border: 1px solid #b8c8b7; border-radius: 4px; background: transparent; font-size: 12px; font-weight: 700; cursor: pointer; }
        .page-heading { display: flex; align-items: end; justify-content: space-between; gap: 20px; padding: 26px 0 19px; }
        .eyebrow { color: #5e7746; text-transform: uppercase; font-size: 10px; font-weight: 700; letter-spacing: 1.2px; }
        h1 { margin: 7px 0 0; font: 800 27px/1.2 Manrope, sans-serif; }
        .event-info { color: var(--muted); font-size: 12px; }
        .stats { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); margin-bottom: 21px; border-block: 1px solid var(--line); }
        .stat { min-width: 0; padding: 15px 16px 14px 0; }
        .stat + .stat { padding-left: 16px; border-left: 1px solid var(--line); }
        .stat strong { display: block; color: var(--forest); font: 800 22px Manrope, sans-serif; }
        .stat span { display: block; margin-top: 3px; color: var(--muted); font-size: 11px; }
        .notice { margin: 0 0 16px; padding: 11px 13px; border-left: 3px solid var(--forest); background: #edf3e5; font-size: 13px; line-height: 1.5; }
        .notice.error { border-color: var(--coral); background: #fff0e9; }
        .filters { display: flex; flex-wrap: wrap; align-items: end; gap: 10px; padding: 0 0 18px; }
        .filter-field { display: grid; gap: 6px; }
        .filter-field label, .compose label { color: #455149; font-size: 11px; font-weight: 700; }
        .filter-field input, .filter-field select, .compose input, .compose select, .compose textarea { min-height: 40px; padding: 0 10px; border: 1px solid #cfd8ce; border-radius: 4px; color: var(--ink); background: var(--white); }
        .filter-field input { width: min(290px, 64vw); }
        .filter-field select { min-width: 190px; }
        .primary-button { min-height: 40px; padding: 0 15px; border: 0; border-radius: 4px; color: white; background: var(--forest); font-size: 12px; font-weight: 700; cursor: pointer; }
        .clear-link { padding: 12px 3px; color: var(--forest); font-size: 12px; font-weight: 700; text-underline-offset: 3px; }
        .workspace { display: grid; grid-template-columns: minmax(0, 1fr) 330px; align-items: start; gap: 25px; padding-bottom: 42px; }
        .directory-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; padding: 0 0 11px; }
        h2 { margin: 0; font: 800 15px Manrope, sans-serif; }
        .result-count { color: var(--muted); font-size: 11px; }
        .table-scroll { overflow-x: auto; border-top: 1px solid var(--line); }
        table { width: 100%; min-width: 910px; border-collapse: collapse; text-align: left; }
        th { padding: 11px 12px 10px 0; color: #718075; border-bottom: 1px solid var(--line); font-size: 10px; font-weight: 700; text-transform: uppercase; }
        td { padding: 13px 12px 13px 0; border-bottom: 1px solid var(--line); vertical-align: top; font-size: 11px; line-height: 1.5; }
        td strong { display: block; color: var(--ink); font-size: 12px; }
        td a { color: var(--forest); text-decoration-thickness: 1px; text-underline-offset: 2px; }
        .sub { display: block; margin-top: 3px; color: var(--muted); }
        .tag { display: inline-block; padding: 3px 7px; border-radius: 3px; color: var(--forest); background: #e8efdf; font-size: 10px; font-weight: 700; white-space: nowrap; }
        .compose { padding: 17px; border: 1px solid var(--line); border-radius: 5px; background: white; }
        .compose h2 { margin-bottom: 5px; }
        .compose > p { margin: 0 0 16px; color: var(--muted); font-size: 11px; line-height: 1.5; }
        .compose form { display: grid; gap: 11px; }
        .compose label { display: grid; gap: 6px; }
        .compose select, .compose input, .compose textarea { width: 100%; }
        .compose textarea { min-height: 165px; padding: 10px; resize: vertical; line-height: 1.5; }
        .compose .primary-button { width: 100%; margin-top: 2px; }
        .compose-note { margin-top: 10px; color: #818b82; font-size: 10px; line-height: 1.5; }
        .empty, .db-error { margin: 16px 0; padding: 17px; border: 1px solid var(--line); color: var(--muted); background: white; font-size: 12px; }
        .welcome-splash { position: fixed; inset: 0; z-index: 1000; display: grid; place-items: center; padding: 24px; background: #07130e; }
        .welcome-splash[hidden] { display: none; }
        .welcome-splash video { width: min(100%, 1100px); max-height: calc(100vh - 48px); object-fit: contain; }
        .splash-skip { position: absolute; top: 20px; right: 20px; padding: 10px 16px; color: white; border: 1px solid rgba(255,255,255,.65); border-radius: 4px; background: rgba(0,0,0,.5); font: 700 13px 'DM Sans', sans-serif; cursor: pointer; }
        @media (max-width: 1100px) { .workspace { grid-template-columns: minmax(0, 1fr) 290px; gap: 18px; } .stats { grid-template-columns: repeat(3, 1fr); } .stat:nth-child(4) { border-left: 0; padding-left: 0; } }
        @media (max-width: 760px) { .shell { width: min(100% - 32px, 1540px); } .workspace { grid-template-columns: 1fr; } .compose { grid-row: 1; } .page-heading { align-items: start; flex-direction: column; gap: 8px; } }
        @media (max-width: 520px) { .topbar { min-height: 66px; } .top-actions { gap: 8px; } .top-actions span { display: none; } .stats { grid-template-columns: repeat(2, 1fr); } .stat:nth-child(odd) { padding-left: 0; border-left: 0; } .stat:nth-child(even) { padding-left: 12px; } .filters { align-items: stretch; } .filter-field, .filter-field input, .filter-field select { width: 100%; } .filters .primary-button { flex: 1; } }
    </style>
</head>
<body>
<?php if ($showAdminLoginSplash): ?>
<div class="welcome-splash" id="welcome-splash" role="dialog" aria-label="Welcome" aria-modal="true">
    <video id="welcome-video" autoplay muted playsinline preload="auto" aria-label="Welcome video"><source src="assets/WhatsApp%20Video%202026-07-17%20at%2012.31.12%20PM.mp4" type="video/mp4"></video>
    <button class="splash-skip" type="button" id="splash-skip">Skip video</button>
</div>
<script>
(() => {
    const splash = document.getElementById('welcome-splash');
    const video = document.getElementById('welcome-video');
    const close = () => { splash.hidden = true; video.pause(); };
    document.getElementById('splash-skip').addEventListener('click', close);
    video.addEventListener('ended', close);
    video.addEventListener('error', close);
    video.play().catch(() => {});
})();
</script>
<?php endif; ?>
<div class="shell">
    <header class="topbar">
        <div class="brand"><img class="brand-logo" src="assets/mglg-favicon.png" alt="My Generation Loves God"></div>
        <div class="top-actions"><span>Administrator</span><form method="post" action="admin.php"><input type="hidden" name="csrf_token" value="<?= adminEscape($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="logout"><button class="text-button" type="submit">Sign out</button></form></div>
    </header>
    <main>
        <section class="page-heading"><div><div class="eyebrow">MGLG event directory</div><h1>Registered members</h1></div><div class="event-info">18 October 2026 · Thika Town · From 1:00 PM</div></section>
        <?php if ($flash !== ''): ?><div class="notice <?= $flashType === 'notice' ? 'error' : '' ?>" role="status"><?= adminEscape($flash) ?></div><?php endif; ?>
        <?php if ($pageError !== ''): ?><div class="notice error" role="alert"><?= adminEscape($pageError) ?></div><?php endif; ?>
        <?php if ($dbError !== ''): ?><div class="db-error" role="alert"><?= adminEscape($dbError) ?></div><?php endif; ?>

        <section class="stats" aria-label="Member totals">
            <div class="stat"><strong><?= (int) $stats['total'] ?></strong><span>All members</span></div>
            <div class="stat"><strong><?= (int) $stats['students'] ?></strong><span>Students</span></div>
            <div class="stat"><strong><?= (int) $stats['non_students'] ?></strong><span>Non-students</span></div>
            <div class="stat"><strong><?= (int) $stats['attachments'] ?></strong><span>Seeking attachment</span></div>
            <div class="stat"><strong><?= (int) $stats['job_seekers'] ?></strong><span>Seeking jobs</span></div>
            <div class="stat"><strong><?= (int) $stats['employed'] ?></strong><span>Employed</span></div>
        </section>

        <form class="filters" method="get" action="admin.php">
            <div class="filter-field"><label for="q">Search members</label><input id="q" name="q" type="search" value="<?= adminEscape($search) ?>" placeholder="Name, email, course, town..."></div>
            <div class="filter-field"><label for="segment">Category</label><select id="segment" name="segment">
                <?php foreach (['all' => 'All members', 'students' => 'Students', 'non_students' => 'Non-students', 'seeking_attachment' => 'Seeking attachment', 'seeking_job' => 'Seeking a job', 'employed' => 'Employed', 'not_seeking' => 'Not seeking work'] as $value => $label): ?>
                    <option value="<?= adminEscape($value) ?>" <?= $segment === $value ? 'selected' : '' ?>><?= adminEscape($label) ?></option>
                <?php endforeach; ?>
            </select></div>
            <button class="primary-button" type="submit">Apply filters</button>
            <?php if ($segment !== 'all' || $search !== ''): ?><a class="clear-link" href="admin.php">Clear filters</a><?php endif; ?>
        </form>

        <div class="workspace">
            <section aria-labelledby="directory-title">
                <div class="directory-head"><h2 id="directory-title"><?= adminEscape(segmentLabel($segment)) ?></h2><span class="result-count"><?= count($members) ?> shown</span></div>
                <?php if (!$dbError && !$members): ?>
                    <div class="empty">No members match this view yet.</div>
                <?php elseif ($members): ?>
                    <div class="table-scroll"><table>
                        <thead><tr><th>Member</th><th>Contact</th><th>Category</th><th>Course / area</th><th>Interest / work</th><th>Location</th><th>Joined</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($members as $member): ?>
                            <tr>
                                <td><strong><?= adminEscape($member['name']) ?></strong><span class="sub"><?= adminEscape($member['institution']) ?></span><span class="sub"><?= adminEscape($member['occupation']) ?></span></td>
                                <td><a href="mailto:<?= adminEscape($member['email']) ?>"><?= adminEscape($member['email']) ?></a><span class="sub"><a href="tel:<?= adminEscape($member['phone']) ?>"><?= adminEscape($member['phone']) ?></a></span></td>
                                <td><span class="tag"><?= $member['participant_type'] === 'student' ? 'Student' : 'Non-student' ?></span>
                                    <?php if ($member['participant_type'] === 'student' && $member['student_year']): ?><span class="sub"><?= adminEscape($member['student_year']) ?></span><?php endif; ?></td>
                                <td><?= $member['participant_type'] === 'student' ? '<strong>' . adminEscape($member['course'] ?: 'Course not provided') . '</strong>' : '<strong>' . adminEscape($member['area_of_specification'] ?: 'Area not provided') . '</strong>' ?>
                                    <?php if ($member['participant_type'] === 'non_student' && $member['course']): ?><span class="sub">Studied: <?= adminEscape($member['course']) ?></span><?php endif; ?></td>
                                <td><?php if ($member['participant_type'] === 'student'): ?>
                                    <?php if ($member['attachment_seeking'] === 'yes'): ?><span class="tag">Seeking attachment</span><?php elseif ($member['attachment_seeking'] === 'no'): ?>Not seeking attachment<?php else: ?>Attachment status not provided<?php endif; ?>
                                    <?php else: ?>
                                    <?php if ($member['employment_status'] === 'seeking_job'): ?><span class="tag">Seeking a job</span><?php elseif ($member['employment_status'] === 'employed'): ?><span class="tag">Employed</span><?php elseif ($member['employment_status'] === 'not_seeking'): ?>Not seeking work<?php else: ?>Employment status not provided<?php endif; ?><?php endif; ?></td>
                                <td><strong><?= adminEscape($member['area_of_residence']) ?></strong><span class="sub">Home: <?= adminEscape($member['hometown']) ?></span></td>
                                <td><?= adminEscape(date('d M Y', strtotime($member['created_at']))) ?></td>
                                <td><form method="post" action="admin.php" onsubmit="return confirm('Permanently delete this member registration?');"><input type="hidden" name="csrf_token" value="<?= adminEscape($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="delete_member"><input type="hidden" name="member_id" value="<?= (int) $member['id'] ?>"><input type="hidden" name="segment" value="<?= adminEscape($segment) ?>"><input type="hidden" name="search" value="<?= adminEscape($search) ?>"><button class="text-button" type="submit">Delete</button></form></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </section>

            <aside class="compose" aria-labelledby="compose-title">
                <h2 id="compose-title">Message members</h2>
                <p>Send an email update to everyone or the current filtered group.</p>
                <form method="post" action="admin.php">
                    <input type="hidden" name="csrf_token" value="<?= adminEscape($_SESSION['admin_csrf']) ?>">
                    <input type="hidden" name="action" value="send_message">
                    <input type="hidden" name="segment" value="<?= adminEscape($segment) ?>">
                    <input type="hidden" name="search" value="<?= adminEscape($search) ?>">
                    <label for="audience">Recipients<select id="audience" name="audience"><option value="filtered" selected><?= adminEscape(segmentLabel($segment)) ?> (<?= count($members) ?>)</option><option value="all">All registered members (<?= (int) $stats['total'] ?>)</option></select></label>
                    <label for="subject">Subject<input id="subject" name="subject" maxlength="200" value="<?= adminEscape($messageSubject) ?>" required></label>
                    <label for="message">Message<textarea id="message" name="message" maxlength="5000" required><?= adminEscape($message) ?></textarea></label>
                    <button class="primary-button" type="submit">Send email</button>
                </form>
                <div class="compose-note">Recipients receive individual emails. SMTP must be configured before sending.</div>
            </aside>
        </div>
    </main>
</div>
</body>
</html>
