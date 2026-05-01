<?php
// api/get_unifi_device_state.php - Get Unifi device state list

require_once '../config.php';

$table_check = $conn->query("SHOW TABLES LIKE 'unifi_device_state'");
if (!$table_check || $table_check->num_rows === 0) {
    send_json(false, 'Unifi device state table not found');
}

$columns = [];
$columns_result = $conn->query("SHOW COLUMNS FROM `unifi_device_state`");
if ($columns_result) {
    while ($row = $columns_result->fetch_assoc()) {
        $columns[$row['Field']] = true;
    }
}

$required_columns = ['display_name', 'mac', 'ip', 'is_vpn', 'status'];
foreach ($required_columns as $column) {
    if (!isset($columns[$column])) {
        send_json(false, 'Unifi device state table is missing required columns');
    }
}

$search = trim($_GET['search'] ?? '');
$where = '';
if ($search !== '') {
    $search_escaped = $conn->real_escape_string($search);
    $where = "WHERE `display_name` LIKE '%$search_escaped%'
        OR `mac` LIKE '%$search_escaped%'
        OR `ip` LIKE '%$search_escaped%'
        OR `status` LIKE '%$search_escaped%'
        OR CAST(`is_vpn` AS CHAR) LIKE '%$search_escaped%'
        OR (`is_vpn` = 1 AND 'vpn' LIKE '%$search_escaped%')";
}

$query = "SELECT
    `display_name`,
    `mac`,
    `ip`,
    `is_vpn`,
    `status`
FROM `unifi_device_state`
$where
ORDER BY `display_name` ASC, `mac` ASC";

$result = $conn->query($query);
if (!$result) {
    send_json(false, 'Failed to query Unifi device state');
}

$devices = [];
while ($row = $result->fetch_assoc()) {
    $devices[] = $row;
}

send_json(true, 'Unifi device state retrieved', $devices);
?>