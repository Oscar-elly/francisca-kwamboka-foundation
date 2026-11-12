CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'editor') NOT NULL DEFAULT 'editor',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    excerpt TEXT NULL,
    body LONGTEXT NOT NULL,
    image_path VARCHAR(255) NULL,
    status ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pages_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_pages_status_slug (status, slug)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS posts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('news', 'blog', 'article') NOT NULL DEFAULT 'news',
    title VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    excerpt TEXT NULL,
    body LONGTEXT NOT NULL,
    image_path VARCHAR(255) NULL,
    status ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_posts_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_posts_status_type_date (status, type, published_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS gallery_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    caption TEXT NULL,
    alt_text VARCHAR(255) NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_gallery_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_gallery_published_order (is_published, sort_order)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inquiries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(50) NULL,
    subject VARCHAR(190) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('new', 'read', 'replied') NOT NULL DEFAULT 'new',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inquiries_status_date (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
