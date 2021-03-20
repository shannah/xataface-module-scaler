<?php
class actions_scaler_clear_cache {

	function handle($params){
		session_write_close();
		
		set_time_limit(0);
		import('modules/scaler/classes/Xataface_Scaler.php');
			
		$scaler = new Xataface_Scaler();
		echo '<html><head><title>Deleting Cache</title></head><body><h1>Deleting Cache</h1><ul>';
		$scaler->clearCache(array($this, 'writeProgress'));
		echo '</ul></body>';
		
	
	}
	
	function writeProgress($content){
		echo '<li>'.htmlspecialchars($content).'</li>';
		flush();
		
	}
}