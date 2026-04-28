<?php
// api/get_k8s_ingress_dns.php - Get Kubernetes ingress DNS records

require_once '../config.php';

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$sort = isset($_GET['sort']) ? $conn->real_escape_string($_GET['sort']) : 'last_seen';
$sort_dir = isset($_GET['sort_dir']) && $_GET['sort_dir'] === 'asc' ? 'ASC' : 'DESC';

$valid_sorts = ['namespace', 'ingress_name', 'domain', 'service', 'ip', 'type', 'last_seen', 'datecreated'];
if (!in_array($sort, $valid_sorts, true)) {
    $sort = 'last_seen';
}

$query = "SELECT id, namespace, ingress_name, domain, service, ip, type, last_seen, datecreated FROM k8s_ingress_dns WHERE 1=1";

if ($search) {
    $query .= " AND ("
        . "CAST(id AS CHAR) LIKE '%$search%'"
        . " OR namespace LIKE '%$search%'"
        . " OR ingress_name LIKE '%$search%'"
        . " OR domain LIKE '%$search%'"
        . " OR service LIKE '%$search%'"
        . " OR ip LIKE '%$search%'"
        . " OR type LIKE '%$search%'"
        . " OR last_seen LIKE '%$search%'"
        . " OR datecreated LIKE '%$search%'"
        . ")";
}

$query .= " ORDER BY `$sort` $sort_dir";

$result = $conn->query($query);
if (!$result) {
    send_json(false, 'Query failed: ' . $conn->error);
}

$records = [];
while ($row = $result->fetch_assoc()) {
    $records[] = $row;
}

send_json(true, 'Kubernetes ingress DNS records fetched successfully', $records);
?>