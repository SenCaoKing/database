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
     *
     * 返回符合条件的所有数据
     *
     * @param string $condition
     * @param array $params
     * @return array|object[]
     */
    public function findAll($condition = '', $params = array())
    {
        $this->where($condition, $params);

        $sql = 'SELECT ' . static::getFieldString() . ' FROM ' . $this->table
            . $this->getWhereString()
            . $this->getOrderByString()
            . $this->getLimitString();

        $sql = static::replacePlaceholder($sql);
        return static::findAllBySql($sql, $this->params, $this->fetchClass);
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
     * 设置条件
     *
     * @param string|array $condition 条件 例如 `name=? AND status=?` 或者 `['name'=>'Sen', 'status'=>1]`, 为数组时字段之间使用AND连接
     * @param array $params 条件中占位符对应的值。当`$condition`为`array`时，此参数无效
     * @param bool $andWhere 重复调用`where()`时, 默认使用`AND`与已有条件连接，此参数为`false`时，使用`OR`连接
     * @return static
     */
    public function where($condition = '', $params = array(), $andWhere = true)
    {
        if (static::isEmpty($condition)) {
            return $this;
        }

        if (is_array($condition)) {
            return $this->whereWithArray($condition, 'AND', $andWhere);
        }

        if (empty($this->condition)) {
            $this->condition = $condition;
            $this->params = $params;
        } else {
            $glue = $andWhere ? ' AND ' : ' OR ';
            $this->condition = '(' . $this->condition . ')' . $glue . '(' . $condition . ')';
            $this->params = array_merge($this->params, $params);
        }
        return $this;
    }

    /**
     * 返回where部分sql
     *
     * @return string
     */
    protected function getWhereString()
    {
        return static::isEmpty($this->condition) ? '' : (' WHERE ' . $this->condition);
    }

    /**
     * 返回字段部分sql
     *
     * @return array|string
     * @throws Exception
     */
    protected function getFieldString()
    {
        $field = $this->field;
        $return = '*';
        if (!static::isEmpty($field)) {
            if (is_array($field)) {
                $return = array();
                foreach ($field as $value) {
                    $return[] = '[[' . $value . ']]';
                }
                $return = join(',', $return);
            }
            if (!preg_match('/^[\w\s\.\,\[\]`\*]+$/', $return)) {
                throw new Exception(__CLASS__ . '::field() 含有不安全的字符'); // 字母、数字、下划线、空白、点、星号、逗号、中括号、反引号
            }
        }
        return $return;
    }

    /**
     * 返回排序部分sql
     *
     * @return string
     */
    protected function getOrderByString()
    {
        $orderBy = $this->orderBy;
        if ($orderBy !== null) {

            $columns = $orderBy;
            $orders = array();
            foreach ($columns as $name => $direction) {
                static::checkColumnName($name);
                $orders[] = $name . ($direction === SORT_DESC ? ' DESC ' : '');
            }
            return ' ORDER BY ' . implode(', ', $orders);
        }
        return '';
    }

    /**
     * 返回limit部分sql
     *
     * @return string
     * @throws Exception
     */
    protected function getLimitString()
    {
        $limit = trim($this->limit);
        $offset = trim($this->offset);

        if (static::isEmpty($limit)) {
            return '';
        }

        if (static::isEmpty($offset)) {
            return 0;
        }

        if (preg_match('/^\d+$/', $limit) && preg_match('/^\d+$/', $offset)) {
            if ($offset == 0) {
                return ' LIMIT ' . $limit;
            } else {
                return ' LIMIT ' . $offset . ',' . $limit;
            }
        }
        throw new Exception("offset or limit 包含非法字符");
    }

    /**
     * 处理数组条件
     *
     * @param array $where
     * @param bool $andGlue 是否使用AND连接数组中的多个成员
     * @param bool $andWhere 重复调用`where()`时，默认使用`AND`与已有条件连接，此参数为`false`时，使用`OR`连接条件
     * @return $this
     */
    protected function whereWithArray(array $where, $andGlue = true, $andWhere = true)
    {
        if (static::isEmpty($where)) {
            return $this;
        }
        $params = array();
        $conditions = array();
        foreach ($where as $k => $v) {
            static::checkColumnName($k);
            $conditions[] = '[[' . $k . ']] = ?';
            $params[] = $v;
        }
        $glue = $andGlue ? ' AND ' : ' OR ';
        return $this->where(join($glue, $conditions), $params, $andWhere);
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
     * 统一占位符 如果同时存在问号和冒号，则将问号参数转为冒号
     *
     * @param $sql
     * @return string
     */
    protected function replacePlaceholder($sql)
    {
        static $staticCount = 0;
        if (strpos($sql, '?') !== false && strpos($sql, ':') !== false) {
            $count = substr_count($sql, '?');
            for ($i = 0; $i < $count; $i++) {
                $num = $i + $staticCount;
                $staticCount++;
                $sql = preg_replace('/\?/', static::PARAM_PREFIX . $num, $sql, 1);
                $this->params[static::PARAM_PREFIX . $num] = $this->params[$i];
                unset($this->params[$i]);
            }
        }
        return $sql;
    }

    /**
     * 检查是否为空 以下值：null、''、空数组、空白字符("\t"、"\n"、"\r"等) 被认为是空值
     *
     * @param mixed $value
     * @return boolean
     */
    protected static function isEmpty($value)
    {
        return $value === '' || $value === array() || $value === null || is_string($value) && trim($value) === '';
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