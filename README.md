# Francisca Kwamboka Foundation

A responsive foundation website and private content management system built with PHP 8.2+ and MySQL 8+, intended for local development with XAMPP and deployment to a PHP/MySQL host.

## MVP

- Public home, about, causes, news and articles, gallery, contact, and donation-information pages.
- CMS-managed pages, posts (news/blog/article), gallery items, user accounts, and contact enquiries.
- An authenticated page-section editor with reusable layouts, image uploads, section ordering, and visibility controls.
- Admin/editor-managed FAQs with public accordion display and FAQ structured data.
- Search metadata, social previews, sitemap, and robots instructions for public pages.
- Role-aware admin access (`admin` and `editor`), password hashing, CSRF protection, prepared SQL statements, and validated image uploads.
- Existing foundation copy and available site images are used as supplied; no copy rewriting.

## Local setup

1. Copy `config.example.php` to `config.php`, then set the MySQL credentials.
2. Create an empty database and database user, then grant the user access to the database.
3. Run `php scripts/migrate.php`. It creates the schema, records applied migrations, and imports the initial content and editable sections. Run it again safely after every code update.
4. Run `php scripts/create_admin.php` and follow the prompts to create the first administrator.
5. Set `public_url` in `config.php` or `FKF_PUBLIC_URL` to the canonical HTTPS website URL in production so canonical links and the XML sitemap use the correct domain.
6. Start the PHP development server with `php -S 127.0.0.1:8000 -t public public/router.php` and open `http://127.0.0.1:8000`.

The public document root must be `public/`. Configure writable access to `storage/uploads/`. Keep `config.php` out of version control and configure HTTPS in production.

## Admin

Sign in at `/admin/login`. Administrators can manage website pages, page sections, news/blog/articles, the image gallery, team accounts, and contact submissions. Editors can manage content but not user accounts.

Open **Edit pages** in the admin navigation to choose a page. Open any page part to change its heading, role or small heading, text, or photo, then choose **Save changes**. For board members, edit **Role or small heading** to update the role shown beneath their name. Use **More options** when you need to change a part's style, button, or visibility. Move parts with the separate **Move up** and **Move down** buttons. Add a new part from the bottom of the page; supported types include a large introduction, text, photo and text, cards, a highlight banner, board members, and donation options. For cards, enter one per line as `Title | Description | optional page link`. Pages without editable parts keep showing their original content; adding the first part keeps that content and makes it editable.

Open **FAQs** in the admin navigation to add, edit, publish, reorder, or remove answers. Published entries appear on `/page/faqs`; drafts stay private. The page includes FAQ structured data for search engines when published entries are available.

For an existing installation, back up the database before the first run of `php scripts/migrate.php`. It applies unapplied database migrations and refuses to run if an already-applied migration file has been changed; add a new migration file for later schema updates.

## Automatic cPanel deployment

Pushes to `main` trigger [the cPanel deployment workflow](./.github/workflows/deploy-cpanel.yml). It connects to the cPanel Git checkout over SSH, pulls `origin/main` without overwriting server-side edits, and runs `php scripts/migrate.php`. To enable it, configure these GitHub repository variables:

- `CPANEL_HOST`, `CPANEL_PORT`, and `CPANEL_USER`: the SSH hostname, port (usually `22`), and cPanel account.
- `CPANEL_DEPLOY_PATH`: the Git checkout path. For this staging site, use `/home/franpthw/staging.franciscakwambokafoundation.org`.
- `CPANEL_PHP_CLI`: the cPanel PHP CLI executable for PHP 8.2 or newer (for example, `php` or `ea-php82`).
- `CPANEL_KNOWN_HOSTS`: the verified SSH host key line for the cPanel host (obtain it with `ssh-keyscan -H -p PORT HOST`, then verify its fingerprint with your hosting provider).

Add the private key for a dedicated deployment SSH key as the GitHub Actions secret `CPANEL_SSH_KEY`; authorize its public key for SSH access in cPanel. The cPanel checkout must be on the `main` branch, have `origin` configured to this GitHub repository, and be able to pull it. Keep `config.php` out of Git and create it in the checkout with the production database credentials and canonical `public_url`; the automated migration step will fail clearly if configuration or database access is missing.

Initial cPanel setup still requires creating the database and database user in cPanel, granting the user privileges, creating `config.php`, and creating the first administrator with `php scripts/create_admin.php`. Do not manually import `database/schema.sql`; the first deployment creates the tables and seeds initial site content. Once setup is complete, future pushes deploy code and apply new migrations automatically. Configure the website document root to the repository's `public/` directory, enable HTTPS, and allow PHP to write to `public/uploads/`.

This is the initial MVP. Set up outgoing mail separately before relying on contact notifications or donation processing.
