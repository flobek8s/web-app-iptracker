<?php
// api/get_vpn_devices.php - Get devices behind VPN

require_once '../config.php';

$has_tracked_table = false;
$check = $conn->query("SHOW TABLES LIKE 'tracked_devices'");
if ($check && $check->num_rows > 0) {
    $has_tracked_table = true;
}

if ($has_tracked_table) {
    $query = "
        SELECT * FROM (
            SELECT id, ip, `system`, hostname, mac, notes, 'static' AS source_type
            FROM static_ips
            WHERE behind_vpn = 1
            UNION ALL
            SELECT id, current_ip AS ip, device_name AS `system`, '' AS hostname, mac, notes, 'tracked' AS source_type
            FROM tracked_devices
            WHERE behind_vpn = 1
        ) combined
        ORDER BY (ip IS NULL OR ip = ''), INET_ATON(ip), `system`
    ";
} else {
    $query = "SELECT id, ip, `system`, hostname, mac, notes, 'static' AS source_type FROM static_ips WHERE behind_vpn = 1 ORDER BY (ip IS NULL OR ip = ''), INET_ATON(ip), `system`";
}

$result = $conn->query($query);

if (!$result) {
    send_json(false, 'Query failed: ' . $conn->error);
}

$devices = [];
while ($row = $result->fetch_assoc()) {
    $devices[] = $row;
}

send_json(true, 'VPN devices retrieved', $devices);
?>
