<?php
session_start();
session_destroy();
header('Location: /rekam-medis-fixed/api/login.php');
exit;