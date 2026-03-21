-- Test database schema for BibleGet endpoint integration tests

-- Counter table for tracking good/bad queries
CREATE TABLE IF NOT EXISTS counter (
    id   TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    good INT NOT NULL DEFAULT 0,
    bad  INT NOT NULL DEFAULT 0
);
INSERT INTO counter (id, good, bad) VALUES (1, 0, 0)
ON DUPLICATE KEY UPDATE id = id;

-- Available Bible versions
CREATE TABLE IF NOT EXISTS versions_available (
    sigla            VARCHAR(20) NOT NULL PRIMARY KEY,
    fullname         VARCHAR(255) NOT NULL DEFAULT '',
    year             VARCHAR(10) NOT NULL DEFAULT '',
    language         VARCHAR(50) NOT NULL DEFAULT '',
    imprimatur       VARCHAR(255) NOT NULL DEFAULT '',
    canon            VARCHAR(20) NOT NULL DEFAULT '',
    copyright_holder VARCHAR(255) NOT NULL DEFAULT '',
    notes            TEXT,
    copyright        TINYINT NOT NULL DEFAULT 0,
    type             VARCHAR(20) NOT NULL DEFAULT 'BIBLE'
);

-- Bible book full names (73 rows = 73 books, columns = languages)
-- Simplified: just two language columns for testing
CREATE TABLE IF NOT EXISTS biblebooks_fullname (
    BOOK    INT NOT NULL PRIMARY KEY,
    ENGLISH VARCHAR(255) NOT NULL DEFAULT '',
    ITALIAN VARCHAR(255) NOT NULL DEFAULT ''
);

-- Bible book abbreviations (same structure)
CREATE TABLE IF NOT EXISTS biblebooks_abbr (
    BOOK    INT NOT NULL PRIMARY KEY,
    ENGLISH VARCHAR(255) NOT NULL DEFAULT '',
    ITALIAN VARCHAR(255) NOT NULL DEFAULT ''
);

-- Request logging (yearly table, dynamically named for current year)
SET @cur_year = DATE_FORMAT(CURDATE(), '%Y');
SET @log_table = CONCAT('requests_log__', @cur_year);
SET @log_sql = CONCAT(
    'CREATE TABLE IF NOT EXISTS `', @log_table, '` (',
    'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, ',
    'WHO_IP VARBINARY(16), ',
    'WHO_WHERE_JSON TEXT, ',
    'HEADERS_JSON TEXT, ',
    'ORIGIN VARCHAR(255) NOT NULL DEFAULT '''', ',
    'QUERY TEXT, ',
    'ORIGINALQUERY TEXT, ',
    'REQUEST_METHOD VARCHAR(10) NOT NULL DEFAULT '''', ',
    'HTTP_CLIENT_IP VARCHAR(45) NOT NULL DEFAULT '''', ',
    'HTTP_X_FORWARDED_FOR VARCHAR(255) NOT NULL DEFAULT '''', ',
    'HTTP_X_REAL_IP VARCHAR(45) NOT NULL DEFAULT '''', ',
    'REMOTE_ADDR VARCHAR(45) NOT NULL DEFAULT '''', ',
    'APP_ID VARCHAR(100) NOT NULL DEFAULT '''', ',
    'DOMAIN VARCHAR(255) NOT NULL DEFAULT '''', ',
    'PLUGINVERSION VARCHAR(50) NOT NULL DEFAULT '''', ',
    'WHO_WHEN TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ')'
);
PREPARE log_stmt FROM @log_sql;
EXECUTE log_stmt;
DEALLOCATE PREPARE log_stmt;

-- curl error log
CREATE TABLE IF NOT EXISTS curl_error (
    id    INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ERRNO INT NOT NULL DEFAULT 0,
    ERROR TEXT
);
