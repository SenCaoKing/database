<?php
namespace A;

include "./autoload.php";

class User extends \Sen\Database\ActiveRecord
{
}

$config = [
    'dsn' => 'mysql:host=localhost;dbname=sen_database',
    'username' => 'root',
    'password' => 'root',
    'charset' => 'utf8',
    'tablePrefix' => ''
];

$db = new \Sen\Database\Connection($config);

$user = User::find($db)->where(['id' => 2])->one();

var_dump((array)$user->isNewRecord());
var_dump($user);