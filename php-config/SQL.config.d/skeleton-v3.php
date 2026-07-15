<?php

// skeleton-v3: MySQL 8 ships with MyISAM effectively retired (and Cloud SQL
// disables it outright); all auto-created tables use InnoDB. SQL::getCreateTable
// reads this static, so no core patch is needed for framework-generated DDL —
// DB::preprocessQuery in emergence/php-core additionally rewrites these
// to catch any hand-written CREATE TABLE ... ENGINE=MyISAM in site code
SQL::$mysqlStorageEngine = 'InnoDB';
