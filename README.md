# Francisca Kwamboka Foundation

A responsive foundation website and private content management system built with PHP 8.2+ and MySQL 8+, intended for local development with XAMPP and deployment to a PHP/MySQL host.

## MVP

- Public home, about, causes, news and articles, gallery, contact, and donation-information pages.
- CMS-managed pages, posts (news/blog/article), gallery items, user accounts, and contact enquiries.
- Role-aware admin access (`admin` and `editor`), password hashing, CSRF protection, prepared SQL statements, and validated image uploads.
- Existing foundation copy and available site images are used as supplied; no copy rewriting.

## Local setup

1. Copy `config.example.php` to `config.php`, then set the MySQL credentials.
2. Create a database and import `database/schema.sql`.
3. Run `php scripts/create_admin.php` and follow the prompts to create the first administrator.
4. Run `php scripts/seed_content.php` to import the current foundation copy and site images into the CMS. The import skips pages and images already present so it will not overwrite subsequent edits.
5. Start the PHP development server with `php -S 127.0.0.1:8000 -t public public/router.php` and open `http://127.0.0.1:8000`.

The public document root must be `public/`. Configure writable access to `storage/uploads/`. Keep `config.php` out of version control and configure HTTPS in production.

## Admin

Sign in at `/admin/login`. Administrators can manage website pages, news/blog/articles, the image gallery, team accounts, and contact submissions. Editors can manage content but not user accounts.

This is the initial MVP. Set up outgoing mail and production deployment separately before relying on contact notifications or donation processing.
