<?php
// Database connection file
$host = "localhost";
$dbname = "dbqqsenjk7f4bf";
$username = "uklz9ew3hrop3";
$password = "zyrbspyjlzjb";

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
    die();
}
?>
