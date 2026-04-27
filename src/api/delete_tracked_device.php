<?php
// api/delete_tracked_device.php - Delete a tracked device

require_once '../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    send_json(false, 'Invalid request method');
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);
$id = isset($data['id']) ? intval($data['id']) : 0;

if ($id <= 0) {
    send_json(false, 'ID is required');
}

$query = "DELETE FROM tracked_devices WHERE id = $id";
if ($conn->query($query)) {
    send_json(true, 'Tracked device deleted successfully');
}

send_json(false, 'Delete failed: ' . $conn->error);
?>