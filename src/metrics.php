<?php
// metrics.php - Prometheus metrics endpoint for IP Tracker

header('Content-Type: text/plain; version=0.0.4; charset=utf-8');

define('DB_HOST', '192.168.20.27');
define('DB_USER', 'root');
define('DB_PASS', 'champ20');
define('DB_NAME', 'ip_tracker');

$dbHost = DB_HOST;
$dbUser = DB_USER;
$dbPass = DB_PASS;
$dbName = DB_NAME;

$metrics = [];
$conn = null;

function add_metric(array &$metrics, $name, $value, $help, $type = 'gauge') {
    $metrics[] = "# HELP $name $help";
    $metrics[] = "# TYPE $name $type";
    $metrics[] = $name . ' ' . (is_numeric($value) ? $value : 0);
}

function table_exists(mysqli $conn, $table_name) {
    $safe_name = $conn->real_escape_string($table_name);
    $result = $conn->query("SHOW TABLES LIKE '$safe_name'");
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

// PHP 8 MySQLi throws exceptions on connect failure by default;
// disable that so we can handle it gracefully and always emit valid Prometheus text.
mysqli_report(MYSQLI_REPORT_OFF);

try {
    $conn = @new mysqli($dbHost, $dbUser, $dbPass, $dbName);
} catch (Exception $e) {
    $conn = null;
}

if ($conn === null || $conn->connect_errno) {
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
$k8s_ingress_dns_total = 0;
$pihole_dns_entries_total = 0;

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

if (table_exists($conn, 'k8s_ingress_dns')) {
    $k8s_ingress_dns_total = query_int($conn, "SELECT COUNT(*) FROM k8s_ingress_dns");
}

if (table_exists($conn, 'pihole_dns_entries')) {
    $pihole_dns_entries_total = query_int($conn, "SELECT COUNT(*) FROM pihole_dns_entries");
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
add_metric($metrics, 'ip_tracker_k8s_ingress_dns_total', $k8s_ingress_dns_total, 'Total Kubernetes ingress DNS rows');
add_metric($metrics, 'ip_tracker_pihole_dns_entries_total', $pihole_dns_entries_total, 'Total Pi-hole DNS entry rows');
add_metric(
    $metrics,
    'ip_tracker_vpn_devices_total',
    $static_ips_behind_vpn + $tracked_devices_behind_vpn,
    'Total VPN devices across static IPs and tracked devices'
);

echo implode("\n", $metrics) . "\n";
$conn->close();
?>
