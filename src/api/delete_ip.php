<?php
// api/delete_ip.php - Delete an IP record

require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'DELETE') {
    send_json(false, 'Invalid request method');
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);

$id = isset($data['id']) ? intval($data['id']) : 0;

if (!$id) {
    send_json(false, 'ID is required');
}

$query = "DELETE FROM static_ips WHERE id = $id";

if ($conn->query($query)) {
    send_json(true, 'IP deleted successfully');
} else {
    send_json(false, 'Delete failed: ' . $conn->error);
}
?>
