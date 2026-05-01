<?php
// api/get_unifi_state.php - Get latest Unifi configuration state

require_once '../config.php';

$table_check = $conn->query("SHOW TABLES LIKE 'unifi_state'");
if (!$table_check || $table_check->num_rows === 0) {
    send_json(false, 'Unifi state table not found');
}

$columns = [];
$columns_result = $conn->query("SHOW COLUMNS FROM `unifi_state`");
if ($columns_result) {
    while ($row = $columns_result->fetch_assoc()) {
        $columns[$row['Field']] = true;
    }
}

$updated_column = 'updated';
if (!isset($columns['updated']) && isset($columns['updated_at'])) {
    $updated_column = 'updated_at';
}

$dhcp_stop_column = isset($columns['dhcp_stop']) ? 'dhcp_stop' : (isset($columns['dhcp_end']) ? 'dhcp_end' : null);

if (!isset($columns['name']) || !isset($columns['network_name']) || !isset($columns['network_version']) ||
    !isset($columns['udm_display_version']) || !isset($columns['ip_subnet']) || !isset($columns['dhcp_start']) ||
    $dhcp_stop_column === null) {
    send_json(false, 'Unifi state table is missing required columns');
}

$order_column = isset($columns[$updated_column]) ? "`$updated_column`" : '`id`';

$query = "SELECT
    `name`,
    `network_name`,
    `network_version`,
    `udm_display_version`,
    `ip_subnet`,
    `dhcp_start`,
    `$dhcp_stop_column` AS `dhcp_stop`,
    `$updated_column` AS `updated`
FROM `unifi_state`
ORDER BY $order_column DESC
LIMIT 1";

$result = $conn->query($query);
if (!$result || $result->num_rows === 0) {
    send_json(true, 'Unifi state has no rows yet', null);
}

$state = $result->fetch_assoc();

$vpn_state = [
    'vpn_name' => null,
    'vpn_type' => null,
    'ip' => null,
    'target_count' => null
];

$vpn_table_check = $conn->query("SHOW TABLES LIKE 'unifi_vpn_state'");
if ($vpn_table_check && $vpn_table_check->num_rows > 0) {
    $vpn_query = "SELECT `vpn_name`, `vpn_type`, `ip`, `target_count`
        FROM `unifi_vpn_state`
        ORDER BY `id` DESC
        LIMIT 1";
    $vpn_result = $conn->query($vpn_query);
    if ($vpn_result && $vpn_result->num_rows > 0) {
        $vpn_state = $vpn_result->fetch_assoc();
    }
}

$state['vpn_name'] = $vpn_state['vpn_name'];
$state['vpn_type'] = $vpn_state['vpn_type'];
$state['vpn_ip'] = $vpn_state['ip'];
$state['target_count'] = $vpn_state['target_count'];

send_json(true, 'Unifi state retrieved', $state);
?>