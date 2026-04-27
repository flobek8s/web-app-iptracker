<?php
// api/get_ip.php - Get a single IP record

require_once '../config.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    send_json(false, 'ID is required');
}

$query = "SELECT id, ip, `system`, hostname, mac, notes, is_assigned, category, behind_vpn, created_at, updated_at FROM static_ips WHERE id = $id";
$result = $conn->query($query);

if (!$result) {
    send_json(false, 'Query failed: ' . $conn->error);
}

if ($result->num_rows === 0) {
    send_json(false, 'IP not found');
}

$ip = $result->fetch_assoc();
send_json(true, 'IP fetched successfully', $ip);
?>
