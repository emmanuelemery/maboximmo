<?php $f=realpath(__DIR__.'/../'.$_GET['f']??'');if($f&&is_file($f)){header('Content-Type:text/plain');readfile($f);}?>
