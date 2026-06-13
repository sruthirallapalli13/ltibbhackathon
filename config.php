<?php
session_start();

$host     = getenv('DB_HOST')     ?: 'localhost';
$user     = getenv('DB_USER')     ?: 'admin';
$password = getenv('DB_PASSWORD') ?: '';
$dbname   = getenv('DB_NAME')     ?: 'customers';

$con = mysqli_connect($host, $user, $password, $dbname);

if (!$con) {
    error_log("DB connection failed: " . mysqli_connect_error());
    die("Connection failed. Please try again later.");
}
?>
