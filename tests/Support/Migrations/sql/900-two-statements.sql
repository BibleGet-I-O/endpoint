-- Two statements: Doctrine's addSql() would send them as one prepared statement, which PostgreSQL refuses.
CREATE TABLE migration_probe (n INT NOT NULL);
INSERT INTO migration_probe (n) VALUES (1), (2);
