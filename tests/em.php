<?php
namespace A;

include './autoload.php';

class User
{
    public static function tableName()
    {
        return 'user';
    }

    public function beforeSave()
    {
        $this->username = $this->username . '12345678';
        return true;
    }

}

$config = [
    'dsn' => 'mysql:host=localhost;dbname=sen_database',
    'username' => 'root',
    'password' => 'root',
    'charset' => 'utf8',
    'tablePrefix' => ''
];