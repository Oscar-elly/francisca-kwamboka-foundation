CREATE TABLE IF NOT EXISTS page_sections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    section_type VARCHAR(40) NOT NULL,
    eyebrow VARCHAR(190) NULL,
    title VARCHAR(190) NOT NULL DEFAULT '',
    body LONGTEXT NULL,
    image_path VARCHAR(255) NULL,
    link_label VARCHAR(100) NULL,
    link_url VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_page_sections_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
    INDEX idx_page_sections_order (page_id, is_enabled, sort_order, id)
) ENGINE=InnoDB;
