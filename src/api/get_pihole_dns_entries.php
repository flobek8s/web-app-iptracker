<?php
// api/get_pihole_dns_entries.php - Get Pi-hole DNS entries

require_once '../config.php';

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$sort = isset($_GET['sort']) ? $conn->real_escape_string($_GET['sort']) : 'domain';
$sort_dir = isset($_GET['sort_dir']) && $_GET['sort_dir'] === 'desc' ? 'DESC' : 'ASC';

$valid_sorts = ['domain', 'ip', 'datecreated', 'dateupdated'];
if (!in_array($sort, $valid_sorts, true)) {
    $sort = 'domain';
}

$query = "SELECT id, domain, ip, datecreated, dateupdated FROM pihole_dns_entries WHERE 1=1";

if ($search) {
    $query .= " AND ("
        . "CAST(id AS CHAR) LIKE '%$search%'"
        . " OR domain LIKE '%$search%'"
        . " OR ip LIKE '%$search%'"
        . " OR datecreated LIKE '%$search%'"
        . " OR dateupdated LIKE '%$search%'"
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

send_json(true, 'Pi-hole DNS entries fetched successfully', $records);
?>