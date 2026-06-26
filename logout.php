<?php
session_start();
session_destroy();
header('Location: /rekam-medis-fixed/login.php');
exit;