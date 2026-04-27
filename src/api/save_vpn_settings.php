<?php
// api/save_vpn_settings.php - Update VPN configuration

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

$vpn_provider = isset($data['vpn_provider']) ? $conn->real_escape_string($data['vpn_provider']) : 'IPVanish';
$vpn_external_ip = isset($data['vpn_external_ip']) ? $conn->real_escape_string($data['vpn_external_ip']) : '';
$vpn_tunnel_ip = isset($data['vpn_tunnel_ip']) ? $conn->real_escape_string($data['vpn_tunnel_ip']) : '';
$vpn_tunnel_subnet = isset($data['vpn_tunnel_subnet']) ? $conn->real_escape_string($data['vpn_tunnel_subnet']) : '';
$vpn_type = isset($data['vpn_type']) ? $conn->real_escape_string($data['vpn_type']) : 'WireGuard';
$vpn_port = isset($data['vpn_port']) ? intval($data['vpn_port']) : 51820;

$query = "UPDATE vpn_settings SET 
    vpn_provider = '$vpn_provider',
    vpn_external_ip = '$vpn_external_ip',
    vpn_tunnel_ip = '$vpn_tunnel_ip',
    vpn_tunnel_subnet = '$vpn_tunnel_subnet',
    vpn_type = '$vpn_type',
    vpn_port = $vpn_port
    WHERE id = 1";

if ($conn->query($query)) {
    send_json(true, 'VPN settings updated successfully');
} else {
    send_json(false, 'Update failed: ' . $conn->error);
}
?>
