<?php
include dirname(__FILE__).'/classes/Xataface_Scaler.php';
if ( defined('XATAFACE_SCALER_AUTOSTART') and !XATAFACE_SCALER_AUTOSTART ){
	// Do not autostart
} else {
	$scaler = Xataface_Scaler::getInstance();
	$scaler->start();
}