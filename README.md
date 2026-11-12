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
2. Create a database and import `database/schema.sql`.
3. Run `php scripts/create_admin.php` and follow the prompts to create the first administrator.
4. Run `php scripts/seed_content.php` to import the current foundation copy and site images into the CMS. The import skips pages and images already present so it will not overwrite subsequent edits.
5. Run `php scripts/migrate_page_sections.php` to create the page-section table and add editable sections to the home, board, and donation pages. The migration is safe to rerun: existing sections are left unchanged, and the original page text is retained.
6. Run `php scripts/migrate_faqs.php` to create FAQ storage for an existing database (fresh installs already create the table).
7. Set `public_url` in `config.php` or `FKF_PUBLIC_URL` to the canonical HTTPS website URL in production so canonical links and the XML sitemap use the correct domain.
8. Start the PHP development server with `php -S 127.0.0.1:8000 -t public public/router.php` and open `http://127.0.0.1:8000`.

The public document root must be `public/`. Configure writable access to `storage/uploads/`. Keep `config.php` out of version control and configure HTTPS in production.

## Admin

Sign in at `/admin/login`. Administrators can manage website pages, page sections, news/blog/articles, the image gallery, team accounts, and contact submissions. Editors can manage content but not user accounts.

Open **Edit pages** in the admin navigation to choose a page. Open any page part to change its heading, role or small heading, text, or photo, then choose **Save changes**. For board members, edit **Role or small heading** to update the role shown beneath their name. Use **More options** when you need to change a part's style, button, or visibility. Move parts with the separate **Move up** and **Move down** buttons. Add a new part from the bottom of the page; supported types include a large introduction, text, photo and text, cards, a highlight banner, board members, and donation options. For cards, enter one per line as `Title | Description | optional page link`. Pages without editable parts keep showing their original content; adding the first part keeps that content and makes it editable.

Open **FAQs** in the admin navigation to add, edit, publish, reorder, or remove answers. Published entries appear on `/page/faqs`; drafts stay private. The page includes FAQ structured data for search engines when published entries are available.

For an existing installation, back up the database and run `php scripts/migrate_page_sections.php` and `php scripts/migrate_faqs.php` after deploying the new code. The scripts create the section and FAQ tables and the page-section migration imports starter sections for the home, board, and donation pages without overwriting existing page content or sections.

This is the initial MVP. Set up outgoing mail and production deployment separately before relying on contact notifications or donation processing.
