<?php
namespace lib;

class PdoHelper
{
	private $sqlPrefix = "pre_";//SQL数据表前缀识别字符
	private $db;
	private $fetchStyle = \PDO::FETCH_ASSOC;
	private $prefix;
	private $errorInfo;
	private $configLockDepth = 0;
	private $configTransactionLock = false;
	private $configLockName;
	private $connectionConfig;

	// Connection-scoped, app/database/prefix-specific; never serialize unrelated cache or funds.
	public function configLock($timeout = 10){
		if($this->configLockDepth > 0){ $this->configLockDepth++; return true; }
		if($this->configLockName === null){
			$name = $this->getColumn("SELECT CONCAT(DATABASE(), ':', :prefix)", [':prefix'=>$this->prefix]);
			if(!is_string($name)) throw new \RuntimeException('Configuration lock identity failed');
			$this->configLockName = 'epay:config:'.hash('sha256', $name);
			$this->configLockName = substr($this->configLockName, 0, 64);
		}
		// Never wait for the advisory mutex after an ambient transaction may have row locks.
		if($this->db->inTransaction()) $timeout = 0;
		if((string)$this->getColumn('SELECT GET_LOCK(:name, :timeout)', [':name'=>$this->configLockName, ':timeout'=>$timeout]) !== '1') throw new \RuntimeException('Configuration lock unavailable');
		$this->configLockDepth = 1;
		return true;
	}
	public function committedConfigRows(){
		// A separate autocommit connection avoids an ambient REPEATABLE READ snapshot
		// and never publishes a cache or waits on the writer's InnoDB row locks.
		$c = $this->connectionConfig;
		$p = new \PDO("mysql:host={$c['host']};dbname={$c['dbname']};port={$c['port']};charset=utf8mb4", $c['user'], $c['pwd']);
		$p->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
		$stmt = $p->query($this->dealPrefix('SELECT k,v FROM pre_config'));
		if($stmt === false) throw new \RuntimeException('Committed configuration read failed');
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}
	public function beginConfigurationTransaction(){
		if($this->db->inTransaction()) throw new \LogicException('Ambient configuration transaction');
		$this->configLock();
		try {
			if($this->beginTransaction() !== true) throw new \RuntimeException('Configuration transaction failed');
			$this->configEnlist();
			return true;
		} finally { $this->configUnlock(); }
	}
	public function configUnlock(){
		if($this->configLockDepth <= 0) throw new \LogicException('Configuration lock not owned');
		if(--$this->configLockDepth === 0){
			if((string)$this->getColumn('SELECT RELEASE_LOCK(:name)', [':name'=>$this->configLockName]) !== '1') throw new \RuntimeException('Configuration lock release failed');
		}
	}
	public function configEnlist(){
		if($this->db->inTransaction() && !$this->configTransactionLock){
			$this->configLock();
			$this->configTransactionLock = true;
		}
	}
	private function releaseConfigTransactionLock(){
		if($this->configTransactionLock){ $this->configTransactionLock = false; $this->configUnlock(); }
	}

	/**
	 * PdoHelper constructor.
	 *
	 * @param array $dbconfig 数据库信息
	 */
	function __construct($dbconfig)
	{
		$this->connectionConfig = $dbconfig;
		$this->prefix = $dbconfig['dbqz'].'_';
		try {
			$this->db = new \PDO("mysql:host={$dbconfig['host']};dbname={$dbconfig['dbname']};port={$dbconfig['port']};charset=utf8mb4",$dbconfig['user'],$dbconfig['pwd']);
		} catch (\Exception $e) {
			exit('链接数据库失败，请联系管理员');
		}
		$this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
		$this->db->exec("set sql_mode = ''");
		$this->db->exec("set names utf8mb4");
		$this->db->exec("set time_zone='+8:00'");
	}

	/**
	 * 设置结果集方式
	 *
	 * @param string $_style
	 */
	public function setFetchStyle($_style)
	{
		$this->fetchStyle = $_style;
	}

	/**
	 * 替换数据表前缀
	 * @param $_sql
	 *
	 * @return mixed
	 */
	private function dealPrefix($_sql){
		return str_replace($this->sqlPrefix,$this->prefix,$_sql);
	}

	private function _where($conditions){
		$result = array( "_where" => " ","_bindParams" => array());
		if(is_array($conditions) && !empty($conditions)){
			$fieldss = array(); $sql = null; $join = array();
			if(isset($conditions[0]) && $sql = $conditions[0]) unset($conditions[0]);
			foreach( $conditions as $key => $condition ){
				if(substr($key, 0, 1) != ":"){
					unset($conditions[$key]);
					$conditions[":".$key] = $condition;
				}
				$join[] = "`{$key}` = :{$key}";
			}
			if(!$sql) $sql = join(" AND ",$join);

			$result["_where"] = " WHERE ". $sql;
			$result["_bindParams"] = $conditions;
		}elseif(!empty($conditions)){
			$result["_where"] = " WHERE ". $conditions;
		}
		return $result;
	}

	private function _select($table, $fields = '*', $where = array(), $sort = null, $limit = null){
		$sort = !empty($sort) ? ' ORDER BY '.$sort : '';
		$fields = !empty($fields) ? $fields : '*';
		if(is_array($fields)){
			$fields = implode(',',$fields);
		}
		$conditions = $this->_where($where);

		$sql = ' FROM pre_'.$table.$conditions["_where"];
		if(is_array($limit)){
			$limit = ' LIMIT '.$limit[0].','.$limit[1];			
		}elseif(!empty($limit)){
			$limit = ' LIMIT '.$limit;
		}else{
			$limit = '';
		}
		return array('sql'=>'SELECT '. $fields . $sql . $sort . $limit, 'bind'=>$conditions["_bindParams"]);
	}

	/**
	 * 查询一条数据
	 * @param string $table
	 * @param string $fields
	 * @param array $where
	 * @param string $sort
	 * @param int $limit
	 *
	 * @return array
	 */
	public function find($table, $fields = '*', $where = array(), $sort = null, $limit = null){
		$sql_arr = $this->_select($table, $fields, $where, $sort, $limit);
		return $this->getRow($sql_arr['sql'], $sql_arr['bind']);
	}

	/**
	 * 查询全部数据
	 * @param string $table
	 * @param string $fields
	 * @param array $where
	 * @param string $sort
	 * @param int $limit
	 *
	 * @return array
	 */
	public function findAll($table, $fields = '*', $where = array(), $sort = null, $limit = null){
		$sql_arr = $this->_select($table, $fields, $where, $sort, $limit);
		return $this->getAll($sql_arr['sql'], $sql_arr['bind']);
	}

	/**
	 * 查询字段数据
	 * @param string $table
	 * @param string $fields
	 * @param array $where
	 * @param string $sort
	 *
	 * @return mixed
	 */
	public function findColumn($table, $fields, $where = array(), $sort = null){
		$sql_arr = $this->_select($table, $fields, $where, $sort, 1);
		return $this->getColumn($sql_arr['sql'], $sql_arr['bind']);
	}

	/**
	 * 插入数据
	 * @param string $table
	 * @param array $data
	 * @param bool $replace
	 *
	 * @return int
	 */
	public function insert($table, $data, $replace = false){
		$values = array();
		foreach ($data as $k=>$v){
			$keys[] = "`{$k}`";
			if ($v == 'NOW()' || $v == 'CURDATE()' || $v == 'CURTIME()') {
				$marks[] = $v;
			}elseif ($v === null || $v === false) {
				$marks[] = 'NULL';
			}else{
				$values[":".$k] = $v;
				$marks[] = ":".$k;
			}
		}
		$rowCount = $this->exec(($replace?"REPLACE":"INSERT")." INTO pre_".$table." (".implode(', ', $keys).") VALUES (".implode(', ', $marks).")", $values);
		if($rowCount){
			return $this->lastInsertId();
		}else{
			return false;
		}
	}

	/**
	 * 更新数据
	 * @param string $table
	 * @param array $data
	 * @param array $where
	 *
	 * @return int
	 */
	public function update($table, $data, $where){
		if(is_array($data) && !empty($data)){
			$values = array();
			foreach ($data as $k=>$v){
				if($v == 'NOW()' || $v == 'CURDATE()' || $v == 'CURTIME()'){
					$setstr[] = "`{$k}` = ".$v;
				}elseif($v === null || $v === false){
					$setstr[] = "`{$k}` = NULL";
				}else{
					$values[":M_UPDATE_".$k] = $v;
					$setstr[] = "`{$k}` = :M_UPDATE_".$k;
				}
			}
			$update = implode(', ', $setstr);
		}elseif(!empty($data)){
			$update = $data;
		}else{
			return false;
		}
		$conditions = $this->_where($where);
		$rowCount = $this->exec("UPDATE pre_".$table." SET ".$update.$conditions["_where"], $conditions["_bindParams"] + $values);
		return $rowCount;
	}

	/**
	 * 删除数据
	 * @param string $table
	 * @param array $where
	 *
	 * @return int
	 */
	public function delete($table, $where){
		$conditions = $this->_where($where);
		$rowCount = $this->exec("DELETE FROM pre_".$table.$conditions["_where"], $conditions["_bindParams"]);
		return $rowCount;
	}

	/**
	 * 统计行数
	 * @param string $table
	 * @param array $where
	 *
	 * @return int
	 */
	public function count($table, $where){
		$conditions = $this->_where($where);
		$count = $this->getColumn("SELECT COUNT(*) FROM pre_".$table.$conditions["_where"], $conditions["_bindParams"]);
		return $count;
	}


	/**
	 * 执行语句
	 * @param string $_sql
	 * @param array $_array
	 *
	 * @return int|bool
	 */
	public function exec($_sql, $_array = null)
	{
		// All wrapper config writers (exec/update/insert/delete), including legacy loops,
		// invalidate in the same transaction and hold the builder lock until commit.
		if(preg_match('/^\s*(?:REPLACE\s+INTO|INSERT(?:\s+IGNORE)?\s+INTO|UPDATE|DELETE\s+FROM)\s+`?pre_config`?\b/i', $_sql)){
			$this->configLock();
			$owned = false;
			try {
				if(!$this->db->inTransaction()){
					if($this->beginTransaction() !== true) throw new \RuntimeException('Configuration transaction failed');
					$owned = true;
				}
				$this->configEnlist();
				$result = $this->execRaw($_sql, $_array);
				if($result === false || $this->execRaw("UPDATE pre_cache SET v='' WHERE k='config'") === false) throw new \RuntimeException('Configuration write failed');
				if($owned && $this->commit() !== true) throw new \RuntimeException('Configuration commit failed');
				return $result;
			} catch(\Throwable $e){
				if($owned && $this->db->inTransaction()) $this->rollBack();
				if($owned) return false;
				throw $e;
			} finally { $this->configUnlock(); }
		}
		return $this->execRaw($_sql, $_array);
	}
	private function execRaw($_sql, $_array = null)
	{
		$_sql = $this->dealPrefix($_sql);
		if (is_array($_array)) {
			$stmt = $this->db->prepare($_sql);
			if($stmt) {
				$result = $stmt->execute($_array);
				if($result!==false){
					return $stmt->rowCount();
				}else{
					$this->errorInfo = $stmt->errorInfo();
					return false;
				}
			}else{
				$this->errorInfo = $this->db->errorInfo();
				return false;
			}
		} else {
			$result = $this->db->exec($_sql);
			if($result!==false){
				return $result;
			}else{
				$this->errorInfo = $this->db->errorInfo();
				return false;
			}
		}
	}

	/**
	 * 获取PDOStatement
	 * @param string $_sql
	 * @param array $_array
	 *
	 * @return \PDOStatement
	 */
	public function query($_sql, $_array = null)
	{
		if(preg_match('/\bpre_config\b/i', $_sql) && preg_match('/\bFOR\s+UPDATE\b/i', $_sql)){
			$this->configLock();
			try { $this->configEnlist(); }
			finally { $this->configUnlock(); }
		}
		$_sql = $this->dealPrefix($_sql);
		if (is_array($_array)) {
			$stmt = $this->db->prepare($_sql);
			if($stmt) {
				if($stmt->execute($_array)){
					return $stmt;
				}else{
					$this->errorInfo = $stmt->errorInfo();
					return false;
				}
			}else{
				$this->errorInfo = $this->db->errorInfo();
				return false;
			}
		} else {
			if($stmt = $this->db->query($_sql)){
				return $stmt;
			}else{
				$this->errorInfo = $this->db->errorInfo();
				return false;
			}
		}
	}

	/**
	 * 查询一条结果
	 *
	 * @param string $_sql string
	 * @param array $_array array
	 *
	 * @return mixed
	 */
	public function getRow($_sql, $_array = null)
	{
		$stmt = $this->query($_sql, $_array);
		if($stmt) {
			return $stmt->fetch($this->fetchStyle);
		}else{
			return false;
		}
	}

	/**
	 * 获取所有结果
	 *
	 * @param string $_sql
	 * @param array $_array
	 *
	 * @return array
	 */
	public function getAll($_sql, $_array = null)
	{
		$stmt = $this->query($_sql, $_array);
		if($stmt) {
			return $stmt->fetchAll($this->fetchStyle);
		}else{
			return false;
		}
	}

	/**
	 * 获取结果数
	 * @param string $_sql
	 * @param array $_array
	 *
	 * @return int
	 */
	public function getCount($_sql, $_array = null)
	{
		$stmt = $this->query($_sql, $_array);
		if($stmt) {
			return $stmt->rowCount();
		}else{
			return false;
		}
	}

	/**
	 * 获取一个字段值
	 * @param string $_sql
	 * @param array $_array
	 *
	 * @return int
	 */
	public function getColumn($_sql, $_array = null)
	{
		$stmt = $this->query($_sql, $_array);
		if($stmt) {
			return $stmt->fetchColumn();
		}else{
			return false;
		}
	}

	/**
	 * 返回最后插入行的ID
	 *
	 * @return int|\PDOStatement
	 */
	public function lastInsertId()
	{
		return $this->db->lastInsertId();
	}

	/**
	 * 返回错误信息
	 *
	 * @return string|\PDOStatement
	 */
	public function error()
	{
		$error = $this->errorInfo;
		if($error){
			return '['.$error[1].']'.$error[2];
		}else{
			return null;
		}
	}

	//开启事务
	public function beginTransaction()
	{
		return $this->db->beginTransaction();
	}

	//提交事务
	public function commit()
	{
		try { return $this->db->commit(); }
		finally { if(!$this->db->inTransaction()) $this->releaseConfigTransactionLock(); }
	}

	//回滚事务
	public function rollBack()
	{
		try { return $this->db->rollBack(); }
		finally { if(!$this->db->inTransaction()) $this->releaseConfigTransactionLock(); }
	}

	//事务
	public function transaction($action){
		if(!is_callable($action)) return false;
		if($this->db->inTransaction()) throw new \LogicException('Nested wrapper transaction');
		if($this->beginTransaction() !== true) throw new \RuntimeException('Transaction start failed');
		try {
			$result = $action($this);
			if($result === false){ $this->rollBack(); return false; }
			if($this->commit() !== true) throw new \RuntimeException('Transaction commit failed');
			return $result;
		} catch(\Throwable $e){
			if($this->db->inTransaction()) $this->rollBack();
			throw $e;
		}
	}

	function __get($name)
	{
		return $this->$name;
	}

	function __destruct()
	{
		$this->db = null;
	}


}