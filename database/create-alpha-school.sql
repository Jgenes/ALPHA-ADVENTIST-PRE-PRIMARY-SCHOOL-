-- Run once with an approved database-owner account, using your provider's SQL console.
-- This creates the named schema only: it never drops or moves an existing database.
-- Use a dedicated application account with access to alpha_school, not a shared root account.
CREATE DATABASE IF NOT EXISTS `alpha_school`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
-- Application tables/indexes/audit triggers are initialized by:
-- php scripts/init-production.php
