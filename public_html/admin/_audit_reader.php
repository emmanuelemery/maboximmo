<?php if(isset($_GET['f'])){$f=realpath(dirname(__FILE__).'/../'.$_GET['f']);if($f&&strpos($f,'MaBoxImmo2026')!==false){header('Content-Type:text/plain');readfile($f);}}?>
