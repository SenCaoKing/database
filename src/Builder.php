<?php

namespace Sen\Database;

/**
 * 数据库操作辅助类
 *
 * @method int count($field = '*')
 * @method string sum($field)
 * @method mixed max($field)
 * @method mixed min($field)
 * @method string avg($field)
 *
 * @author Sen
 * @since  1.0
 */
class Builder
{
    /**
     * @var Connection 数据库连接对象
     */
    private $db;

    protected $table;
    protected $field;
    protected $orderBy;
    protected $limit;
    protected $offset;
    protected $condition;
    protected $params = array();
    protected $fetchClass;
    protected $lockForUpdate;

    /**
     * 自动生成查询绑定参数前缀
     */
    const PARAM_PREFIX = ':_sb_';

    /**
     * @param array $config 配置信息
     */
    public function __construct(array $config = array())
    {
        if (count($config) > 0) {
            $this->setConnection(new Connection($config));
        }
    }

    /**
     * 设置数据库连接
     *
     * @return static
     */
    public function setConnection(Connection $connection)
    {
        $this->db = $connection;
        return $this;
    }

    /**
     * 返回数据库连接
     *
     * @return Connection
     */
    public function getConnection()
    {
        return $this->db;
    }

    /**
     * 指定查询表名
     *
     * 此方法将自动添加表前缀, 例如配置的表前缀为`cms_`, 则传入参数 `user` 将被替换为 `cms_user`, 等价于`{{%user}}`
     * 如果希望使用后缀, 例如表名为`user_cms`, 使用`{{user%}}`
     * 如果不希望添加表前缀, 例如表名为`user`, 使用`{{user}}`
     * 如果使用自定义表前缀(不使用配置中指定的表前缀), 例如表前缀为`wp_`, 使用`{{wp_user}}`
     *
     * @param string $tableName
     * @return static
     * @throws Exception
     */
    public function table($tableName)
    {
        if (strpos($tableName, '{{') === false) {
            $tableName = '{{%' . $tableName . '}}';
        }
        if (!preg_match('/^\{\{%?[\w\-\.\$]+%?\}\}$/', $tableName)) {
            throw new Exception('表名错误');
        }
        $this->table = $tableName;
        return $this;
    }

    /**
     * 执行新增，返回受影响行数
     * @param array $data
     * @return bool
     */
    public function insert(array $data)
    {
        $names = array();
        $replacePlaceholders = array();
        foreach ($data as $name => $value) {
            static::checkColumnName($name);
            $names[] = '[[' . $name . ']]';
            $phName = ':' . $name;
            $replacePlaceholders[] = $phName;
        }
        $sql = 'INSERT INTO ' . $this->table . ' (' . implode(', ', $names) .') VALUES (' . implode(', ', $replacePlaceholders) . ')';
        return 0 < static::getConnection()->execute($sql, $data);
    }

    /**
     * 执行新增，返回自增ID
     * @param array $data
     * @return int
     */
    public function insertGetId(array $data)
    {
        if (static::insert($data)) {
            return static::getConnection()->getLastInsertId();
        }
        return 0;
    }

    /**
     * 根据SQL查询，返回符合条件的所有数据
     *
     * @param string $sql
     * @param array $params
     * @param null|string $fetchClass
     * @return array|object[]
     */
    public function findAllBySql($sql = '', $params = array(), $fetchClass = null)
    {
        $sql = static::appendLock($sql);

        $this->reset();

        if ($fetchClass === null) {
            return static::getConnection()->query($sql, $params, \PDO::FETCH_ASSOC);
        } else {
            return static::getConnection()->query($sql, $params, \PDO::FETCH_CLASS, $fetchClass);
        }
    }

    /**
     * lockForUpdate
     *
     * @param $sql
     * @return string
     */
    protected function appendLock($sql)
    {
        if ($this->lockForUpdate === true) {
            $sql = rtrim($sql) . ' FOR UPDATE';
        }
        return $sql;
    }

    /**
     * 检查列名是否有效
     *
     * @param string $column 列名只允许字母、数字、下划线、点(.)、中杠(-)
     * @throws Exception
     */
    protected static function checkColumnName($column)
    {
        if (!preg_match('/^[\w\-\.]+$/', $column)) {
            throw new Exception('列名只允许字母、数字、下划线、点(.)、中杠(-)');
        }
    }

    /**
     * 清空所有条件
     */
    protected function reset()
    {
        $this->table = null;
        $this->fetchClass = null;
        $this->orderBy = null;
        $this->field = null;
        $this->limit = null;
        $this->offset = null;
        $this->condition = null;
        $this->params = null;
        $this->lockForUpdate = null;
    }

}