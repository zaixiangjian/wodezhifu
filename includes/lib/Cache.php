<?php
namespace lib;

class Cache {
	public function get($key) {
		global $_CACHE;
		return $_CACHE[$key];
	}
	public function read($key = 'config') {
		global $DB;
		$value = $DB->getColumn("SELECT v FROM pre_cache WHERE k=:key LIMIT 1", [':key'=>$key]);
		return $value;
	}
	public function save($key ,$value, $expire=0) {
		// Never publish a caller's stale config snapshot. Re-read under the shared lock.
		if($key === 'config'){
			global $DB;
			$DB->configLock();
			$DB->configEnlist();
			try {
				$value = $this->configRows();
				return $this->saveValue($key, $value, $expire);
			} finally { $DB->configUnlock(); }
		}
		return $this->saveValue($key, $value, $expire);
	}
	private function saveValue($key, $value, $expire=0){
		if (is_array($value)) $value = serialize($value);
		global $DB;
		if($expire) $expire = time() + $expire;
		return $DB->exec("REPLACE INTO pre_cache VALUES (:key, :value, :expire)", [':key'=>$key, ':value'=>$value, ':expire'=>$expire]);
	}
	public function pre_fetch(){
		global $_CACHE;
		$_CACHE=array();
		global $DB;
		if($DB->db->inTransaction()) return $_CACHE = $this->update();
		$cache = $this->read('config');
		$_CACHE = @unserialize($cache, ['allowed_classes'=>false]);
		if(empty($_CACHE['version']))$_CACHE = $this->update();
		return $_CACHE;
	}
	private function configRows(){
		global $DB;
		// Current read, also when called inside the writer's owning transaction.
		$result = $DB->getAll("SELECT * FROM pre_config LOCK IN SHARE MODE");
		if($result === false) throw new \RuntimeException('Configuration read failed');
		$cache = array();
		foreach($result as $row) $cache[$row['k']] = $row['v'];
		return $cache;
	}
	public function update() {
		global $DB;
		// Contention is not an excuse to use an old cache: return one committed
		// DB snapshot on a fresh connection, without publishing it.
		try { $DB->configLock(0); }
		catch(\RuntimeException $e){
			$cache = array();
			foreach($DB->committedConfigRows() as $row) $cache[$row['k']] = $row['v'];
			return $cache;
		}
		try {
			$DB->configEnlist();
			$cache = $this->configRows();
			if($this->saveValue('config', $cache) === false) throw new \RuntimeException('Configuration cache write failed');
			return $cache;
		} finally { $DB->configUnlock(); }
	}
	public function clear($key = 'config') {
		global $DB;
		if($key !== 'config') return $DB->exec("UPDATE pre_cache SET v='' WHERE k=:key", [':key'=>$key]);
		$DB->configLock();
			$DB->configEnlist();
		try { return $DB->exec("UPDATE pre_cache SET v='' WHERE k=:key", [':key'=>$key]); }
		finally { $DB->configUnlock(); }
	}
	public function delete($key) {
		global $DB;
		if($key !== 'config') return $DB->exec("DELETE FROM pre_cache WHERE k=:key", [':key'=>$key]);
		$DB->configLock();
			$DB->configEnlist();
		try { return $DB->exec("DELETE FROM pre_cache WHERE k=:key", [':key'=>$key]); }
		finally { $DB->configUnlock(); }
	}
	public function clean() {
		global $DB;
		$DB->exec("DELETE FROM pre_cache WHERE expire>0 AND expire<'".time()."'");
		//$DB->exec("OPTIMIZE TABLE pre_cache");
		return true;
	}
}
