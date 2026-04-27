<?php
// metrics.php - Prometheus metrics endpoint for IP Tracker

header('Content-Type: text/plain; version=0.0.4; charset=utf-8');

$dbHost = $_ENV['mysql_host'];
$dbUser = $_ENV['mysql_user'];
$dbPass = $_ENV['mysql_pass'];
$dbName = $_ENV['mysql_db'];

$conn = @new mysqli($dbHost, $dbUser, $dbPass, $dbName);

$metrics = [];

function add_metric(array &$metrics, $name, $value, $help, $type = 'gauge') {
    $metrics[] = "# HELP $name $help";
    $metrics[] = "# TYPE $name $type";
    $metrics[] = $name . ' ' . (is_numeric($value) ? $value : 0);
}

function table_exists(mysqli $conn, $table_name) {
    $safe_name = $conn->real_escape_string($table_name);
    $query = "SHOW TABLES LIKE '$safe_name'";
    $result = $conn->query($query);
    return $result && $result->num_rows > 0;
}

function query_int(mysqli $conn, $sql, $fallback = 0) {
    $result = $conn->query($sql);
    if (!$result) {
        return $fallback;
    }

    $row = $result->fetch_row();
    if (!$row || !isset($row[0]) || $row[0] === null) {
        return $fallback;
    }

    return (int)$row[0];
}

if ($conn->connect_error) {
    add_metric(
        $metrics,
        'ip_tracker_up',
        0,
        'Whether IP Tracker can connect to the database (1=up, 0=down)'
    );

    echo implode("\n", $metrics) . "\n";
    exit;
}

$conn->set_charset('utf8mb4');

$static_ips_total = 0;
$static_ips_assigned = 0;
$static_ips_behind_vpn = 0;
$network_ranges_total = 0;
$tracked_devices_total = 0;
$tracked_devices_behind_vpn = 0;

if (table_exists($conn, 'static_ips')) {
    $static_ips_total = query_int($conn, "SELECT COUNT(*) FROM static_ips");
    $static_ips_assigned = query_int($conn, "SELECT COALESCE(SUM(is_assigned), 0) FROM static_ips");
    $static_ips_behind_vpn = query_int($conn, "SELECT COALESCE(SUM(behind_vpn), 0) FROM static_ips");
}

if (table_exists($conn, 'static_ranges')) {
    $network_ranges_total = query_int($conn, "SELECT COUNT(*) FROM static_ranges");
}

if (table_exists($conn, 'tracked_devices')) {
    $tracked_devices_total = query_int($conn, "SELECT COUNT(*) FROM tracked_devices");
    $tracked_devices_behind_vpn = query_int($conn, "SELECT COALESCE(SUM(behind_vpn), 0) FROM tracked_devices");
}

add_metric(
    $metrics,
    'ip_tracker_up',
    1,
    'Whether IP Tracker can connect to the database (1=up, 0=down)'
);
add_metric($metrics, 'ip_tracker_static_ips_total', $static_ips_total, 'Total static IP rows');
add_metric($metrics, 'ip_tracker_static_ips_assigned', $static_ips_assigned, 'Assigned static IP rows');
add_metric(
    $metrics,
    'ip_tracker_static_ips_available',
    max(0, $static_ips_total - $static_ips_assigned),
    'Available static IP rows'
);
add_metric($metrics, 'ip_tracker_static_ips_behind_vpn', $static_ips_behind_vpn, 'Static IP rows behind VPN');
add_metric($metrics, 'ip_tracker_tracked_devices_total', $tracked_devices_total, 'Total tracked non-static devices');
add_metric(
    $metrics,
    'ip_tracker_tracked_devices_behind_vpn',
    $tracked_devices_behind_vpn,
    'Tracked non-static devices behind VPN'
);
add_metric($metrics, 'ip_tracker_network_ranges_total', $network_ranges_total, 'Total configured static IP ranges');
add_metric(
    $metrics,
    'ip_tracker_vpn_devices_total',
    $static_ips_behind_vpn + $tracked_devices_behind_vpn,
    'Total VPN devices across static IPs and tracked devices'
);

echo implode("\n", $metrics) . "\n";
$conn->close();
?>
