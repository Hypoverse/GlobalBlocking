-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: extensions/GlobalBlocking/sql/abstractSchemaChanges/patch-add-gb_user_central_id.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
ALTER TABLE  /*_*/globalblocks
ADD  COLUMN gb_user_central_id INTEGER UNSIGNED DEFAULT NULL;