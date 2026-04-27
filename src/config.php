<?php
// config.php - Database configuration and utilities

define('DB_HOST', $_ENV['mysql_host']);
define('DB_USER', $_ENV['mysql_user']);
define('DB_PASS', $_ENV['mysql_pass']);
define('DB_NAME', $_ENV['mysql_db']);

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Connection failed: ' . $conn->connect_error]));
}

$conn->set_charset("utf8mb4");

// Helper function to send JSON response
function send_json($success, $message, $data = null) {
    header('Content-Type: application/json');
    $response = [
        'success' => $success,
        'message' => $message
    ];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    exit;
}

// Helper function to convert IP string to binary
function ip_to_bin($ip) {
    return inet_pton($ip);
}

// Helper function to convert binary to IP string
function bin_to_ip($bin) {
    return inet_ntop($bin);
}

// Helper function to check if IP is in range
function ip_in_range($ip, $range_start, $range_end) {
    $ip_long = ip2long($ip);
    $start_long = ip2long($range_start);
    $end_long = ip2long($range_end);
    return ($ip_long >= $start_long && $ip_long <= $end_long);
}

// Helper function to get IP category
function get_ip_category($ip) {
    $result = $GLOBALS['conn']->query("SELECT category FROM static_ranges WHERE '$ip' >= range_start AND '$ip' <= range_end LIMIT 1");
    if ($result && $row = $result->fetch_assoc()) {
        return $row['category'];
    }
    return 'Other';
}
?>
