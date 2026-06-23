<?php

$dbConfig = nexus_config('nexus.database');
$connectionName = $dbConfig['default'] ?? nexus_env('DB_CONNECTION', 'mysql');
$config = $dbConfig['connections'][$connectionName] ?? nexus_config('nexus.mysql');
\Nexus\Database\NexusDB::bootEloquent($config);


