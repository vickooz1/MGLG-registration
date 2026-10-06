<?php

$localConfigPath = __DIR__ . '/config.local.php';
$localConfig = is_file($localConfigPath) ? require $localConfigPath : [];

define('DB_HOST', getenv('MGLG_DB_HOST') ?: ($localConfig['MGLG_DB_HOST'] ?? '/home/vol14_3/infinityfree.com/if0_43093339'));
define('DB_PORT', (int) (getenv('MGLG_DB_PORT') ?: ($localConfig['MGLG_DB_PORT'] ?? 3306)));
define('DB_NAME', getenv('MGLG_DB_NAME') ?: ($localConfig['MGLG_DB_NAME'] ?? 'mglg_registration'));
define('DB_USER', getenv('MGLG_DB_USER') ?: ($localConfig['MGLG_DB_USER'] ?? 'if0_43093339'));
define('DB_PASSWORD', getenv('MGLG_DB_PASSWORD') ?: ($localConfig['MGLG_DB_PASSWORD'] ?? ''));

define('SMTP_HOST', getenv('MGLG_SMTP_HOST') ?: ($localConfig['MGLG_SMTP_HOST'] ?? ''));
define('SMTP_USER', getenv('MGLG_SMTP_USER') ?: ($localConfig['MGLG_SMTP_USER'] ?? ''));
$smtpPassword = getenv('MGLG_SMTP_PASSWORD') ?: ($localConfig['MGLG_SMTP_PASSWORD'] ?? '');
define('SMTP_PASSWORD', preg_replace('/\s+/', '', $smtpPassword));
define('SMTP_PORT', (int) (getenv('MGLG_SMTP_PORT') ?: ($localConfig['MGLG_SMTP_PORT'] ?? 587)));
define('SMTP_ENCRYPTION', getenv('MGLG_SMTP_ENCRYPTION') ?: ($localConfig['MGLG_SMTP_ENCRYPTION'] ?? 'tls'));
define('MAIL_FROM_ADDRESS', getenv('MGLG_MAIL_FROM_ADDRESS') ?: ($localConfig['MGLG_MAIL_FROM_ADDRESS'] ?? ''));
define('MAIL_FROM_NAME', getenv('MGLG_MAIL_FROM_NAME') ?: ($localConfig['MGLG_MAIL_FROM_NAME'] ?? 'My Generation Loves God'));

define('MGLG_ADMIN_USERNAME', getenv('MGLG_ADMIN_USERNAME') ?: 'MGLG admin');
define('MGLG_ADMIN_PASSWORD_HASH', getenv('MGLG_ADMIN_PASSWORD_HASH') ?: '$2y$10$2zKfcn5DvZhZakd7bTFma.v1IMXJZZ7yNFAR4JZfcuG5lzV0PEtNC');
