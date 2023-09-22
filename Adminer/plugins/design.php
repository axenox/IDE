<?php

/** Set custom design
* @author Andrej Kabachnik
* @license https://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
*/
class AdminerDesign {
	/** @access protected */
	var $design = null;
	
	/**
	* @param array URL in key, name in value
	*/
	function __construct($design) {
		$this->design = $design;
	}
	
	function css() {
	    $return = array();
	    if ($this->design !== null) {
            $filename = "designs/{$this->design}/adminer.css";
	        $return[] = "$filename?v=" . crc32(file_get_contents('../' . $filename));
	    }
	    return $return;
	}
}
