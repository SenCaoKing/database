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

    protected $config = array(
        'dsn' => 'mysql:host=localhost;dbname=sen_database',
        'username' => 'root',
        'password' => 'root',
        'charset' => 'utf8',
        'tablePrefix' => '',
        'options' => array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STRINGIFY_FETCHES => false, // 禁止提取的时候将数值转换为字符串
            PDO::ATTR_EMULATE_PREPARES => false,  // 禁止模拟预处理语句
            PDO::ATTR_CASE => PDO::CASE_NATURAL,
        ),
        'slave' => array(/*
            array(
                'dsn' => 'mysql:host=192.168.11.25;dbname=sen_database',
                'username' => 'root',
                'password' => 'root',
            ),
            array(
                'dsn' => 'mysql:host=192.168.11.26;dbname=sen_database',
                'username' => 'root',
                'password' => 'root',
            ),*/
        ),
    );

    /**
     * Connection constructor.
     * @param array $config 配置信息
     */
    public function __construct(array $config)
    {
        $this->config = array_replace_recursive($this->config, $config);
    }

    /**
     * 返回用于操作主库的PDO对象（增、删、改）
     * @return PDO
     */
    public function getPdo()
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $this->pdo = $this->makePdo($this->config);
        return $this->pdo;
    }

    protected function makePdo(array $config)
    {
        try {
            $pdo = new PDO($config['dsn'], $config['username'], $config['password'], $config['options']);
            $pdo->exec('SET NAMES ' . $pdo->quote($config['charset']));
            return $pdo;
        } catch (PDOException $ex) {
            throw new Exception($ex->getMessage());
        }
    }

    /**
     * 返回用于查询的PDO对象（如果在事务中，将自动调用getPdo()以确保整个事务均使用主库）
     * @return PDO
     */
    public function getReadPdo()
    {
        if ($this->transactions >= 1) {
            return $this->getPdo();
        }

        if ($this->readPdo instanceof PDO) {
            return $this->readPdo;
        }

        if (!is_array($this->config['slave']) || count($this->config['slave']) == 0) {
            return $this->getPdo();
        }

        $slaveDbConfig = $this->config['slave'];
        shuffle($slaveDbConfig);
        do {
            // 取出一个打乱后的从库信息
            $config = array_shift($slaveDbConfig);

            // 使用主库信息补全从库配置
            $config = array_replace_recursive($this->config, $config);

            try {
                $this->readPdo = $this->makePdo($config);
                return $this->readPdo;
            } catch (\Exception $ex) {
                // nothing to do
            }

        } while(count($slaveDbConfig) > 0);

        // 使用主库
        return $this->readPdo = $this->getPdo();
    }

    /**
     * 返回表前缀
     * @return string
     */
    protected function getTablePrefix()
    {
        return $this->config['tablePrefix'];
    }

    /**
     * 解析SQL中的表名
     * 当表前缀为"cms_"时将sql中的"{{%user}}"解析为"`cms_user`"
     * 解析"[[列名]]" 为 "`列名`"
     * @param $sql
     * @return string
     */
    public function quoteSql($sql)
    {
        return preg_replace_callback(
            '/(\\{\\{(%?[\w\-\.\$ ]+%?)\\}\\}|\\[\\[([\w\-\. ]+)\\]\\])/',
            function ($matches) {
                if (isset($matches[3])) {
                    return $this->quoteColumnName($matches[3]);
                } else {
                    return str_replace('%', $this->getTablePrefix(), $this->quoteTableName($matches[2]));
                }
            },
            $sql
        );
    }

    /**
     * 执行SQL语句（增、删、改 类型的SQL），返回受影响行数，执行失败抛出异常
     * @param string $sql 执行的SQL，可以包含问号或冒号占位符，支持{{%table_name}}格式自动替换为表前缀
     * @param array $params 参数，对应SQL中的冒号或问号占位符
     * @return int 返回受影响行数
     * @throws Exception
     */
    public function execute($sql, $params = array())
    {
        $sql = $this->quoteSql($sql);
        try {
            $statement = $this->getPdo()->prepare($sql);
            $start = microtime(true);
            if ($statement->execute($params)) {
                $this->logQuery($sql, $params, $this->getElapsedTime($start));
                return $statement->rowCount();
            }
        } catch (PDOException $ex) {
            throw new Exception($ex->getMessage());
        }
    }

    public function query(){}

    /**
     * 解析SQL中的占位符("?"或":")，主要用于调试SQL
     * @param string $sql
     * @param array $params
     * @return string
     */
    public function parsePlaceholder($sql, array $params = array())
    {
        // 一次替换一个问号
        $count = substr_count($sql, '?');
        for ($i = 0; $i < $count; $i++) {
            $sql = preg_replace('/\?/', $this->getPdo()->quote($params[$i]), $sql, 1);
        }

        // 替换冒号
        $sql = preg_replace_callback('/:(\w+)/', function ($matches) use ($params) {
            if (isset($params[$matches[1]])) {
                return $this->getPdo()->quote($params[$matches[1]]);
            } else if (isset($params[':' . $matches[1]])) {
                return $this->getPdo()->quote($params[':' . $matches[1]]);
            }
            return $matches[0];
        }, $sql);

        return $sql;
    }

    /**
     * 给表名加引号
     * 如果有前缀，前缀也将被加上引号
     * 如果已加引号，或包含 '(' or '{{', 将不做处理
     * @param string $name
     * @return string
     */
    protected function quoteTableName($name)
    {
        if (strpos($name, '(') !== false || strpos($name, '{{') !== false) {
            return $name;
        }
        if (strpos($name, '.') === false) {
            return $this->quoteSimpleTableName($name);
        }
        $parts = explode('.', $name);
        foreach ($parts as $i => $part) {
            $parts[$i] = $this->quoteSimpleTableName($part);
        }

        return implode('.', $parts);
    }

    /**
     * 给列名加引号
     * 如果有前缀，前缀也将被加上引号
     * 如果列名已加引号，或包含 '(', '[[' or '{{', 将不做处理
     * @param string $name
     * @return string
     */
    protected function quoteColumnName($name)
    {
        if (strpos($name, '(') !== false || strpos($name, '[[') !== false || strpos($name, '{{') !== false) {
            return $name;
        }
        if (($pos = strrpos($name, '.')) !== false) {
            $prefix = $this->quoteTableName(substr($name, 0, $pos) . '.');
            $name = substr($name, $pos + 1);
        } else {
            $prefix = '';
        }

        return $prefix . $this->quoteSimpleColumnName($name);
    }

    /**
     * 给表名加上引号
     * 表名为无前缀的简单列名
     * @param string $name
     * @return string
     */
    protected function quoteSimpleTableName($name)
    {
        return strpos($name, '`') !== false ? $name : '`' . $name .'`';
    }

    /**
     * 给列名加上引号
     * 列名为无前缀的简单列名
     * @param string $name
     * @return string
     */
    protected function quoteSimpleColumnName($name)
    {
        return strpos($name, '`') !== false || $name === '*' ? $name : '`' . $name .'`';
    }

    /**
     * 开启记录所有SQL，如果不开启，默认只记录最后一次执行的SQL
     */
    public function enableQueryLog()
    {
        $this->enableQueryLog = true;
    }

    public function disableQueryLog()
    {
        $this->enableQueryLog = false;
    }

    /**
     * 记录SQL
     * @param $sql
     * @param array $params
     */
    protected function logQuery($sql, $params = array(), $time = null)
    {
        if ($this->enableQueryLog) {
            $this->queryLog[] = compact('sql', 'params', 'time');
        } else {
            $this->queryLog = array(compact('sql', 'params', 'time'));
        }
    }

    /**
     * 返回执行的SQL
     * @return array
     */
    public function getQueryLog()
    {
        return $this->queryLog;
    }

    /**
     * 返回最近一次执行的sql语句
     * @return string
     */
    public function getLastSql()
    {
        if (count($this->queryLog) == 0) {
            return null;
        }
        $queryLog = end($this->queryLog);
        return $this->parsePlaceholder($queryLog['sql'], $queryLog['params']);
    }

    /**
     * 计算所使用的时间
     *
     * @param int $start
     * @return float
     */
    protected function getElapsedTime($start)
    {
        return round((microtime(true) - $start) * 1000, 2);
    }
}