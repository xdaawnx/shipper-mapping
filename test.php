<?php
// Database connection details
$host = 'shipper-staging.csyo5vl3oyxs.ap-southeast-1.rds.amazonaws.com';
$username = 'dody.adi';
$password = '0Nfje6XhEkzpc3RBTHTZtTJKp2P4GesJ';
$database = 'shipper_staging';

// SSL certificate path
$sslCert = 'storage/app/private/cert.pem';

if (!file_exists($sslCert)) {
    echo "SSL certificate file does not exist at: $sslCert \n";
    return;
}

// Create a new mysqli connection
$mysqli = new mysqli($host, $username, $password, $database);

// Set up SSL connection
$mysqli->ssl_set(NULL, NULL, $sslCert, NULL, NULL);

// Attempt to establish the connection with SSL
if (!$mysqli->real_connect($host, $username, $password, $database, 3306, null, MYSQLI_CLIENT_SSL)) {
    die("SSL connection failed: " . $mysqli->connect_error);
}

echo "Connected successfully with SSL";

// Close the connection
$mysqli->close();
?>
