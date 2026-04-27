<?php
// api/get_tracked_devices.php - Get tracked non-static devices

require_once '../config.php';

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$vpn_only = isset($_GET['vpn_only']) ? intval($_GET['vpn_only']) : 0;
$sort = isset($_GET['sort']) ? $conn->real_escape_string($_GET['sort']) : 'device_name';
$sort_dir = isset($_GET['sort_dir']) && $_GET['sort_dir'] === 'desc' ? 'DESC' : 'ASC';

$valid_sorts = ['device_name', 'mac', 'current_ip', 'behind_vpn', 'created_at', 'updated_at'];
if (!in_array($sort, $valid_sorts, true)) {
    $sort = 'device_name';
}

$query = "SELECT id, device_name, mac, current_ip, behind_vpn, notes, created_at, updated_at FROM tracked_devices WHERE 1=1";

if ($search) {
    $query .= " AND (device_name LIKE '%$search%' OR mac LIKE '%$search%' OR current_ip LIKE '%$search%' OR notes LIKE '%$search%')";
}

if ($vpn_only) {
    $query .= " AND behind_vpn = 1";
}

if ($sort === 'current_ip') {
    $query .= " ORDER BY (current_ip IS NULL OR current_ip = ''), INET_ATON(current_ip) $sort_dir, device_name ASC";
} else {
    $query .= " ORDER BY `$sort` $sort_dir";
}

$result = $conn->query($query);
if (!$result) {
    send_json(false, 'Query failed: ' . $conn->error);
}

$devices = [];
while ($row = $result->fetch_assoc()) {
    $devices[] = $row;
}

send_json(true, 'Tracked devices fetched successfully', $devices);
?>