<?php

function getDB(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: 'gateway01.ap-southeast-1.prod.alicloud.tidbcloud.com';
    $port = getenv('DB_PORT') ?: '4000';
    $dbname = getenv('DB_NAME') ?: 'meditrack';
    $user = getenv('DB_USER') ?: '2Ggt5JVH8GPf4fe.root';
    $pass = getenv('DB_PASS') ?: 'Dvqi3NZvhn3tFcc0';

    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);

    } catch (PDOException $e) {

        die("
        <h2>Koneksi Database Gagal</h2>
        <b>Error :</b><br>
        {$e->getMessage()}
        ");

    }

    return $pdo;
}