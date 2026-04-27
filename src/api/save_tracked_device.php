<?php
// api/save_tracked_device.php - Create or update tracked non-static devices

require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST' && $method !== 'PUT') {
    send_json(false, 'Invalid request method');
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);
if (!$data) {
    send_json(false, 'Invalid JSON data');
}

$id = isset($data['id']) ? intval($data['id']) : 0;
$device_name = isset($data['device_name']) ? trim($data['device_name']) : '';
$mac = isset($data['mac']) ? strtoupper(str_replace('-', ':', trim($data['mac']))) : '';
$current_ip = isset($data['current_ip']) ? trim($data['current_ip']) : '';
$behind_vpn = isset($data['behind_vpn']) ? intval($data['behind_vpn']) : 0;
$notes = isset($data['notes']) ? trim($data['notes']) : '';

if ($device_name === '') {
    send_json(false, 'Device name is required');
}

if ($mac === '') {
    send_json(false, 'MAC address is required');
}

if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
    send_json(false, 'Invalid MAC format. Use format like AA:BB:CC:DD:EE:FF');
}

if ($current_ip !== '' && !filter_var($current_ip, FILTER_VALIDATE_IP)) {
    send_json(false, 'Invalid current IP address');
}

$device_name_sql = $conn->real_escape_string($device_name);
$mac_sql = $conn->real_escape_string($mac);
$notes_sql = $conn->real_escape_string($notes);
$current_ip_sql = $current_ip === '' ? 'NULL' : "'" . $conn->real_escape_string($current_ip) . "'";
$behind_vpn = $behind_vpn === 1 ? 1 : 0;

if ($id > 0) {
    $query = "UPDATE tracked_devices SET
        device_name = '$device_name_sql',
        mac = '$mac_sql',
        current_ip = $current_ip_sql,
        behind_vpn = $behind_vpn,
        notes = '$notes_sql'
        WHERE id = $id";

    if ($conn->query($query)) {
        send_json(true, 'Tracked device updated successfully', ['id' => $id]);
    }

    send_json(false, 'Update failed: ' . $conn->error);
}

$query = "INSERT INTO tracked_devices (device_name, mac, current_ip, behind_vpn, notes)
    VALUES ('$device_name_sql', '$mac_sql', $current_ip_sql, $behind_vpn, '$notes_sql')";

if ($conn->query($query)) {
    send_json(true, 'Tracked device created successfully', ['id' => $conn->insert_id]);
}

if ($conn->errno === 1062) {
    send_json(false, 'That MAC address already exists in tracked devices');
}

send_json(false, 'Insert failed: ' . $conn->error);
?>