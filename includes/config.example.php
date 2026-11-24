<?php
// Copy this file to config.php and fill in your values.
// config.php is excluded from version control via .gitignore

define('DB_HOST', 'your-rds-endpoint.rds.amazonaws.com');
define('DB_NAME', 'darkarchive');
define('DB_USER', 'darkarchive_user');
define('DB_PASS', 'your_db_password');
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('RDS_CERT',   '/etc/mysql-certs/global-bundle.pem');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE                      => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE           => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_SSL_CA                 => RDS_CERT,
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
            ]
        );
    }
    return $pdo;
}
