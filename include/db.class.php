<?php
if(!defined('CORE_ROOT')) exit;
class dbstuff {
	var $querynum = 0;
	var $queries = array();
	var $version = '';
	var $charset = '';
	var $dbhost = '';
	var $dbuser = '';
	var $dbpw = '';
	var $dbname = '';
	var $connect;
	var $dbexist = 1;

	function fetch_array($query) {
		$array = $this->_fetch_array($query);
		return $array;
	}

	function query($sql, $ignoresafe = 0) {
		global $ifdebug, $slowquery, $thetime;
		if(!empty($ifdebug)) $start = akmicrotime();
		if(empty($ignoresafe)) {
			if(preg_match('/^(select|delete)\s+.*?(select|update|detele|insert|replace)\s+/i', $sql, $match)) {
				eventlog("REJECT\t".$sql, 'sql');
				return false;
			}
		}
		if($ignoresafe == 1 && strpos($sql, ";\n") != false) {
			$sqls = explode(";\n", $sql);
			foreach($sqls as $sql) {
				if(trim($sql) != "") $query = $this->_query($sql);
			}
		} else {
			$query = $this->_query($sql);
		}
		if(!$query) $this->halt($this->error(), $sql);
		if(!empty($ifdebug)) {
			$usedtime = akmicrotime() - $start;
			if(empty($slowquery) || $usedtime > $slowquery) {
				eventlog(msformat(akmicrotime() - $start)."\t".$sql, 'sql');
			}
		}
		if($sql != 'BEGIN;') {
			$this->querynum++;
			if(!empty($ifdebug)) $sql = msformat(akmicrotime() - $start).' '.$sql;
			if($this->querynum < 1000) $this->queries[] = $sql;
		}
		return $query;
	}
	
	function commit() {
		$this->_commit();
	}

	function querytoarray($sql, $key = '', $num = 1000) {
		global $sqlcaches;
		$results = array();
		if(!empty($GLOBALS['batchcreateitemflag'])) {
			if(!isset($sqlcaches)) $sqlcaches = array();
			$cachekey = $sql.'*'.$num;
			if(isset($sqlcaches[$cachekey])) {
			}
		}
		$query = $this->query($sql);
		$i = 1;
		while($row = $this->fetch_array($query)) {
			if($key == '' || !isset($row[$key])) {
				$results[$i] = $row;
			} else {
				$results[$row[$key]] = $row;
			}
			if($i ++ >= $num) break;
		}
		return $results;
	}

	function close() {
		$this->_close();
	}

	function get_by($what, $from, $where = '', $orderby = '') {
		$table = $this->fulltablename($from);
		$sql = "SELECT {$what} FROM `{$table}`";
		if($where != '') $sql .= " WHERE {$where}";
		if($orderby != '') $sql .= " ORDER BY {$orderby}";
		if(strpos($what, '(') === false) $sql .= " LIMIT 1";
		$row = $this->get_one($sql);
		if($row === false) {
			return false;
		} elseif(count($row) == 1) {
			return current($row);
		} else {
			return $row;
		}
	}
	
	function list_by($what, $from, $where = '', $orderby = '', $limit = '') {
		$table = $this->fulltablename($from);
		$sql = "SELECT {$what} FROM `{$table}`";
		if($where != '') $sql .= " WHERE {$where}";
		if($orderby != '') $sql .= " ORDER BY {$orderby}";
		if(!empty($limit)) $sql .= " LIMIT {$limit}";
		return $this->querytoarray($sql, 'id');
	}

	function query_by($what, $from, $where = '', $orderby = '', $limit = '') {
		$table = $this->fulltablename($from);
		$sql = "SELECT {$what} FROM `{$table}`";
		if($where != '') $sql .= " WHERE {$where}";
		if($orderby != '') $sql .= " ORDER BY {$orderby}";
		if(!empty($limit)) $sql .= " LIMIT {$limit}";
		return $this->query($sql);
	}

	function insert($table, $values) {
		$table = $this->fulltablename($table);
		$sql = "INSERT INTO `{$table}`";
		$keysql = '';
		$valuesql = '';
		foreach($values as $key => $value) {
			$keysql .= "`$key`,";
			$valuesql .= "'".$this->addslashes($value)."',";
		}
		$sql = $sql.'('.substr($keysql, 0, -1).')VALUES('.substr($valuesql, 0, -1).')';
		if(file_exists(actionhookfile('db_insert'))) include(actionhookfile('db_insert'));
		return $this->query($sql);
	}

	function update($table, $values, $where) {
		$table = $this->fulltablename($table);
		$sql = "UPDATE `{$table}` SET ";
		$keysql = '';
		$valuesql = '';
		foreach($values as $k => $v) {
			if(substr($v, 0, 1) == '+' && a_is_int(substr($v, 1))) {
				$sql .= "`$k`={$k}{$v},";
			} else {
				$sql .= "`$k`='".$this->addslashes($v)."',";
			}
		}
		$sql = substr($sql, 0, -1);
		$sql .= " WHERE {$where}";
		if(file_exists(actionhookfile('db_update'))) include(actionhookfile('db_update'));
		return $this->query($sql);
	}
	
	function replaceinto($table, $values, $uniquefields) {
		$uniquefields = explode(',', $uniquefields);
		$where = '1';
		foreach($uniquefields as $f) {
			$where .= " AND `$f`='".$this->addslashes($values[$f])."'";
		}
		$row = $this->get_by('*', $table, $where);
		if($row === false) {
			$this->insert($table, $values);
		} else {
			$this->update($table, $values, $where);
		}
	}

	function delete($table, $where = '') {
		$table = $this->fulltablename($table);
		$sql = "DELETE FROM `{$table}`";
		if($where != '') $sql .= " WHERE {$where}";
		if(file_exists(actionhookfile('db_delete'))) include(actionhookfile('db_delete'));
		return $this->query($sql);
	}

	function get_one($sql) {
		$arr = $this->querytoarray($sql, '', 1);
		if(count($arr) > 0) return array_shift($arr);
		return false;
	}

	function get_field($sql) {
		$arr = $this->get_one($sql);
		if(!empty($arr)) {
			return current($arr);
		} else {
			return '';
		}
	}

	function getalltables() {
		$tables = array();
		$sql = "SHOW TABLES";
		$query = $this->query($sql);
		$tables = array();
		while($table = $this->fetch_array($query)) {
			$tables[] = current($table);
		}
		return $tables;
	}

	function halt($error, $sql) {
		$error = $this->error($this->connect);
		if(fixhalt($sql.':'.$error)) {
			eventlog($sql.':'.$error.':fixed!', 'sqlhalt');
			aexit('<a href="http://www.akhtm.com/manual/sql-error.htm?source=ak" target="_blank">An SQL error occurred and automatically fixed! Please refresh!</a>');
		} else {
			eventlog($sql.':'.$error, 'sqlhalt');
			aexit('<a href="http://www.akhtm.com/manual/sql-error.htm?source=ak" target="_blank">An SQL error occurred! You can find detail information in logs.</a>');
		}
	}
	
	function getcreatetable($table) {
		$sql = "SHOW CREATE TABLE `{$table}`";
		$result = current($this->querytoarray($sql));
		return $result['Create Table'];
	}

	function fulltablename($table) {
		global $tablepre;
		if(in_array($table, array('admins', 'attachments', 'categories', 'comments', 'filenames', 'item_exts', 'items', 'modules', 'sections', 'settings', 'texts', 'variables', 'sessions', 'filters', 'keys', 'apps'))) {
			return $tablepre.'_'.$table;
		} else {
			if(substr($table, 0, 1) == '*') return $tablepre.'_'.substr($table, 1);
		}
		return $table;
	}
	function gettableinfo($table) {
		$return = array();
		$_query = $this->query("SHOW CREATE TABLE `$table`");
		$r = $this->fetch_array($_query);
		$sqls = explode("\n", $r['Create Table']);
		foreach($sqls as $sql) {
			$sql = trim($sql);
			if(substr($sql, 0, 1) == '`') {
				$_f = getfield('`', '`', $sql);
				$offset1 = strlen($_f) + 3;
				$offset2 = strpos($sql, '(', $offset1);
				if(empty($offset2)) $offset2 = strpos($sql, ' ', $offset1);
				if(empty($offset2)) $offset2 = strpos($sql, ',', $offset1);
				$type = substr($sql, $offset1, $offset2 - $offset1);
				$return['fields'][$_f]['type'] = $type;
				$length = getfield('(', ')', $sql);
				if(!empty($length)) $return['fields'][$_f]['length'] = $length;
				if(strpos($sql, 'auto_increment')) $return['fields'][$_f]['auto_increment'] = 1;
				if(strpos($sql, 'unsigned')) $return['fields'][$_f]['unsigned'] = 1;
			}
			unset($keytype);
			if(substr($sql, 0, 10) == 'UNIQUE KEY') {
				$keytype = 'unique';
			} elseif(substr($sql, 0, 3) == 'KEY') {
				$keytype = 'key';
			} elseif(substr($sql, 0, 11) == 'PRIMARY KEY') {
				$keytype = 'primary';
			}
			if(!empty($keytype)) {
				$_k = getfield('`', '`', $sql);
				$return['indexs'][$_k]['type'] = $keytype;
				if($keytype != 'primary') {
					$_v = getfield('(', ')', $sql);
					$_v = str_replace('`', '', $_v);
					$_v = tidyitemlist($_v, ',', 0);
					$_vs = explode(',', $_v);
					$return['indexs'][$_k]['value'] = $_vs;
				}
			}
			if(substr($sql, 0, 1) == ')') {
				$engine = getfield('ENGINE=', ' ', $sql);
				$charset = getfield('CHARSET=', '', $sql);
				if($engine == 'MEMORY') $return['engine'] = 'memory';
				$return['charset'] = $charset;
			}
		}
		return $return;
	}
}
?>
