-- Optional, for a local MySQL owner account (for example in phpMyAdmin).
-- On cPanel use MySQL Database Wizard instead, and put the FULL prefixed name
-- in MYSQL_DB_NAME in config.php. Do not run this against an existing school's data.
CREATE DATABASE IF NOT EXISTS `alpha_school`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
-- Application tables, indexes and audit triggers are initialized by:
-- php alpha-school/scripts/init-production.php
