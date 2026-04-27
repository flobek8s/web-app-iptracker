<?php
// api/get_tracked_device.php - Get a single tracked device by ID

require_once '../config.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    send_json(false, 'Invalid ID');
}

$query = "SELECT id, device_name, mac, current_ip, behind_vpn, notes, created_at, updated_at FROM tracked_devices WHERE id = $id LIMIT 1";
$result = $conn->query($query);

if (!$result) {
    send_json(false, 'Query failed: ' . $conn->error);
}

if ($result->num_rows === 0) {
    send_json(false, 'Tracked device not found');
}

send_json(true, 'Tracked device fetched successfully', $result->fetch_assoc());
?>