-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: extensions/GlobalBlocking/sql/abstractSchemaChanges/patch-add-gb_by_central_id.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
ALTER TABLE  globalblocks
ADD  gb_by_central_id INT DEFAULT NULL;