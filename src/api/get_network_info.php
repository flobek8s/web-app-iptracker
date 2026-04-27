<?php
// api/get_network_info.php - Get network configuration and stats

require_once '../config.php';

// Get network config
$config_query = "SELECT * FROM network_config WHERE id = 1";
$config_result = $conn->query($config_query);
$network_config = $config_result->fetch_assoc();

// Get static ranges
$ranges_query = "SELECT * FROM static_ranges ORDER BY range_name";
$ranges_result = $conn->query($ranges_query);
$ranges = [];
while ($row = $ranges_result->fetch_assoc()) {
    $ranges[] = $row;
}

// Calculate statistics
$stats_query = "SELECT 
    COUNT(*) as total_ips,
    SUM(is_assigned) as assigned_ips,
    SUM(behind_vpn) as vpn_ips,
    COUNT(DISTINCT category) as categories
    FROM static_ips";
    
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Include tracked non-static devices in VPN count when table exists.
$tracked_vpn_count = 0;
$tracked_table_check = $conn->query("SHOW TABLES LIKE 'tracked_devices'");
if ($tracked_table_check && $tracked_table_check->num_rows > 0) {
    $tracked_vpn_result = $conn->query("SELECT COUNT(*) AS tracked_vpn_count FROM tracked_devices WHERE behind_vpn = 1");
    if ($tracked_vpn_result) {
        $tracked_row = $tracked_vpn_result->fetch_assoc();
        $tracked_vpn_count = intval($tracked_row['tracked_vpn_count'] ?? 0);
    }
}

$stats['vpn_ips'] = intval($stats['vpn_ips'] ?? 0) + $tracked_vpn_count;

$data = [
    'network_config' => $network_config,
    'ranges' => $ranges,
    'stats' => $stats
];

send_json(true, 'Network info retrieved', $data);
?>
