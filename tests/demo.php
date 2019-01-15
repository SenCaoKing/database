<?php

include "./autoload.php";

$config = [
    'dsn' => 'mysql:host=localhost;dbname=sen_database',
    'username' => 'root',
    'password' => 'root',
    'charset' => 'utf8',
    'tablePrefix' => 'db_'
];

$db = new \Sen\Database\Builder($config);

var_dump($db);