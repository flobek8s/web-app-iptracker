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

$k8s_ingress_count = 0;
$k8s_table_check = $conn->query("SHOW TABLES LIKE 'k8s_ingress_dns'");
if ($k8s_table_check && $k8s_table_check->num_rows > 0) {
    $k8s_result = $conn->query("SELECT COUNT(*) AS k8s_ingress_count FROM k8s_ingress_dns");
    if ($k8s_result) {
        $k8s_row = $k8s_result->fetch_assoc();
        $k8s_ingress_count = intval($k8s_row['k8s_ingress_count'] ?? 0);
    }
}

$pihole_dns_entries_count = 0;
$pihole_entries_table_check = $conn->query("SHOW TABLES LIKE 'pihole_dns_entries'");
if ($pihole_entries_table_check && $pihole_entries_table_check->num_rows > 0) {
    $pihole_entries_result = $conn->query("SELECT COUNT(*) AS pihole_dns_entries_count FROM pihole_dns_entries");
    if ($pihole_entries_result) {
        $pihole_entries_row = $pihole_entries_result->fetch_assoc();
        $pihole_dns_entries_count = intval($pihole_entries_row['pihole_dns_entries_count'] ?? 0);
    }
}

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
$stats['k8s_ingress_count'] = $k8s_ingress_count;
$stats['pihole_dns_entries_count'] = $pihole_dns_entries_count;

$data = [
    'network_config' => $network_config,
    'ranges' => $ranges,
    'stats' => $stats
];

send_json(true, 'Network info retrieved', $data);
?>
