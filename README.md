# database

数据库操作类

环境要求：PHP >= 5.3

```php
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
$db->getConnection()->enableQueryLog();

$id = $db->table('test')->insertGetId(['username' =>134, 'status' => '0']);

var_dump($id);
```
