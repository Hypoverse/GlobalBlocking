-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: extensions/GlobalBlocking/sql/abstractSchemaChanges/patch-add-gbw_central_id.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
ALTER TABLE  /*_*/global_block_whitelist
ADD  COLUMN gbw_central_id INTEGER UNSIGNED DEFAULT NULL;