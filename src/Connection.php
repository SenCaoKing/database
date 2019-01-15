<?php

namespace Sen\Database;

use PDO;
use PDOException;

/**
 * 数据库连接(PDO)
 * @author Sen
 * @since 1.0
 */
class Connection
{
    protected $pdo;
    protected $readPdo;
    protected $transactions = 0;

    protected $enableQueryLog = false;
    protected $queryLog = array();
}