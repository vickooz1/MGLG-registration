# My Generation Loves God registration

A PHP and MySQL registration form and member dashboard for the MGLG gathering in Thika Town on 18 October 2026, from 1:00 PM. The form stores attendee details, asks students about their course, year and attachment search, and asks non-students about their area of specification, previous course and employment status. The admin dashboard supports member search, category filters, and individual bulk email delivery to all members or the current filtered group.

## Run with XAMPP

1. Start Apache and MySQL in the XAMPP Control Panel.
2. Open phpMyAdmin at `http://localhost/phpmyadmin`, create and select a database named `mglg_registration`, then import `database.sql` into it.
3. Install Composer if it is not already available, then run `composer install` from this folder.
4. Configure SMTP and admin environment variables below. Restart Apache after setting them.
5. Open `http://localhost/Register/` for registration or `http://localhost/Register/admin.php` for the dashboard.

If you already imported the earlier version of the database, import `upgrade_admin_dashboard.sql` once instead of re-importing `database.sql`. Existing records remain in place; their new category fields will be blank until updated by a member or administrator.

The database defaults in `config.php` are for a typical local XAMPP install (`root`, no password). Set `MGLG_DB_HOST`, `MGLG_DB_NAME`, `MGLG_DB_USER`, and `MGLG_DB_PASSWORD` to override them.

For InfinityFree deployment, upload the application files into the domain's `htdocs` folder. Include `vendor/` and `assets/`; Composer is not run automatically on the hosting account. Create a MySQL database in the InfinityFree Control Panel, then use the **MySQL DB Name**, **MySQL User Name**, and **MySQL Host Name** shown under **MySQL Databases**. Import `database-hosted.sql` into that selected database through phpMyAdmin. InfinityFree's database setup guide explains where it displays these values and the database password: [How to set up a new MySQL database](https://forum.infinityfree.com/t/how-to-setup-a-new-mysql-database/49342).

Upload `config.local.php` separately; it is excluded from Git. Add the host-provided database values to its returned array (or set the `MGLG_DB_*` environment variables on the host):

```php
'MGLG_DB_HOST' => 'database host from your provider',
'MGLG_DB_PORT' => 3306,
'MGLG_DB_NAME' => 'database name from your provider',
'MGLG_DB_USER' => 'database username from your provider',
'MGLG_DB_PASSWORD' => 'database password from your provider',
```

Do not use the local XAMPP defaults on the deployed site. Keep the existing Gmail settings in the same array. InfinityFree requires an external SMTP provider for mail; the app is configured for Gmail SMTP on port 587 with TLS. See [InfinityFree PHPMailer support](https://forum.infinityfree.com/t/support-for-php-mailer/115366/3).

## SMTP configuration

Set these environment variables in the environment used by Apache:

- `MGLG_SMTP_HOST`
- `MGLG_SMTP_USER`
- `MGLG_SMTP_PASSWORD`
- `MGLG_SMTP_PORT` (usually `587`)
- `MGLG_SMTP_ENCRYPTION` (`tls` for port 587 or `ssl` for port 465)
- `MGLG_MAIL_FROM_ADDRESS`
- `MGLG_MAIL_FROM_NAME` (optional; defaults to My Generation Loves God)

For local XAMPP use, you can put these settings in the ignored `config.local.php` file instead. It returns an array with the same `MGLG_SMTP_*` and `MGLG_MAIL_FROM_*` keys; do not commit that file.

Until Composer dependencies and working SMTP settings are present, registrations are still saved and the page reports that the invitation could not be sent. Avoid placing real SMTP credentials in source control.

## Admin access

The local default admin sign-in is username `MGLG admin` and password `MGLGadmin`. For deployment, add `MGLG_ADMIN_USERNAME` and `MGLG_ADMIN_PASSWORD_HASH` to the returned array in `config.local.php` or set them in the host environment:

```powershell
& 'C:\xampp\php\php.exe' -r "echo password_hash('replace-with-a-strong-password', PASSWORD_DEFAULT), PHP_EOL;"
```

Add the generated hash like this:

```php
'MGLG_ADMIN_USERNAME' => 'your-admin-username',
'MGLG_ADMIN_PASSWORD_HASH' => 'paste-generated-hash-here',
```

Store the resulting hash as `MGLG_ADMIN_PASSWORD_HASH`; do not store the plain password in the project. The dashboard login is at `http://localhost/Register/admin.php`. Bulk messages use the same SMTP settings as event invitations and are sent as individual emails.
