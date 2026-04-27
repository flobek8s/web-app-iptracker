<?php
// api/get_ips.php - Get all IPs with optional filtering and sorting

require_once '../config.php';

header('Content-Type: application/json');

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$sort = isset($_GET['sort']) ? $conn->real_escape_string($_GET['sort']) : 'ip';
$category = isset($_GET['category']) ? $conn->real_escape_string($_GET['category']) : '';
$vpn_only = isset($_GET['vpn_only']) ? intval($_GET['vpn_only']) : 0;
$query = "SELECT id, ip, `system`, hostname, mac, notes, is_assigned, category, behind_vpn, created_at, updated_at FROM static_ips WHERE 1=1";

if ($search) {
    $query .= " AND ("
        . "CAST(id AS CHAR) LIKE '%$search%'"
        . " OR ip LIKE '%$search%'"
        . " OR `system` LIKE '%$search%'"
        . " OR hostname LIKE '%$search%'"
        . " OR mac LIKE '%$search%'"
        . " OR notes LIKE '%$search%'"
        . " OR category LIKE '%$search%'"
        . " OR CAST(is_assigned AS CHAR) LIKE '%$search%'"
        . " OR CAST(behind_vpn AS CHAR) LIKE '%$search%'"
        . " OR created_at LIKE '%$search%'"
        . " OR updated_at LIKE '%$search%'"
        . ")";
}

if ($category) {
    $query .= " AND category = '$category'";
}

if ($vpn_only) {
    $query .= " AND behind_vpn = 1";
}

// Determine sort column and direction
$valid_sorts = ['ip', 'system', 'hostname', 'mac', 'is_assigned', 'created_at', 'updated_at'];
$sort_dir = isset($_GET['sort_dir']) && $_GET['sort_dir'] === 'desc' ? 'DESC' : 'ASC';

if (!in_array($sort, $valid_sorts)) {
    $sort = 'ip';
}

// Sort by IP numerically
if ($sort === 'ip') {
    $query .= " ORDER BY INET_ATON(ip) $sort_dir";
} else {
    $query .= " ORDER BY `$sort` $sort_dir";
}

$result = $conn->query($query);

if (!$result) {
    send_json(false, 'Query failed: ' . $conn->error);
}

$ips = [];
while ($row = $result->fetch_assoc()) {
    $ips[] = $row;
}

send_json(true, 'IPs fetched successfully', $ips);
?>
