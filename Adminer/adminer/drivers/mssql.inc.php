<?php
/**
* @author Jakub Cernohuby
* @author Vladimir Stastka
* @author Jakub Vrana
*/

$drivers["mssql"] = "Microsoft SQL Server";

if (isset($_GET["mssql"])) {
	define("DRIVER", "mssql");
	if (extension_loaded("sqlsrv")) {
		class Min_DB {
			var $extension = "sqlsrv", $_link, $_result, $server_info, $affected_rows, $errno, $error;

			function _get_error() {
				$this->error = "";
				foreach (sqlsrv_errors() as $error) {
					$this->errno = $error["code"];
					$this->error .= "$error[message]\n";
				}
				$this->error = rtrim($this->error);
			}

			function connect($server, $username, $password) {
				global $adminer;
				$db = $adminer->database();
				if (false !== ($semicPos = mb_stripos($server, ';')) && false !== ($optionsPos = mb_stripos($server, 'Options={'))) {
				    $connection_info = json_decode(mb_substr($server, ($optionsPos + strlen('Options='))), true);
				    $server = mb_substr($server, 0, $semicPos);
				} else {
				    $connection_info = [];
				}
				if (! array_key_exists("CharacterSet", $connection_info)) {
				    $connection_info["CharacterSet"] = "UTF-8";
				}
				if ($username !== '' && $username !== null) {
				    $connection_info['UID'] = $username;
				}
				if ($password !== '' && $password !== null) {
				    // Escape closing curly braces in MS SQL password by a second brace
				    $connection_info['PWD'] = preg_replace('/([^}])}([^}])/', '\\1}}\\2', $password);;
				}
				if ($db != "") {
					$connection_info["Database"] = $db;
				}
				$this->_link = @sqlsrv_connect(preg_replace('~:~', ',', $server), $connection_info);
				if ($this->_link) {
					$info = sqlsrv_server_info($this->_link);
					$this->server_info = $info['SQLServerVersion'];
				} else {
					$this->_get_error();
				}
				return (bool) $this->_link;
			}

			function quote($string) {
				return "'" . str_replace("'", "''", $string) . "'";
			}

			function select_db($database) {
				return $this->query("USE " . idf_escape($database));
			}

			function query($query, $unbuffered = false) {
				$result = sqlsrv_query($this->_link, $query); //! , array(), ($unbuffered ? array() : array("Scrollable" => "keyset"))
				$this->error = "";
				if (!$result) {
					$this->_get_error();
					return false;
				}
				return $this->store_result($result);
			}

			function multi_query($query) {
				$this->_result = sqlsrv_query($this->_link, $query);
				$this->error = "";
				if (!$this->_result) {
					$this->_get_error();
					return false;
				}
				return true;
			}

			function store_result($result = null) {
				if (!$result) {
					$result = $this->_result;
				}
				if (!$result) {
					return false;
				}
				if (sqlsrv_field_metadata($result)) {
					return new Min_Result($result);
				}
				$this->affected_rows = sqlsrv_rows_affected($result);
				return true;
			}

			function next_result() {
				return $this->_result ? sqlsrv_next_result($this->_result) : null;
			}

			function result($query, $field = 0) {
				$result = $this->query($query);
				if (!is_object($result)) {
					return false;
				}
				$row = $result->fetch_row();
				return $row[$field];
			}
		}

		class Min_Result {
			var $_result, $_offset = 0, $_fields, $num_rows;

			function __construct($result) {
				$this->_result = $result;
				// $this->num_rows = sqlsrv_num_rows($result); // available only in scrollable results
			}

			function _convert($row) {
				foreach ((array) $row as $key => $val) {
					if (is_a($val, 'DateTime')) {
						$row[$key] = $val->format("Y-m-d H:i:s");
					}
					//! stream
				}
				return $row;
			}

			function fetch_assoc() {
				return $this->_convert(sqlsrv_fetch_array($this->_result, SQLSRV_FETCH_ASSOC));
			}

			function fetch_row() {
				return $this->_convert(sqlsrv_fetch_array($this->_result, SQLSRV_FETCH_NUMERIC));
			}

			function fetch_field() {
				if (!$this->_fields) {
					$this->_fields = sqlsrv_field_metadata($this->_result);
				}
				$field = $this->_fields[$this->_offset++];
				$return = new stdClass;
				$return->name = $field["Name"];
				$return->orgname = $field["Name"];
				$return->type = ($field["Type"] == 1 ? 254 : 0);
				return $return;
			}

			function seek($offset) {
				for ($i=0; $i < $offset; $i++) {
					sqlsrv_fetch($this->_result); // SQLSRV_SCROLL_ABSOLUTE added in sqlsrv 1.1
				}
			}

			function __destruct() {
			    if (is_resource($this->_result)) {
				    sqlsrv_free_stmt($this->_result);
			    }
			}
		}

	} elseif (extension_loaded("mssql")) {
		class Min_DB {
			var $extension = "MSSQL", $_link, $_result, $server_info, $affected_rows, $error;

			function connect($server, $username, $password) {
				$this->_link = @mssql_connect($server, $username, $password);
				if ($this->_link) {
					$result = $this->query("SELECT SERVERPROPERTY('ProductLevel'), SERVERPROPERTY('Edition')");
					if ($result) {
						$row = $result->fetch_row();
						$this->server_info = $this->result("sp_server_info 2", 2) . " [$row[0]] $row[1]";
					}
				} else {
					$this->error = mssql_get_last_message();
				}
				return (bool) $this->_link;
			}

			function quote($string) {
				return "'" . str_replace("'", "''", $string) . "'";
			}

			function select_db($database) {
				return mssql_select_db($database);
			}

			function query($query, $unbuffered = false) {
				$result = @mssql_query($query, $this->_link); //! $unbuffered
				$this->error = "";
				if (!$result) {
					$this->error = mssql_get_last_message();
					return false;
				}
				if ($result === true) {
					$this->affected_rows = mssql_rows_affected($this->_link);
					return true;
				}
				return new Min_Result($result);
			}

			function multi_query($query) {
				return $this->_result = $this->query($query);
			}

			function store_result() {
				return $this->_result;
			}

			function next_result() {
				return mssql_next_result($this->_result->_result);
			}

			function result($query, $field = 0) {
				$result = $this->query($query);
				if (!is_object($result)) {
					return false;
				}
				return mssql_result($result->_result, 0, $field);
			}
		}

		class Min_Result {
			var $_result, $_offset = 0, $_fields, $num_rows;

			function __construct($result) {
				$this->_result = $result;
				$this->num_rows = mssql_num_rows($result);
			}

			function fetch_assoc() {
				return mssql_fetch_assoc($this->_result);
			}

			function fetch_row() {
				return mssql_fetch_row($this->_result);
			}

			function num_rows() {
				return mssql_num_rows($this->_result);
			}

			function fetch_field() {
				$return = mssql_fetch_field($this->_result);
				$return->orgtable = $return->table;
				$return->orgname = $return->name;
				return $return;
			}

			function seek($offset) {
				mssql_data_seek($this->_result, $offset);
			}

			function __destruct() {
				mssql_free_result($this->_result);
			}
		}

	} elseif (extension_loaded("pdo_dblib")) {
		class Min_DB extends Min_PDO {
			var $extension = "PDO_DBLIB";

			function connect($server, $username, $password) {
				$this->dsn("dblib:charset=utf8;host=" . str_replace(":", ";unix_socket=", preg_replace('~:(\d)~', ';port=\1', $server)), $username, $password);
				return true;
			}

			function select_db($database) {
				// database selection is separated from the connection so dbname in DSN can't be used
				return $this->query("USE " . idf_escape($database));
			}
		}
	}


	class Min_Driver extends Min_SQL {

		function insertUpdate($table, $rows, $primary) {
			foreach ($rows as $set) {
				$update = array();
				$where = array();
				foreach ($set as $key => $val) {
					$update[] = "$key = $val";
					if (isset($primary[idf_unescape($key)])) {
						$where[] = "$key = $val";
					}
				}
				//! can use only one query for all rows
				if (!queries("MERGE " . table($table) . " USING (VALUES(" . implode(", ", $set) . ")) AS source (c" . implode(", c", range(1, count($set))) . ") ON " . implode(" AND ", $where) //! source, c1 - possible conflict
					. " WHEN MATCHED THEN UPDATE SET " . implode(", ", $update)
					. " WHEN NOT MATCHED THEN INSERT (" . implode(", ", array_keys($set)) . ") VALUES (" . implode(", ", $set) . ");" // ; is mandatory
				)) {
					return false;
				}
			}
			return true;
		}

		function begin() {
			return queries("BEGIN TRANSACTION");
		}

	}

	function idf_unescape_mssql($idf) {
	    if (mb_substr($idf, 0, 1) === '[' && mb_substr($idf, -1) === ']');
	    $idf = mb_substr($idf, 1, -1);
	    return str_replace("]]", "]", $idf);
	}

	function idf_escape($idf) {
		return "[" . str_replace("]", "]]", $idf) . "]";
	}

	function table($idf) {
		return ($_GET["ns"] != "" ? idf_escape($_GET["ns"]) . "." : "") . idf_escape($idf);
	}

	function connect() {
		global $adminer;
		$connection = new Min_DB;
		$credentials = $adminer->credentials();
		if ($connection->connect($credentials[0], $credentials[1], $credentials[2])) {
			return $connection;
		}
		return $connection->error;
	}

	function get_databases() {
		return get_vals("SELECT name FROM sys.databases WHERE name NOT IN ('master', 'tempdb', 'model', 'msdb')");
	}

	function limit($query, $where, $limit, $offset = 0, $separator = " ") {
		return ($limit !== null ? " TOP (" . ($limit + $offset) . ")" : "") . " $query$where"; // seek later
	}

	function limit1($table, $query, $where, $separator = "\n") {
		return limit($query, $where, 1, 0, $separator);
	}

	function db_collation($db, $collations) {
		global $connection;
		return $connection->result("SELECT collation_name FROM sys.databases WHERE name = " . q($db));
	}

	function engines() {
		return array();
	}

	function logged_user() {
		global $connection;
		return $connection->result("SELECT SUSER_NAME()");
	}

	function tables_list() {
		return get_key_vals("SELECT name, type_desc FROM sys.all_objects WHERE schema_id = SCHEMA_ID(" . q(get_schema()) . ") AND type IN ('S', 'U', 'V') ORDER BY name");
	}

	function count_tables($databases) {
		global $connection;
		$return = array();
		foreach ($databases as $db) {
			$connection->select_db($db);
			$return[$db] = $connection->result("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES");
		}
		return $return;
	}

	function table_status($name = "") {
		$return = array();
		foreach (get_rows("SELECT ao.name AS Name, ao.type_desc AS Engine, (SELECT value FROM fn_listextendedproperty(default, 'SCHEMA', schema_name(schema_id), 'TABLE', ao.name, null, null)) AS Comment FROM sys.all_objects AS ao WHERE schema_id = SCHEMA_ID(" . q(get_schema()) . ") AND type IN ('S', 'U', 'V') " . ($name != "" ? "AND name = " . q($name) : "ORDER BY name")) as $row) {
			if ($name != "") {
				return $row;
			}
			$return[$row["Name"]] = $row;
		}
		return $return;
	}

	function is_view($table_status) {
		return $table_status["Engine"] == "VIEW";
	}

	function fk_support($table_status) {
		return true;
	}

	function fields($table) {
		$comments = get_key_vals("SELECT objname, cast(value as varchar(max)) FROM fn_listextendedproperty('MS_DESCRIPTION', 'schema', " . q(get_schema()) . ", 'table', " . q($table) . ", 'column', NULL)");
		$return = array();
		foreach (get_rows("SELECT c.max_length, c.precision, c.scale, c.name, c.is_nullable, c.is_identity, c.collation_name, t.name type, CAST(d.definition as text) [default]
FROM sys.all_columns c
JOIN sys.all_objects o ON c.object_id = o.object_id
JOIN sys.types t ON c.user_type_id = t.user_type_id
LEFT JOIN sys.default_constraints d ON c.default_object_id = d.parent_column_id
WHERE o.schema_id = SCHEMA_ID(" . q(get_schema()) . ") AND o.type IN ('S', 'U', 'V') AND o.name = " . q($table)
		) as $row) {
			$type = $row["type"];
			$length = (preg_match("~char|binary~", $type) ? $row["max_length"] : ($type == "decimal" ? "$row[precision],$row[scale]" : ""));
			// nvarchar seems to return twice the length, that was specified on creation.
			if (($type === 'nvarchar' || $type === 'nchar' || $type === 'ntext') && is_numeric($length)) {
			    if ($length === -1) {
			        $length = 'max';
			    } else {
			        $length = $length / 2;
			    }
			}
			$return[$row["name"]] = array(
				"field" => $row["name"],
				"full_type" => $type . ($length ? "($length)" : ""),
				"type" => $type,
				"length" => $length,
				"default" => $row["default"],
				"null" => $row["is_nullable"],
				"auto_increment" => $row["is_identity"],
				"collation" => $row["collation_name"],
				"privileges" => array("insert" => 1, "select" => 1, "update" => 1),
				"primary" => $row["is_identity"], //! or indexes.is_primary_key
				"comment" => $comments[$row["name"]],
			);
		}
		return $return;
	}

	function indexes($table, $connection2 = null) {
		$return = array();
		// sp_statistics doesn't return information about primary key
		foreach (get_rows("SELECT i.name, key_ordinal, is_unique, is_primary_key, c.name AS column_name, is_descending_key
FROM sys.indexes i
INNER JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
INNER JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id
WHERE OBJECT_NAME(i.object_id) = " . q($table)
		, $connection2) as $row) {
			$name = $row["name"];
			$return[$name]["type"] = ($row["is_primary_key"] ? "PRIMARY" : ($row["is_unique"] ? "UNIQUE" : "INDEX"));
			$return[$name]["lengths"] = array();
			$return[$name]["columns"][$row["key_ordinal"]] = $row["column_name"];
			$return[$name]["descs"][$row["key_ordinal"]] = ($row["is_descending_key"] ? '1' : null);
		}
		return $return;
	}

	function view($name) {
		global $connection;
		$sql = "SELECT OBJECT_DEFINITION(OBJECT_ID(" . q((get_schema() ? get_schema() . '.' : '') . $name) . "))";
		return array("select" => preg_replace('~^(?:[^[]|\[[^]]*])*\s+AS\s+~isU', '', $connection->result($sql)));
	}

	function collations() {
		$return = array();
		foreach (get_vals("SELECT name FROM fn_helpcollations()") as $collation) {
			$return[preg_replace('~_.*~', '', $collation)][] = $collation;
		}
		return $return;
	}

	function information_schema($db) {
		return false;
	}

	function error() {
		global $connection;
		// This is a workaround for $connection not having the error probably due to decoupling from
		// the original instance in connect() method. In the minified version the connections is $g.
		global $g;
		$msg = $connection->error ? $connection->error : ($g ? $g->error : '');
		return nl_br(h(preg_replace('~^(\[[^]]*])+~m', '', $msg)));
	}

	function create_database($db, $collation) {
		return queries("CREATE DATABASE " . idf_escape($db) . (preg_match('~^[a-z0-9_]+$~i', $collation) ? " COLLATE $collation" : ""));
	}

	function drop_databases($databases) {
		return queries("DROP DATABASE " . implode(", ", array_map('idf_escape', $databases)));
	}

	function rename_database($name, $collation) {
		if (preg_match('~^[a-z0-9_]+$~i', $collation)) {
			queries("ALTER DATABASE " . idf_escape(DB) . " COLLATE $collation");
		}
		queries("ALTER DATABASE " . idf_escape(DB) . " MODIFY NAME = " . idf_escape($name));
		return true; //! false negative "The database name 'test2' has been set."
	}

	function auto_increment() {
		return " IDENTITY" . ($_POST["Auto_increment"] != "" ? "(" . number($_POST["Auto_increment"]) . ",1)" : "") . " PRIMARY KEY";
	}

	function alter_table($table, $name, $fields, $foreign, $comment, $engine, $collation, $auto_increment, $partitioning) {
		$alter = array();
		$comments = array();
		foreach ($fields as $field) {
			$column = idf_escape($field[0]);
			$val = $field[1];
			if (!$val) {
				$alter["DROP"][] = " COLUMN $column";
			} else {
				$val[1] = preg_replace("~( COLLATE )'(\\w+)'~", '\1\2', $val[1]);
				$comments[$field[0]] = $val[5];
				unset($val[5]);
				if ($field[0] == "") {
					$alter["ADD"][] = "\n  " . implode("", $val) . ($table == "" ? substr($foreign[$val[0]], 16 + strlen($val[0])) : ""); // 16 - strlen("  FOREIGN KEY ()")
				} else {
					unset($val[6]); //! identity can't be removed
					if ($column != $val[0]) {
					    queries("EXEC sp_rename " . q((get_schema() ? get_schema() . '.' : '') . "{$table}.{$field[0]}") . ", " . q(idf_unescape_mssql($val[0])) . ", 'COLUMN'");
					}
					$alter["ALTER COLUMN " . implode("", $val)][] = "";
				}
			}
		}
		if ($table == "") {
			return queries("CREATE TABLE " . table($name) . " (" . implode(",", (array) $alter["ADD"]) . "\n)");
		}
		if ($table != $name) {
		    $schema = get_schema();
		    queries("EXEC sp_rename " . q(table($table)) . ", " . q($name));
			// Make sure to rename the primary key of the table too - otherwise copying a table
			// multiple times (copy tbl1 > rename tbl1_copy > copy tbl1 again) will fail because
			// to copied constraint name already exists
			queries(<<<SQL
DECLARE @oldConstr NVARCHAR(max)
SELECT @oldConstr = CONCAT(CONSTRAINT_SCHEMA, '.', TABLE_NAME, '.', CONSTRAINT_NAME) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '{$schema}' AND TABLE_NAME = '{$name}' AND CONSTRAINT_TYPE = 'PRIMARY KEY'
IF @oldConstr IS NOT NULL
BEGIN
    EXEC sp_rename @oldConstr, 'PK_{$name}'
END
SQL);
		}
		if ($foreign) {
			$alter[""] = $foreign;
		}
		foreach ($alter as $key => $val) {
			if (!queries("ALTER TABLE " . table($name) . " $key" . implode(",", $val))) {
				return false;
			}
		}
		foreach ($comments as $key => $val) {
			$comment = substr($val, 9); // 9 - strlen(" COMMENT ")
			queries("EXEC sp_dropextendedproperty @name = N'MS_Description', @level0type = N'Schema', @level0name = " . q(get_schema()) . ", @level1type = N'Table', @level1name = " . q($name) . ", @level2type = N'Column', @level2name = " . q($key));
			queries("EXEC sp_addextendedproperty @name = N'MS_Description', @value = " . $comment . ", @level0type = N'Schema', @level0name = " . q(get_schema()) . ", @level1type = N'Table', @level1name = " . q($name) . ", @level2type = N'Column', @level2name = " . q($key));
		}
		return true;
	}

	function alter_indexes($table, $alter) {
		$index = array();
		$drop = array();
		foreach ($alter as $val) {
			if ($val[2] == "DROP") {
				if ($val[0] == "PRIMARY") { //! sometimes used also for UNIQUE
					$drop[] = idf_escape($val[1]);
				} else {
					$index[] = idf_escape($val[1]) . " ON " . table($table);
				}
			} elseif (!queries(($val[0] != "PRIMARY"
				? "CREATE $val[0] " . ($val[0] != "INDEX" ? "INDEX " : "") . idf_escape($val[1] != "" ? $val[1] : uniqid($table . "_")) . " ON " . table($table)
				: "ALTER TABLE " . table($table) . " ADD PRIMARY KEY"
			) . " (" . implode(", ", $val[2]) . ")")) {
				return false;
			}
		}
		return (!$index || queries("DROP INDEX " . implode(", ", $index)))
			&& (!$drop || queries("ALTER TABLE " . table($table) . " DROP " . implode(", ", $drop)))
		;
	}

	function last_id() {
		global $connection;
		return $connection->result("SELECT SCOPE_IDENTITY()"); // @@IDENTITY can return trigger INSERT
	}

	function explain($connection, $query) {
		$connection->query("SET SHOWPLAN_ALL ON");
		$return = $connection->query($query);
		$connection->query("SET SHOWPLAN_ALL OFF"); // connection is used also for indexes
		return $return;
	}

	function found_rows($table_status, $where) {
	}

	function foreign_keys($table) {
	    global $adminer;
	    $currentDB = $adminer->database();
	    $schema = get_schema();
	    if ($schema) {
	        $owner = ", @fktable_owner = '{$schema}'";
	    }
	    $return = array();
	    foreach (get_rows("EXEC sp_fkeys @fktable_name = " . q($table) . $owner) as $row) {
			$foreign_key = &$return[$row["FK_NAME"]];
			// Make sure to leave db empty if it is the current DB as this is required to draw
			// arrows in the DB schema diagram.
			$foreign_key["db"] = $row["PKTABLE_QUALIFIER"] !== $currentDB ? $row["PKTABLE_QUALIFIER"] : "";
			$foreign_key["table"] = $row["PKTABLE_NAME"];
			$foreign_key["source"][] = $row["FKCOLUMN_NAME"];
			$foreign_key["target"][] = $row["PKCOLUMN_NAME"];
		}
		return $return;
	}

	function truncate_tables($tables) {
		return apply_queries("TRUNCATE TABLE", $tables);
	}

	function drop_views($views) {
		return queries("DROP VIEW " . implode(", ", array_map('table', $views)));
	}

	function drop_tables($tables) {
		return queries("DROP TABLE " . implode(", ", array_map('table', $tables)));
	}

	function move_tables($tables, $views, $target) {
		return apply_queries("ALTER SCHEMA " . idf_escape($target) . " TRANSFER", array_merge($tables, $views));
	}
	
	/** 
	 * Copy tables and views to other schema
	 * 
	 * Tables will be copied without their data
	 * 
	 * @param array
	 * @param array
	 * @param string
	 * @return bool
	 */
	function copy_tables($tables, $views, $targetSchema) {
	    global $connection;
	    foreach ($tables as $srcTableName) {
	        $srcSchema = get_schema();
	        $targetTableName = ($targetSchema == $srcSchema ? "{$srcTableName}_copy" : $srcTableName);
	        $targetTableFull = ($targetSchema == $srcSchema ? table($targetTableName) : idf_escape($targetSchema) . "." . table($targetTableName));
	        if (
	            // DROP table with same name in target schema if overwrite was checked
	            ($_POST["overwrite"] && !queries("\nIF OBJECT_ID ('$srcTableName', N'U') IS NULL \nDROP TABLE $targetTableFull"))
                // Copy the table without data (via WHERE, which is always false). This will copy
                // IDENTITY columns, but no constraints, so the copied table won't have a primary key yet
	            || !queries("SELECT * INTO $targetTableFull FROM " . table($srcTableName) . " WHERE 1 = 0")
	            // Copy the primary key constraint
	            || !queries("DECLARE @pkey NVARCHAR(max);
SELECT @pkey = COLUMN_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE OBJECTPROPERTY(OBJECT_ID(CONSTRAINT_SCHEMA + '.' + QUOTENAME(CONSTRAINT_NAME)), 'IsPrimaryKey') = 1
        AND TABLE_NAME = '{$srcTableName}' 
        AND TABLE_SCHEMA = '{$srcSchema}';
IF @pkey IS NOT NULL
EXEC('ALTER TABLE {$targetTableFull} ADD CONSTRAINT PK_{$targetTableName} PRIMARY KEY CLUSTERED (' + @pkey + ')');
                ")
            ) {
                return false;
            }
	    }
	    if (! empty($views)) {
	        $connection->error = 'Cannot copy views in Microsoft SQL';
	        return false;
	    }
	    return true;
	}

	function trigger($name) {
		if ($name == "") {
			return array();
		}
		$rows = get_rows("SELECT s.name [Trigger],
CASE WHEN OBJECTPROPERTY(s.id, 'ExecIsInsertTrigger') = 1 THEN 'INSERT' WHEN OBJECTPROPERTY(s.id, 'ExecIsUpdateTrigger') = 1 THEN 'UPDATE' WHEN OBJECTPROPERTY(s.id, 'ExecIsDeleteTrigger') = 1 THEN 'DELETE' END [Event],
CASE WHEN OBJECTPROPERTY(s.id, 'ExecIsInsteadOfTrigger') = 1 THEN 'INSTEAD OF' ELSE 'AFTER' END [Timing],
c.text
FROM sysobjects s
JOIN syscomments c ON s.id = c.id
WHERE s.xtype = 'TR' AND s.name = " . q($name)
		); // triggers are not schema-scoped
		$return = reset($rows);
		if ($return) {
			$return["Statement"] = preg_replace('~^.+\s+AS\s+~isU', '', $return["text"]); //! identifiers, comments
		}
		return $return;
	}

	function triggers($table) {
		$return = array();
		foreach (get_rows("SELECT sys1.name,
CASE WHEN OBJECTPROPERTY(sys1.id, 'ExecIsInsertTrigger') = 1 THEN 'INSERT' WHEN OBJECTPROPERTY(sys1.id, 'ExecIsUpdateTrigger') = 1 THEN 'UPDATE' WHEN OBJECTPROPERTY(sys1.id, 'ExecIsDeleteTrigger') = 1 THEN 'DELETE' END [Event],
CASE WHEN OBJECTPROPERTY(sys1.id, 'ExecIsInsteadOfTrigger') = 1 THEN 'INSTEAD OF' ELSE 'AFTER' END [Timing]
FROM sysobjects sys1
JOIN sysobjects sys2 ON sys1.parent_obj = sys2.id
WHERE sys1.xtype = 'TR' AND sys2.name = " . q($table)
		) as $row) { // triggers are not schema-scoped
			$return[$row["name"]] = array($row["Timing"], $row["Event"]);
		}
		return $return;
	}

	function trigger_options() {
		return array(
			"Timing" => array("AFTER", "INSTEAD OF"),
			"Event" => array("INSERT", "UPDATE", "DELETE"),
			"Type" => array("AS"),
		);
	}

	function schemas() {
		return get_vals("SELECT name FROM sys.schemas");
	}

	function get_schema() {
		global $connection;
		if ($_GET["ns"] != "") {
			return $_GET["ns"];
		}
		return $connection->result("SELECT SCHEMA_NAME()");
	}

	function set_schema($schema) {
		return true; // ALTER USER is permanent
	}

	function use_sql($database) {
		return "USE " . idf_escape($database);
	}

	function show_variables() {
		return array();
	}

	function show_status() {
		return array();
	}
	
	/**
	 * Get SQL command to drop a table
	 * 
	 * @param string $table
	 * @param bool $if_not_exists
	 * @param bool $drop_constraints
	 * @return string
	 */
	function drop_table_sql($table, $if_not_exists = false, $drop_dependencies = false)
	{
	    $t = table($table);
	    $sql = $if_not_exists ? "IF OBJECT_ID ({$t}, N'U') IS NOT NULL\n" : '';
	    if (! $drop_dependencies) {
	       $sql .= "DROP TABLE $t";
	    } else {
	       $sql .= "
BEGIN
    DECLARE @table NVARCHAR(max) = 'YourTableName';
    DECLARE @schema NVARCHAR(max) = 'dbo';
    DECLARE @stmt NVARCHAR(max);
	-- STEP1: Remove foreign keys to this table
	-- Cursor to generate ALTER TABLE DROP CONSTRAINT statements  
	DECLARE cur CURSOR FOR
		SELECT 'ALTER TABLE ' + OBJECT_SCHEMA_NAME(parent_object_id) + '.' + OBJECT_NAME(parent_object_id) + ' DROP CONSTRAINT ' + name
		FROM sys.foreign_keys 
		WHERE OBJECT_SCHEMA_NAME(referenced_object_id) = @schema 
			AND OBJECT_NAME(referenced_object_id) = @table;
 
   OPEN cur;
   FETCH cur INTO @stmt;
	-- Drop each found foreign key constraint 
	WHILE @@FETCH_STATUS = 0
		BEGIN
			EXEC (@stmt);
			FETCH cur INTO @stmt;
		END
	CLOSE cur;
	DEALLOCATE cur;
	
	-- STEP2: remove constraints inside this table
	SELECT @stmt = '';
	SELECT @stmt += N'
ALTER TABLE ' + OBJECT_NAME(parent_object_id) + ' DROP CONSTRAINT ' + OBJECT_NAME(object_id) + ';' 
	FROM SYS.OBJECTS
	WHERE TYPE_DESC LIKE '%CONSTRAINT' AND OBJECT_NAME(parent_object_id) = @table AND SCHEMA_NAME(schema_id) = @schema;
	EXEC(@stmt);

	-- FINALLY drop the table itself
	DROP TABLE CONCAT(@schema, '.', @table);
END
";
	    }
	    return $sql;
	}
	
	/**
	 * Get SQL command to drop a view
	 * 
	 * @param string $table
	 * @param boolean $if_not_exists
	 * @return string
	 */
	function drop_view_sql($table, $if_not_exists = false)
	{
	    $t = table($table);
	    $sql = $if_not_exists ? "IF OBJECT_ID ({$t}, N'V') IS NOT NULL\n" : '';
	    $sql .= "DROP VIEW $t";
	    return $sql;
	}
	
	/** 
	 * Get SQL command to create table
	 * 
	 * In the case of MS SQL there is no built-in function to do this, so we use an SQL script from here
	 * https://stackoverflow.com/questions/706664/generate-sql-create-scripts-for-existing-tables-with-query.
	 * 
     * @param string
     * @param bool
     * @param string
     * @return string
	 */
	function create_sql($table, $auto_increment, $style) {
	    global $connection;
	    $sql = "

DECLARE @table_name SYSNAME
SELECT @table_name = 'dbo.Abruf'

DECLARE 
      @object_name SYSNAME
    , @object_id INT

SELECT 
      @object_name = '[' + s.name + '].[' + o.name + ']'
    , @object_id = o.[object_id]
FROM sys.objects o WITH (NOWAIT)
JOIN sys.schemas s WITH (NOWAIT) ON o.[schema_id] = s.[schema_id]
WHERE s.name + '.' + o.name = @table_name
    AND o.[type] = 'U'
    AND o.is_ms_shipped = 0

DECLARE @SQL NVARCHAR(MAX) = ''

;WITH index_column AS 
(
    SELECT 
          ic.[object_id]
        , ic.index_id
        , ic.is_descending_key
        , ic.is_included_column
        , c.name
    FROM sys.index_columns ic WITH (NOWAIT)
    JOIN sys.columns c WITH (NOWAIT) ON ic.[object_id] = c.[object_id] AND ic.column_id = c.column_id
    WHERE ic.[object_id] = @object_id
),
fk_columns AS 
(
     SELECT 
          k.constraint_object_id
        , cname = c.name
        , rcname = rc.name
    FROM sys.foreign_key_columns k WITH (NOWAIT)
    JOIN sys.columns rc WITH (NOWAIT) ON rc.[object_id] = k.referenced_object_id AND rc.column_id = k.referenced_column_id 
    JOIN sys.columns c WITH (NOWAIT) ON c.[object_id] = k.parent_object_id AND c.column_id = k.parent_column_id
    WHERE k.parent_object_id = @object_id
)
SELECT @SQL = 'CREATE TABLE ' + @object_name + CHAR(13) + '(' + CHAR(13) + STUFF((
    SELECT CHAR(9) + ', [' + c.name + '] ' + 
        CASE WHEN c.is_computed = 1
            THEN 'AS ' + cc.[definition] 
            ELSE UPPER(tp.name) + 
                CASE WHEN tp.name IN ('varchar', 'char', 'varbinary', 'binary', 'text')
                       THEN '(' + CASE WHEN c.max_length = -1 THEN 'MAX' ELSE CAST(c.max_length AS VARCHAR(5)) END + ')'
                     WHEN tp.name IN ('nvarchar', 'nchar', 'ntext')
                       THEN '(' + CASE WHEN c.max_length = -1 THEN 'MAX' ELSE CAST(c.max_length / 2 AS VARCHAR(5)) END + ')'
                     WHEN tp.name IN ('datetime2', 'time2', 'datetimeoffset') 
                       THEN '(' + CAST(c.scale AS VARCHAR(5)) + ')'
                    WHEN tp.name IN ('decimal', 'numeric')
                       THEN '(' + CAST(c.[precision] AS VARCHAR(5)) + ',' + CAST(c.scale AS VARCHAR(5)) + ')'
                    ELSE ''
                END +
                CASE WHEN c.collation_name IS NOT NULL THEN ' COLLATE ' + c.collation_name ELSE '' END +
                CASE WHEN c.is_nullable = 1 THEN ' NULL' ELSE ' NOT NULL' END +
                CASE WHEN dc.[definition] IS NOT NULL THEN ' DEFAULT' + dc.[definition] ELSE '' END + 
                CASE WHEN ic.is_identity = 1 THEN ' IDENTITY(' + CAST(ISNULL(ic.seed_value, '0') AS CHAR(1)) + ',' + CAST(ISNULL(ic.increment_value, '1') AS CHAR(1)) + ')' ELSE '' END 
        END + CHAR(13)
    FROM sys.columns c WITH (NOWAIT)
    JOIN sys.types tp WITH (NOWAIT) ON c.user_type_id = tp.user_type_id
    LEFT JOIN sys.computed_columns cc WITH (NOWAIT) ON c.[object_id] = cc.[object_id] AND c.column_id = cc.column_id
    LEFT JOIN sys.default_constraints dc WITH (NOWAIT) ON c.default_object_id != 0 AND c.[object_id] = dc.parent_object_id AND c.column_id = dc.parent_column_id
    LEFT JOIN sys.identity_columns ic WITH (NOWAIT) ON c.is_identity = 1 AND c.[object_id] = ic.[object_id] AND c.column_id = ic.column_id
    WHERE c.[object_id] = @object_id
    ORDER BY c.column_id
    FOR XML PATH(''), TYPE).value('.', 'NVARCHAR(MAX)'), 1, 2, CHAR(9) + ' ')
    + ISNULL((SELECT CHAR(9) + ', CONSTRAINT [' + k.name + '] PRIMARY KEY (' + 
                    (SELECT STUFF((
                         SELECT ', [' + c.name + '] ' + CASE WHEN ic.is_descending_key = 1 THEN 'DESC' ELSE 'ASC' END
                         FROM sys.index_columns ic WITH (NOWAIT)
                         JOIN sys.columns c WITH (NOWAIT) ON c.[object_id] = ic.[object_id] AND c.column_id = ic.column_id
                         WHERE ic.is_included_column = 0
                             AND ic.[object_id] = k.parent_object_id 
                             AND ic.index_id = k.unique_index_id     
                         FOR XML PATH(N''), TYPE).value('.', 'NVARCHAR(MAX)'), 1, 2, ''))
            + ')' + CHAR(13)
            FROM sys.key_constraints k WITH (NOWAIT)
            WHERE k.parent_object_id = @object_id 
                AND k.[type] = 'PK'), '') + ')'  + CHAR(13)
    + ISNULL((SELECT (
        SELECT CHAR(13) +
             'ALTER TABLE ' + @object_name + ' WITH' 
            + CASE WHEN fk.is_not_trusted = 1 
                THEN ' NOCHECK' 
                ELSE ' CHECK' 
              END + 
              ' ADD CONSTRAINT [' + fk.name  + '] FOREIGN KEY(' 
              + STUFF((
                SELECT ', [' + k.cname + ']'
                FROM fk_columns k
                WHERE k.constraint_object_id = fk.[object_id]
                FOR XML PATH(''), TYPE).value('.', 'NVARCHAR(MAX)'), 1, 2, '')
               + ')' +
              ' REFERENCES [' + SCHEMA_NAME(ro.[schema_id]) + '].[' + ro.name + '] ('
              + STUFF((
                SELECT ', [' + k.rcname + ']'
                FROM fk_columns k
                WHERE k.constraint_object_id = fk.[object_id]
                FOR XML PATH(''), TYPE).value('.', 'NVARCHAR(MAX)'), 1, 2, '')
               + ')'
            + CASE 
                WHEN fk.delete_referential_action = 1 THEN ' ON DELETE CASCADE' 
                WHEN fk.delete_referential_action = 2 THEN ' ON DELETE SET NULL'
                WHEN fk.delete_referential_action = 3 THEN ' ON DELETE SET DEFAULT' 
                ELSE '' 
              END
            + CASE 
                WHEN fk.update_referential_action = 1 THEN ' ON UPDATE CASCADE'
                WHEN fk.update_referential_action = 2 THEN ' ON UPDATE SET NULL'
                WHEN fk.update_referential_action = 3 THEN ' ON UPDATE SET DEFAULT'  
                ELSE '' 
              END 
            + CHAR(13) + 'ALTER TABLE ' + @object_name + ' CHECK CONSTRAINT [' + fk.name  + ']' + CHAR(13)
        FROM sys.foreign_keys fk WITH (NOWAIT)
        JOIN sys.objects ro WITH (NOWAIT) ON ro.[object_id] = fk.referenced_object_id
        WHERE fk.parent_object_id = @object_id
        FOR XML PATH(N''), TYPE).value('.', 'NVARCHAR(MAX)')), '')
    + ISNULL(((SELECT
         CHAR(13) + 'CREATE' + CASE WHEN i.is_unique = 1 THEN ' UNIQUE' ELSE '' END 
                + ' NONCLUSTERED INDEX [' + i.name + '] ON ' + @object_name + ' (' +
                STUFF((
                SELECT ', [' + c.name + ']' + CASE WHEN c.is_descending_key = 1 THEN ' DESC' ELSE ' ASC' END
                FROM index_column c
                WHERE c.is_included_column = 0
                    AND c.index_id = i.index_id
                FOR XML PATH(''), TYPE).value('.', 'NVARCHAR(MAX)'), 1, 2, '') + ')'  
                + ISNULL(CHAR(13) + 'INCLUDE (' + 
                    STUFF((
                    SELECT ', [' + c.name + ']'
                    FROM index_column c
                    WHERE c.is_included_column = 1
                        AND c.index_id = i.index_id
                    FOR XML PATH(''), TYPE).value('.', 'NVARCHAR(MAX)'), 1, 2, '') + ')', '')  + CHAR(13)
        FROM sys.indexes i WITH (NOWAIT)
        WHERE i.[object_id] = @object_id
            AND i.is_primary_key = 0
            AND i.[type] = 2
        FOR XML PATH(''), TYPE).value('.', 'NVARCHAR(MAX)')
    ), '')

SELECT @SQL
";
	    // Since these are multiple statements, use a multi-query and iterate through the results
	    // ultimately using the return value of the last result (the `SELECT @SQL`).
	    $connection->multi_query($sql);
	    do {
	        $result = $connection->store_result();
	        $arr = $result->fetch_row();
	    } while ($connection->next_result());
	    
	    /* TODO remove auto-increments here? Where are they in MS SQL anyway?
	     * This is what was done in mysql.inc.php
	    if (!$auto_increment) {
	        $return = preg_replace('~ AUTO_INCREMENT=\d+~', '', $return); //! skip comments
	    }*/
	    return $arr[0];
	}
	
	/** Get SQL commands to create triggers
	 * @param string
	 * @return string
	 */
	function trigger_sql($table) {
	    /* TODO
	    $return = "";
	    foreach (get_rows("SHOW TRIGGERS LIKE " . q(addcslashes($table, "%_\\")), null, "-- ") as $row) {
	        $return .= "\nCREATE TRIGGER " . idf_escape($row["Trigger"]) . " $row[Timing] $row[Event] ON " . table($row["Table"]) . " FOR EACH ROW\n$row[Statement];;\n";
	    }
	    return $return;
	    */
	    return '';
	}
	
	/**
	 * Use named foreign key constraints here to simplify using DDL statements in migrations scripts
	 * 
	 * @see editing.inc.php::format_foreign_key()
	 * 
	 * @param array $foreign_key
	 * @return string
	 */
	function format_foreign_key($foreign_key) {
	    global $on_actions;
	    $db = $foreign_key["db"];
	    $ns = $foreign_key["ns"];
	    $fkName = 'FK_' . $foreign_key["source_table"] . '_' . $foreign_key["table"] . '_' . implode('_', $foreign_key["source"]);
	    return " CONSTRAINT " . $fkName . " FOREIGN KEY (" . implode(", ", array_map('idf_escape', $foreign_key["source"])) . ") REFERENCES "
	        . ($db != "" && $db != $_GET["db"] ? idf_escape($db) . "." : "")
	        . ($ns != "" && $ns != $_GET["ns"] ? idf_escape($ns) . "." : "")
	        . table($foreign_key["table"])
	        . " (" . implode(", ", array_map('idf_escape', $foreign_key["target"])) . ")" //! reuse $name - check in older MySQL versions
	        . (preg_match("~^($on_actions)\$~", $foreign_key["on_delete"]) ? " ON DELETE $foreign_key[on_delete]" : "")
	        . (preg_match("~^($on_actions)\$~", $foreign_key["on_update"]) ? " ON UPDATE $foreign_key[on_update]" : "")
	        ;
	}
	
	function convert_field($field) {
	    if (preg_match("~binary~", $field["type"])) {
	        return "LOWER(CONVERT(VARCHAR(max), " . idf_escape($field["field"]) . ", 1))";
	    }
	}
	
	/** Convert value in edit after applying functions back
	 * @param array one element from fields()
	 * @param string
	 * @return string
	 */
	function unconvert_field($field, $return) {
	    if (preg_match("~binary~", $field["type"])) {
	        if (strcasecmp(mb_substr($return, 0, 2), '0x') === 0) {
	            return $return;
	        } else {
	            // TODO How to UNHEX() in MS SQL?
	            // $return = "UNHEX($return)";
	        }
	    }
	    return $return;
	}

	function support($feature) {
		return preg_match('~^(comment|columns|database|drop_col|indexes|descidx|scheme|sql|table|copy|trigger|view|view_trigger|dump)$~', $feature); //! routine|
	}

	function driver_config() {
		$types = array();
		$structured_types = array();
		foreach (array( //! use sys.types
			lang('Numbers') => array("tinyint" => 3, "smallint" => 5, "int" => 10, "bigint" => 20, "bit" => 1, "decimal" => 0, "real" => 12, "float" => 53, "smallmoney" => 10, "money" => 20),
			lang('Date and time') => array("date" => 10, "smalldatetime" => 19, "datetime" => 19, "datetime2" => 19, "time" => 8, "datetimeoffset" => 10),
			lang('Strings') => array("char" => 8000, "varchar" => 8000, "text" => 2147483647, "nchar" => 4000, "nvarchar" => 4000, "nvarchar(max)" => "max", "ntext" => 1073741823),
			lang('Binary') => array("binary" => 8000, "varbinary" => 8000, "image" => 2147483647),
		) as $key => $val) {
			$types += $val;
			$structured_types[$key] = array_keys($val);
		}
		return array(
			'possible_drivers' => array("SQLSRV", "MSSQL", "PDO_DBLIB"),
			'jush' => "mssql",
			'types' => $types,
			'structured_types' => $structured_types,
			'unsigned' => array(),
			'operators' => array("=", "<", ">", "<=", ">=", "!=", "LIKE", "LIKE %%", "IN", "IS NULL", "NOT LIKE", "NOT IN", "IS NOT NULL"),
			'functions' => array("len", "lower", "round", "upper"),
			'grouping' => array("avg", "count", "count distinct", "max", "min", "sum"),
			'edit_functions' => array(
				array(
					"date|time" => "getdate",
				), array(
					"int|decimal|real|float|money|datetime" => "+/-",
					"char|text" => "+",
				)
			),
		);
	}
}
