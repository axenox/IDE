<?php
class AdminerForeignKeys {
	function head() {
		?>
		<script<?php echo nonce(); ?>>
			document.addEventListener("DOMContentLoaded", function()
			{
				collapsable = document.getElementsByClassName('collapsable')

				for (item of collapsable) {
					item.addEventListener('click', function () {
						moreDiv = this.parentElement.getElementsByClassName('fk-more')[0]

						if (moreDiv.classList.contains('hidden')) {
							moreDiv.classList.remove('hidden')
							this.innerHTML = " [<a>&#x25B2;</a>]"
						} else {
							moreDiv.classList.add('hidden')
							this.innerHTML = " [<a>&#x25BC;</a>]"
						}

					})
				}
			})
		</script>
		<style>
			.collapsable {
				cursor: pointer;
			}
		</style>
		<?php
	}


	function backwardKeys($table, $tableName) {
	    global $y;
	    
	    switch (true) {
	        case $y === 'sql': 
	        case strpos($y, 'mysql') !== false: 
	            return $this->backwardKeysFromMySQL($table, $tableName);
	        case strpos($y, 'mssql') !== false: 
	            return $this->backwardKeysFromMsSQL($table, $tableName);
	        default: return [];
	    }
	}
	
	private function backwardKeysFromMsSQL($table, $tableName)
	{
	    $connection = connection();
	    $schema = get_schema();
	    $result = $connection->query("EXEC sp_fkeys @pktable_name = '{$table}', @pktable_owner = '{$schema}'");
	    
	    $backwardKeys = [];
	    $i = 0;
	    
	    if ($result) {
	        while ($row = $result->fetch_assoc()) {
	            $fkTable = $row['FKTABLE_OWNER'] . '.' . $row['FKTABLE_NAME'];
	            $backwardKeys[$fkTable . $i] = [
	                'tableName' => $row['FKTABLE_NAME'],
	                'columnName' =>$row['FKCOLUMN_NAME'],
	                'referencedColumnName' =>$row['PKCOLUMN_NAME'],
	            ];
	            $i++;
	        }
	    }
	    
	    ksort($backwardKeys);
	    
	    return $backwardKeys;
	}
	
	private function backwardKeysFromMySQL($table, $tableName)
	{
	    $connection = connection();
	    
	    $database = $connection->query('SELECT DATABASE() AS db;')->fetch_assoc();
	    $result = $connection->query(sprintf('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME = \'%s\' AND CONSTRAINT_SCHEMA = \'%s\';', $tableName, $database['db']));
	    
	    $backwardKeys = [];
	    $i = 0;
	    
	    if ($result) {
	        while ($row = $result->fetch_assoc()) {
	            $backwardKeys[$row['TABLE_NAME'] . $i] = [
	                'tableName' => $row['TABLE_NAME'],
	                'columnName' =>$row['COLUMN_NAME'],
	                'referencedColumnName' =>$row['REFERENCED_COLUMN_NAME'],
	            ];
	            $i++;
	        }
	    }
	    
	    ksort($backwardKeys);
	    
	    return $backwardKeys;
	}

	function backwardKeysPrint($backwardKeys, $row) {
		$iterator = 0;

		foreach ($backwardKeys as $backwardKey) {
			$iterator++;
			$whereLink = where_link(1, $backwardKey['columnName'], $row[$backwardKey['referencedColumnName']]);
			$link = sprintf('select=%s%s', $backwardKey['tableName'], $whereLink);

			if ($iterator === 2) {
				echo '<div class="fk-more hidden">';
			}

			echo sprintf("<a href='%s'>%s</a>%s\n", h(ME . $link), $backwardKey['tableName'], ($iterator === 1 && count($backwardKeys) > 1) ? '<span class="collapsable"> [<a>&#x25BC;</a>]</span>' : '');

			if ($iterator === count($backwardKeys)) {
				echo '</div>';
			}
		}

		echo '</div>';
	}
}