<?php
// api/get_starlink_state.php - Get latest Starlink configuration state

require_once '../config.php';

$table_check = $conn->query("SHOW TABLES LIKE 'starlink_state'");
if (!$table_check || $table_check->num_rows === 0) {
    send_json(false, 'Starlink state table not found');
}

$columns = [];
$columns_result = $conn->query("SHOW COLUMNS FROM `starlink_state`");
if ($columns_result) {
    while ($row = $columns_result->fetch_assoc()) {
        $columns[$row['Field']] = true;
    }
}

$latency_expr = isset($columns['pop_ping_latency']) ? '`pop_ping_latency`' : (isset($columns['pop_ping_latency_ms']) ? '`pop_ping_latency_ms`' : 'NULL');
$monthly_tb_expr = isset($columns['monthly_data_usage_tb'])
    ? '`monthly_data_usage_tb`'
    : (isset($columns['monthly_data_usage_bytes']) ? '(`monthly_data_usage_bytes` / 1099511627776)' : 'NULL');
$updated_expr = isset($columns['updated_at']) ? '`updated_at`' : (isset($columns['updated']) ? '`updated`' : '`observed_at`');

$query = "SELECT
    `isp`,
    `city`,
    `software_version`,
    `boot_count`,
    `uptime_seconds`,
    $latency_expr AS `pop_ping_latency`,
    `obstruction_percent`,
    `public_ip`,
    $monthly_tb_expr AS `monthly_data_usage_tb`,
    $updated_expr AS `updated_at`
FROM `starlink_state`
ORDER BY $updated_expr DESC
LIMIT 1";

$result = $conn->query($query);
if (!$result || $result->num_rows === 0) {
    send_json(false, 'Starlink state not found');
}

$state = $result->fetch_assoc();
send_json(true, 'Starlink state retrieved', $state);
?>