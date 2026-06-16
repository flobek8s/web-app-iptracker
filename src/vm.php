<?php
// ============================================================
//  Proxmox VM Manager  v2
//  Single-file PHP — requires PHP 8.0+, MySQLi, cURL
// ============================================================

define('DB_HOST', $_ENV['mysql_host']);
define('DB_USER', $_ENV['mysql_user']);
define('DB_PASS', $_ENV['mysql_pass']);
define('DB_NAME', $_ENV['mysql_db']);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function db(): mysqli {
    static $c;
    if (!$c) { $c = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME); $c->set_charset('utf8mb4'); }
    return $c;
}
function json_out(array $d): never { header('Content-Type: application/json'); echo json_encode($d); exit; }
function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }


// ── Proxmox API helper (defined globally so all actions can use it) ──
function pve_get(string $url, array $hdrs, bool $verify): array|false {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $hdrs,
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($code !== 200) return false;
    return json_decode($body, true)['data'] ?? false;
}

// POST helper — used for agent commands (ping, etc.) which require POST not GET
function pve_post(string $url, array $hdrs, bool $verify, array $fields = []): array|null {
    $hdrs[] = 'Content-Type: application/x-www-form-urlencoded';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => $hdrs,
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  
    // Proxmox agent/ping returns 200 with {"data":{}} on success,
    // or 500 if agent not running — treat anything non-200 as failure
    if ($code !== 200) return null;
    return json_decode($body, true)['data'] ?? [];
}

// ── AJAX ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $db  = db();
    $db->query("SET SESSION sql_mode=''"); // optional safety
    $act = $_POST['action'];

    // ── VM: list ────────────────────────────────────────────
    if ($act === 'vm_list') {
        $col = match($_POST['sort'] ?? 'vm_id') {
            'name'    => 'v.name',   'host'   => 'h.name',
            'ip'      => 'v.ip',     'vm_type'=> 'v.vm_type',
            'status'  => 'v.status', default  => 'v.vm_id',
        };
        $dir = ($_POST['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        $q   = '%'.$db->real_escape_string($_POST['q'] ?? '').'%';
        $st  = $db->prepare(
            "SELECT v.id, v.vm_id, v.name, h.name AS host, h.id AS host_id,
                    v.ip, v.mac, v.vm_type, v.status, v.notes, v.last_synced
             FROM   vms v JOIN proxmox_hosts h ON h.id=v.host_id
             WHERE  (v.vm_id LIKE ? OR v.name LIKE ? OR h.name LIKE ?
                     OR v.ip LIKE ? OR v.notes LIKE ?)
             ORDER BY $col $dir"
        );
        $st->bind_param('sssss',$q,$q,$q,$q,$q);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        // flag duplicate VMIDs across fleet
        $cnt = [];
        foreach ($rows as $r) $cnt[$r['vm_id']] = ($cnt[$r['vm_id']] ?? 0) + 1;
        foreach ($rows as &$r) $r['dup'] = $cnt[$r['vm_id']] > 1;
        unset($r);
        json_out(['ok'=>true,'rows'=>$rows]);
    }

    // ── VM: get single ──────────────────────────────────────
    if ($act === 'vm_get') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $db->prepare("SELECT * FROM vms WHERE id=?");
        $st->bind_param('i', $id);
        $st->execute();
        $vm = $st->get_result()->fetch_assoc();
        
        if (!$vm) {
            json_out(['ok'=>false, 'msg'=>'VM not found.']);
        }
        
        json_out(['ok'=>true, 'vm'=>$vm]);
    }

    // ── VM: save (insert or update) ─────────────────────────
    if ($act === 'vm_save') {
        $flds  = ['vm_id','host_id','name','ip','mac','vm_type','status','notes'];
        $d     = [];
        foreach ($flds as $f) $d[$f] = trim($_POST[$f] ?? '') ?: null;
        $d['vm_id']   = (int)$d['vm_id'];
        $d['host_id'] = (int)$d['host_id'];
        if (!$d['vm_id'] || !$d['host_id'] || !$d['name'])
            json_out(['ok'=>false,'msg'=>'VM ID, Host, and Name are required.']);
        $rid = (int)($_POST['id'] ?? 0);
        try {
            if ($rid) {
                $st = $db->prepare(
                    "UPDATE vms SET vm_id=?,host_id=?,name=?,ip=?,mac=?,vm_type=?,status=?,notes=? WHERE id=?"
                );
                $st->bind_param('iissssssi',$d['vm_id'],$d['host_id'],$d['name'],
                    $d['ip'],$d['mac'],$d['vm_type'],$d['status'],$d['notes'],$rid);
            } else {
                $st = $db->prepare(
                    "INSERT INTO vms (vm_id,host_id,name,ip,mac,vm_type,status,notes) VALUES (?,?,?,?,?,?,?,?)"
                );
                $st->bind_param('iissssss',$d['vm_id'],$d['host_id'],$d['name'],
                    $d['ip'],$d['mac'],$d['vm_type'],$d['status'],$d['notes']);
            }
            $st->execute();
            json_out(['ok'=>true,'id'=>$rid ?: $db->insert_id]);
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode()===1062) json_out(['ok'=>false,'msg'=>'That VMID already exists on this host.']);
            throw $e;
        }
    }

    // ── VM: delete ──────────────────────────────────────────
    if ($act === 'vm_delete') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $db->prepare("DELETE FROM vms WHERE id=?");
        $st->bind_param('i', $id);
        $st->execute();
        json_out(['ok'=>true]);
    }

    // ── Host: list ──────────────────────────────────────────
    if ($act === 'host_list') {
        $rows = $db->query(
            "SELECT h.*, COUNT(v.id) AS vm_count
             FROM   proxmox_hosts h
             LEFT JOIN vms v ON v.host_id=h.id
             GROUP BY h.id ORDER BY h.name"
        )->fetch_all(MYSQLI_ASSOC);
        // mask token secret for display
        foreach ($rows as &$r) {
            $r['token_set'] = !empty($r['api_token']);
            $r['api_token'] = ''; // never send to browser
        }
        unset($r);
        json_out(['ok'=>true,'rows'=>$rows]);
    }

    // ── Host: get single (token redacted) ───────────────────
    if ($act === 'host_get') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $db->prepare("SELECT * FROM proxmox_hosts WHERE id=?");
        $st->bind_param('i', $id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        
        if (!$row) {
            json_out(['ok'=>false, 'msg'=>'Host not found.']);
        }
        
        $row['api_token'] = ''; // never expose secret
        json_out(['ok'=>true, 'host'=>$row]);
    }

    // ── Host: save ──────────────────────────────────────────
    if ($act === 'host_save') {
            $name     = trim($_POST['name']     ?? '');
            $hostname = trim($_POST['hostname'] ?? '');
            $mac      = trim($_POST['mac']      ?? '') ?: null;
            $port     = max(1, (int)($_POST['api_port'] ?? 8006));
            $token    = trim($_POST['api_token'] ?? '');
            $ssl      = (int)!empty($_POST['verify_ssl']);
            $notes    = trim($_POST['notes']    ?? '') ?: null;

            if (!$name || !$hostname) 
                json_out(['ok'=>false,'msg'=>'Name and Hostname are required.']);

            $rid = (int)($_POST['id'] ?? 0);

            try {
                if ($rid) {
                    // UPDATE existing host
                    if ($token !== '') {
                        // Token is being changed
                        $st = $db->prepare(
                            "UPDATE proxmox_hosts 
                            SET name=?, hostname=?, mac=?, api_port=?, api_token=?, verify_ssl=?, notes=? 
                            WHERE id=?"
                        );
                        $st->bind_param('sssisisi', $name, $hostname, $mac, $port, $token, $ssl, $notes, $rid);
                    } else {
                        // Keep existing token (no token column updated)
                        $st = $db->prepare(
                            "UPDATE proxmox_hosts 
                            SET name=?, hostname=?, mac=?, api_port=?, verify_ssl=?, notes=? 
                            WHERE id=?"
                        );
                        $st->bind_param('sssissi', $name, $hostname, $mac, $port, $ssl, $notes, $rid);
                    }
                } else {
                    // INSERT new host
                    $tok = $token ?: null;
                    $st = $db->prepare(
                        "INSERT INTO proxmox_hosts (name,hostname,mac,api_port,api_token,verify_ssl,notes) 
                        VALUES (?,?,?,?,?,?,?)"
                    );
                    $st->bind_param('sssisis', $name, $hostname, $mac, $port, $tok, $ssl, $notes);
                }

                $st->execute();
                json_out(['ok'=>true, 'id' => $rid ?: $db->insert_id]);
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() === 1062) 
                    json_out(['ok'=>false, 'msg'=>'A host with that name already exists.']);
                throw $e;
            }
        }

    // ── Host: delete ────────────────────────────────────────
    if ($act === 'host_delete') {
        $id = (int)($_POST['id'] ?? 0);
        $cnt = $db->query("SELECT COUNT(*) FROM vms WHERE host_id=$id")->fetch_row()[0]; // this one is safe
        if ($cnt > 0) json_out(['ok'=>false,'msg'=>"Cannot delete — $cnt VM(s) still assigned to this host."]);
        
        $st = $db->prepare("DELETE FROM proxmox_hosts WHERE id=?");
        $st->bind_param('i', $id);
        $st->execute();
        json_out(['ok'=>true]);
    }

    // ── Host: sync from Proxmox API ─────────────────────────
    if ($act === 'host_sync') {
        $host_id = (int)($_POST['host_id'] ?? 0);
        
        $st = $db->prepare("SELECT * FROM proxmox_hosts WHERE id=?");
        $st->bind_param('i', $host_id);
        $st->execute();
        $host = $st->get_result()->fetch_assoc();
        
        if (!$host) json_out(['ok'=>false,'msg'=>'Host not found.']);
        if (!$host['api_token']) json_out(['ok'=>false,'msg'=>'No API token configured for this host.']);

        $port = (int)$host['api_port'];
        $is_pbs = ($port === 8007);

        $base    = "https://{$host['hostname']}:{$port}/api2/json";
        $token_prefix = $is_pbs ? 'PBSAPIToken' : 'PVEAPIToken';
        $headers = ["Authorization: $token_prefix={$host['api_token']}"];
        $verify  = (bool)$host['verify_ssl'];

        // Try to get nodes list
        $nodes = pve_get("$base/nodes", $headers, $verify);
        
        if (!$nodes) {
            // Fallback: try common PBS node name or direct call
            $node_fallback = $host['hostname'] ?? 'localhost';
            $nodes = pve_get("$base/nodes/$node_fallback", $headers, $verify) ? [['node' => $node_fallback]] : [];
            if (empty($nodes)) {
                json_out(['ok'=>false,'msg'=>'Could not reach Proxmox API. Check token permissions and port.']);
            }
        }

        $node   = $nodes[0]['node'] ?? $host['hostname'] ?? 'localhost';
        $synced = 0;

        $upsert_vm = function(int $vmid, string $name, string $type, string $status, ?string $mac) use ($host_id, $db) {
            $st = $db->prepare(
                "INSERT INTO vms (vm_id,host_id,name,vm_type,status,mac,last_synced)
                 VALUES (?,?,?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE
                   name=VALUES(name), status=VALUES(status), vm_type=VALUES(vm_type),
                   mac=COALESCE(VALUES(mac),mac), last_synced=NOW()"
            );
            $st->bind_param('iissss', $vmid, $host_id, $name, $type, $status, $mac);
            $st->execute();
        };

        if (!$is_pbs) {
            // PVE: QEMU + LXC
            foreach (pve_get("$base/nodes/$node/qemu", $headers, $verify) ?? [] as $vm) {
                $vmid = (int)($vm['vmid'] ?? 0);
                if ($vmid === 0) continue;
                $status = in_array($vm['status'] ?? '', ['running','stopped']) ? $vm['status'] : 'unknown';
                $type   = stripos($vm['name'] ?? '', 'emplate') !== false ? 'template' : 'qemu';

                $cfg = pve_get("$base/nodes/$node/qemu/$vmid/config", $headers, $verify) ?? [];
                $mac = null;
                foreach ($cfg as $k => $v) {
                    if (preg_match('/^net\d+/', $k) && preg_match('/([0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5})/', (string)$v, $m)) {
                        $mac = strtolower($m[1]);
                        break;
                    }
                }
                $upsert_vm($vmid, $vm['name'] ?? "VM-$vmid", $type, $status, $mac);
                $synced++;
            }

            foreach (pve_get("$base/nodes/$node/lxc", $headers, $verify) ?? [] as $ct) {
                $vmid = (int)($ct['vmid'] ?? 0);
                if ($vmid === 0) continue;
                $status = in_array($ct['status'] ?? '', ['running','stopped']) ? $ct['status'] : 'unknown';

                $cfg = pve_get("$base/nodes/$node/lxc/$vmid/config", $headers, $verify) ?? [];
                $mac = null;
                foreach ($cfg as $k => $v) {
                    if (preg_match('/^net\d+/', $k) && preg_match('/hwaddr=([0-9A-Fa-f:]{17})/i', (string)$v, $m)) {
                        $mac = strtolower($m[1]);
                        break;
                    }
                }
                $upsert_vm($vmid, $ct['name'] ?? "CT-$vmid", 'lxc', $status, $mac);
                $synced++;
            }
        } else {
            // PBS: Sync datastores
            $datastores = pve_get("$base/admin/datastore", $headers, $verify) ?? [];
            foreach ($datastores as $ds) {
                $ds_name = trim($ds['name'] ?? '');
                if ($ds_name) {
                    $upsert_vm(0, "Datastore: $ds_name", 'pbs', 'running', null);
                    $synced++;
                }
            }
        }

        json_out(['ok'=>true, 'synced'=>$synced, 'node'=>$node]);
    }

    // ── Hosts dropdown for VM form ───────────────────────────
    if ($act === 'hosts_dropdown') {
        $rows = $db->query("SELECT id,name FROM proxmox_hosts ORDER BY name")->fetch_all(MYSQLI_ASSOC);
        json_out(['ok'=>true,'hosts'=>$rows]);
    }


    // ── VM: info panel (live from Proxmox API) ─────────────────
    if ($act === 'vm_info') {
        $vm_db_id = (int)($_POST['id'] ?? 0);
        $st = $db->prepare(
            "SELECT v.*, h.hostname, h.api_port, h.api_token, h.verify_ssl, h.name AS host_name
             FROM vms v JOIN proxmox_hosts h ON h.id = v.host_id WHERE v.id = ?"
        );
        $st->bind_param('i', $vm_db_id);
        $st->execute();
        $vm = $st->get_result()->fetch_assoc();
        if (!$vm) json_out(['ok' => false, 'msg' => 'VM not found in database.']);
        if (!$vm['api_token']) json_out(['ok' => false, 'msg' => 'No API token configured for host "' . $vm['host_name'] . '". Add one in the Hosts tab.']);

        $base    = "https://{$vm['hostname']}:{$vm['api_port']}/api2/json";
        $headers = ["Authorization: PVEAPIToken={$vm['api_token']}"];
        $verify  = (bool)$vm['verify_ssl'];
        $vmid    = (int)$vm['vm_id'];
        $type    = $vm['vm_type'];

        // Discover node name
        $nodes = pve_get("$base/nodes", $headers, $verify);
        if (!$nodes) json_out(['ok' => false, 'msg' => 'Cannot reach Proxmox API on host "' . $vm['host_name'] . '".']);
        $node = $nodes[0]['node'];

        // ── Proxmox host version info (/nodes/{node}/status) ──
        $node_status = pve_get("$base/nodes/$node/status", $headers, $verify) ?? [];
        $pve_version = $node_status['pveversion'] ?? null;
        $kernel_info = $node_status['current-kernel'] ?? [];
        $kernel_str  = isset($kernel_info['release']) ? $kernel_info['release'] : null;
        $host_cpu    = $node_status['cpuinfo']['model'] ?? null;
        $host_cpus   = $node_status['cpuinfo']['cpus']  ?? null;
        $host_mem    = $node_status['memory'] ?? [];
        $host_rootfs = $node_status['rootfs'] ?? [];

        $out = [
            'ok'          => true,
            'name'        => $vm['name'],
            'vmid'        => $vmid,
            'type'        => $type,
            'host'        => $vm['host_name'],
            'pve_version' => $pve_version,
            'pve_kernel'  => $kernel_str,
            'host_cpu'    => $host_cpu,
            'host_cpus'   => $host_cpus,
            'host_mem'    => $host_mem,
            'host_rootfs' => $host_rootfs,
        ];

        // Helper: parse a Proxmox netN config string into parts
        $parse_net = function(string $raw): array {
            $parts = [];
            foreach (explode(',', $raw) as $seg) {
                [$pk, $pv] = array_pad(explode('=', $seg, 2), 2, '');
                $parts[trim($pk)] = trim($pv);
            }
            return $parts;
        };

        if ($type === 'qemu' || $type === 'template') {
            // ── Config ──────────────────────────────────────────
            $cfg = pve_get("$base/nodes/$node/qemu/$vmid/config", $headers, $verify) ?? [];

            // CPU
            $out['cpu_cores']   = $cfg['cores']   ?? null;
            $out['cpu_sockets'] = $cfg['sockets']  ?? 1;
            $out['cpu_type']    = $cfg['cpu']      ?? 'kvm64';
            $out['total_cores'] = ($cfg['cores'] ?? 1) * ($cfg['sockets'] ?? 1);
            $out['cpu_limit']   = $cfg['cpulimit'] ?? null;  // throttle cap if set
            $out['cpu_units']   = $cfg['cpuunits'] ?? null;  // scheduler weight

            // RAM
            $out['ram_mb']      = $cfg['memory']  ?? null;
            $out['balloon']     = $cfg['balloon']  ?? null;  // min balloon RAM; 0 = disabled
            $out['shares']      = $cfg['shares']   ?? null;

            // Boot / start
            $out['on_boot'] = isset($cfg['onboot']) ? (bool)$cfg['onboot'] : false;
            $out['boot']    = $cfg['boot']    ?? null;
            $out['startup'] = $cfg['startup'] ?? null;  // order/up/down delays

            // Description / tags / protection
            $out['description'] = $cfg['description'] ?? null;
            $out['tags']        = $cfg['tags']        ?? null;
            $out['protection']  = !empty($cfg['protection']);
            $out['template']    = !empty($cfg['template']);

            // OS / machine
            $out['ostype']  = $cfg['ostype']  ?? null;
            $out['machine'] = $cfg['machine'] ?? 'i440fx';
            $out['bios']    = $cfg['bios']    ?? 'seabios';
            $out['smbios1'] = $cfg['smbios1'] ?? null;

            // NUMA / hugepages / CPU flags
            $out['numa']      = !empty($cfg['numa']);
            $out['hugepages'] = $cfg['hugepages'] ?? null;

            // QEMU agent — configured state
            $out['agent_enabled']    = false;
            $out['agent_type']       = null;
            $out['agent_freeze_fs']  = false;
            if (isset($cfg['agent'])) {
                $agent_str = (string)$cfg['agent'];
                // agent can be "1", "enabled=1", "enabled=1,type=virtio,freeze-fs-on-backup=1"
                $out['agent_enabled']   = str_contains($agent_str, 'enabled=1') || $agent_str === '1';
                $out['agent_freeze_fs'] = str_contains($agent_str, 'freeze-fs-on-backup=1');
                if (preg_match('/type=([^,]+)/', $agent_str, $am)) $out['agent_type'] = $am[1];
            }

            // Network interfaces
            $nets = [];
            foreach ($cfg as $k => $v) {
                if (!preg_match('/^net(\d+)$/', $k, $m)) continue;
                $parts  = $parse_net((string)$v);
                $mac    = '';
                $model  = '';
                // The model key holds the MAC as its value: e.g. "virtio=BC:24:11:xx:xx:xx"
                foreach ($parts as $pk => $pv) {
                    if (preg_match('/([0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5})/', $pv, $mm)) {
                        $mac = strtolower($mm[1]);
                        $model = $pk;
                        break;
                    }
                }
                $nets[] = [
                    'id'        => 'net'.$m[1],
                    'mac'       => $mac,
                    'model'     => $model,
                    'bridge'    => $parts['bridge']   ?? '',
                    'vlan'      => $parts['tag']      ?? '',
                    'firewall'  => !empty($parts['firewall']),
                    'link_down' => !empty($parts['link_down']),
                    'rate'      => $parts['rate']     ?? '',
                ];
            }
            $out['networks'] = $nets;

            // Disks (all bus types)
            $disk_prefixes = ['ide','sata','scsi','virtio','efidisk','tpmstate'];
            $disks = [];
            foreach ($cfg as $k => $v) {
                $hit = false;
                foreach ($disk_prefixes as $p) { if (preg_match('/^'.$p.'\d*$/', $k)) { $hit = true; break; } }
                if (!$hit) continue;
                $vs = (string)$v;
                if (str_contains($vs, 'media=cdrom') || str_contains($vs, 'none')) continue;
                $size_raw = '';
                if (preg_match('/size=([^,]+)/', $vs, $sm)) $size_raw = $sm[1];
                $storage  = '';
                if (preg_match('/^([^:]+):/', $vs, $stm)) $storage = $stm[1];
                $cache    = '';
                if (preg_match('/cache=([^,]+)/', $vs, $cm)) $cache = $cm[1];
                $ssd      = str_contains($vs, 'ssd=1') || str_contains($vs, 'discard=on');
                $disks[]  = [
                    'key'      => $k,
                    'storage'  => $storage,
                    'size_cfg' => $size_raw,
                    'cache'    => $cache,
                    'ssd_hint' => $ssd,
                    'raw'      => $vs,
                ];
            }
            $out['disks'] = $disks;

            // ── Current runtime status ───────────────────────────
            $status = pve_get("$base/nodes/$node/qemu/$vmid/status/current", $headers, $verify) ?? [];
            $out['status']         = $status['status']   ?? $vm['status'];
            $out['uptime']         = $status['uptime']   ?? null;
            $out['cpu_pct']        = isset($status['cpu'])    ? round($status['cpu'] * 100, 1) : null;
            $out['mem_used_bytes'] = $status['mem']      ?? null;
            $out['mem_max_bytes']  = $status['maxmem']   ?? null;
            $out['disk_read']      = $status['diskread']  ?? null;  // bytes since boot
            $out['disk_write']     = $status['diskwrite'] ?? null;
            $out['net_in']         = $status['netin']     ?? null;
            $out['net_out']        = $status['netout']    ?? null;
            $out['qmp_status']     = $status['qmpstatus'] ?? null;  // e.g. "running","paused"
            $out['balloon_actual'] = $status['balloon']   ?? null;  // actual balloon allocation

            // ── QEMU Agent ───────────────────────────────────────
            $out['agent_data']    = null;
            $out['agent_running'] = false;

            if ($out['agent_enabled'] && ($status['status'] ?? '') === 'running') {
                // Ping the agent to confirm it's actually responding
                $agent_ping = pve_post("$base/nodes/$node/qemu/$vmid/agent/ping", $headers, $verify);
                $out['agent_running'] = ($agent_ping !== null);

                if ($out['agent_running']) {
                    // OS info
                    $os_raw = pve_get("$base/nodes/$node/qemu/$vmid/agent/get-osinfo", $headers, $verify);
                    if ($os_raw) $out['agent_data']['osinfo'] = $os_raw['result'] ?? $os_raw;

                    // Hostname from agent (may differ from config)
                    $hn_raw = pve_get("$base/nodes/$node/qemu/$vmid/agent/get-host-name", $headers, $verify);
                    if ($hn_raw) $out['agent_data']['hostname'] = $hn_raw['result']['host-name'] ?? null;

                    // Timezone
                    $tz_raw = pve_get("$base/nodes/$node/qemu/$vmid/agent/get-timezone", $headers, $verify);
                    if ($tz_raw) $out['agent_data']['timezone'] = $tz_raw['result']['zone'] ?? null;

                    // Network interfaces with IPs (richer than config netN)
                    $ni_raw = pve_get("$base/nodes/$node/qemu/$vmid/agent/network-get-interfaces", $headers, $verify);
                    if ($ni_raw) {
                        $ifaces = [];
                        $items  = $ni_raw['result'] ?? $ni_raw;
                        if (is_array($items)) {
                            foreach ($items as $iface) {
                                if (($iface['name'] ?? '') === 'lo') continue;
                                $ips = [];
                                foreach ($iface['ip-addresses'] ?? [] as $ip) {
                                    $ips[] = $ip['ip-address'] . '/' . $ip['prefix'];
                                }
                                $ifaces[] = [
                                    'name'  => $iface['name']           ?? '',
                                    'mac'   => strtolower($iface['hardware-address'] ?? ''),
                                    'ips'   => $ips,
                                ];
                            }
                        }
                        $out['agent_data']['interfaces'] = $ifaces;
                    }

                    // Filesystem info (disk usage)
                    $fs_raw = pve_get("$base/nodes/$node/qemu/$vmid/agent/get-fsinfo", $headers, $verify);
                    if ($fs_raw) {
                        $filesystems = [];
                        $items = $fs_raw['result'] ?? $fs_raw;
                        if (is_array($items)) {
                            foreach ($items as $fs) {
                                if (!isset($fs['total-bytes'])) continue;
                                $filesystems[] = [
                                    'name'       => $fs['name']       ?? '',
                                    'type'       => $fs['type']       ?? '',
                                    'mountpoint' => $fs['mountpoint'] ?? '',
                                    'total'      => (int)($fs['total-bytes'] ?? 0),
                                    'used'       => (int)($fs['used-bytes']  ?? 0),
                                    'disk'       => $fs['disk'][0]['dev']    ?? '',
                                ];
                            }
                        }
                        $out['agent_data']['filesystems'] = $filesystems;
                    }
                }
            }

        } else {
            // ── LXC ───────────────────────────────────────────────
            $cfg = pve_get("$base/nodes/$node/lxc/$vmid/config", $headers, $verify) ?? [];

            $out['cpu_cores']   = $cfg['cores']    ?? null;
            $out['cpu_units']   = $cfg['cpuunits'] ?? null;
            $out['cpu_limit']   = $cfg['cpulimit'] ?? null;
            $out['ram_mb']      = $cfg['memory']   ?? null;
            $out['swap_mb']     = $cfg['swap']     ?? null;
            $out['on_boot']     = isset($cfg['onboot']) ? (bool)$cfg['onboot'] : false;
            $out['startup']     = $cfg['startup']     ?? null;
            $out['ostype']      = $cfg['ostype']      ?? null;
            $out['hostname']    = $cfg['hostname']     ?? null;
            $out['description'] = $cfg['description'] ?? null;
            $out['tags']        = $cfg['tags']         ?? null;
            $out['protection']  = !empty($cfg['protection']);
            $out['unprivileged']= !empty($cfg['unprivileged']);
            $out['nesting']     = str_contains((string)($cfg['features'] ?? ''), 'nesting=1');

            // LXC networks
            $nets = [];
            foreach ($cfg as $k => $v) {
                if (!preg_match('/^net(\d+)$/', $k, $m)) continue;
                $parts = $parse_net((string)$v);
                $nets[] = [
                    'id'       => 'net'.$m[1],
                    'mac'      => strtolower($parts['hwaddr']  ?? ''),
                    'bridge'   => $parts['bridge']  ?? '',
                    'ip'       => $parts['ip']      ?? '',
                    'ip6'      => $parts['ip6']     ?? '',
                    'gw'       => $parts['gw']      ?? '',
                    'name'     => $parts['name']    ?? '',
                    'firewall' => !empty($parts['firewall']),
                    'rate'     => $parts['rate']    ?? '',
                ];
            }
            $out['networks'] = $nets;

            // LXC mounts
            $disks = [];
            foreach ($cfg as $k => $v) {
                if (!preg_match('/^(rootfs|mp\d+)$/', $k)) continue;
                $vs       = (string)$v;
                $size_raw = '';
                if (preg_match('/size=([^,]+)/', $vs, $sm)) $size_raw = $sm[1];
                $storage  = '';
                if (preg_match('/^([^:]+):/', $vs, $stm)) $storage = $stm[1];
                $ro       = str_contains($vs, 'ro=1');
                $disks[]  = ['key' => $k, 'storage' => $storage, 'size_cfg' => $size_raw, 'ro' => $ro, 'raw' => $vs];
            }
            $out['disks'] = $disks;

            // LXC status
            $status = pve_get("$base/nodes/$node/lxc/$vmid/status/current", $headers, $verify) ?? [];
            $out['status']         = $status['status']   ?? $vm['status'];
            $out['uptime']         = $status['uptime']   ?? null;
            $out['cpu_pct']        = isset($status['cpu'])    ? round($status['cpu'] * 100, 1) : null;
            $out['mem_used_bytes'] = $status['mem']      ?? null;
            $out['mem_max_bytes']  = $status['maxmem']   ?? null;
            $out['disk_read']      = $status['diskread']  ?? null;
            $out['disk_write']     = $status['diskwrite'] ?? null;
            $out['net_in']         = $status['netin']     ?? null;
            $out['net_out']        = $status['netout']    ?? null;
        }

        json_out($out);
    }

    json_out(['ok'=>false,'msg'=>'Unknown action']);
}

// ── Build host options for initial page render ───────────────
$hosts = db()->query("SELECT id,name FROM proxmox_hosts ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$host_opts = implode('', array_map(fn($h)=>"<option value=\"{$h['id']}\">{$h['name']}</option>", $hosts));
$host_filter_opts = implode('', array_map(fn($h)=>"<option value=\"{$h['name']}\">{$h['name']}</option>", $hosts));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Proxmox VM Manager</title>
<style>
:root {
  --bg:       #0d1117;
  --surf:     #161b22;
  --surf2:    #1c2330;
  --border:   #30363d;
  --accent:   #f78166;
  --blue:     #58a6ff;
  --green:    #3fb950;
  --yellow:   #d29922;
  --red:      #f85149;
  --purple:   #bc8cff;
  --text:     #c9d1d9;
  --dim:      #8b949e;
  --r:        6px;
  --mono:     'JetBrains Mono','Cascadia Code','Fira Mono',monospace;
  --sans:     'Inter',system-ui,sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:var(--sans);font-size:14px;line-height:1.5;min-height:100vh}

/* Header */
header{background:var(--surf);border-bottom:1px solid var(--border);padding:0 24px;display:flex;align-items:center;gap:12px;height:56px;position:sticky;top:0;z-index:100}
.logo{font-family:var(--mono);font-size:16px;font-weight:700;color:var(--accent);letter-spacing:-.5px}
.logo span{color:var(--dim);font-weight:400}

/* Tabs */
.tabs{display:flex;gap:2px;background:var(--bg);padding:16px 24px 0}
.tab-btn{padding:9px 20px;border:1px solid transparent;border-bottom:none;border-radius:var(--r) var(--r) 0 0;background:var(--surf2);color:var(--dim);cursor:pointer;font-size:13px;font-weight:500;transition:color .15s,background .15s}
.tab-btn:hover{color:var(--text)}
.tab-btn.active{background:var(--surf);border-color:var(--border);color:var(--text);position:relative;top:1px}
.tab-content{display:none;padding:20px 24px 24px;background:var(--surf);border:1px solid var(--border);border-radius:0 var(--r) var(--r) var(--r);margin:0 24px 24px}
.tab-content.active{display:block}

/* Stats bar */
.stats-bar{display:flex;gap:24px;padding:12px 18px;background:var(--surf2);border:1px solid var(--border);border-radius:var(--r);margin-bottom:16px;flex-wrap:wrap}
.stat{display:flex;flex-direction:column;gap:2px}
.stat-val{font-size:22px;font-weight:700;font-family:var(--mono);color:var(--accent)}
.stat-lbl{font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.06em}

/* Toolbar */
.toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
.search-wrap{position:relative;flex:1;min-width:200px;max-width:380px}
.search-wrap input{width:100%;padding:7px 10px 7px 32px;background:var(--bg);border:1px solid var(--border);border-radius:var(--r);color:var(--text);font-size:14px}
.si{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--dim);pointer-events:none;font-size:13px}
input:focus,select:focus,textarea:focus{outline:2px solid var(--blue);outline-offset:-1px}
select.fc{padding:7px 10px;background:var(--bg);border:1px solid var(--border);border-radius:var(--r);color:var(--text);font-size:13px;cursor:pointer}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:var(--r);border:1px solid transparent;font-size:13px;font-weight:500;cursor:pointer;transition:opacity .15s;white-space:nowrap;text-decoration:none;font-family:inherit}
.btn:hover{opacity:.82}
.btn-primary{background:var(--accent);color:#fff}
.btn-secondary{background:var(--surf2);border-color:var(--border);color:var(--text)}
.btn-danger{background:transparent;border-color:var(--red);color:var(--red)}
.btn-blue{background:transparent;border-color:var(--blue);color:var(--blue)}
.btn-green{background:transparent;border-color:var(--green);color:var(--green)}
.btn-sm{padding:4px 10px;font-size:12px}

/* Table */
.tbl-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:var(--r);background:var(--surf)}
table{width:100%;border-collapse:collapse}
thead{background:var(--surf2)}
th{padding:9px 14px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--dim);white-space:nowrap;user-select:none}
th.sortable{cursor:pointer}
th.sortable:hover{color:var(--text)}
th.active-sort{color:var(--blue)}
td{padding:9px 14px;border-top:1px solid var(--border);vertical-align:middle;font-size:13px}
tr:hover td{background:rgba(255,255,255,.025)}
tr.dup-row td{background:rgba(210,153,34,.06)}

/* Badges & chips */
.vmid{font-family:var(--mono);font-size:13px;font-weight:700;color:var(--blue)}
.vmid.dup{color:var(--yellow)}
.dup-badge{display:inline-block;background:var(--yellow);color:#000;font-size:10px;font-weight:700;padding:1px 5px;border-radius:3px;margin-left:4px;vertical-align:middle}
.host-chip{display:inline-block;background:var(--surf2);border:1px solid var(--border);border-radius:4px;padding:2px 8px;font-size:12px;font-family:var(--mono);color:var(--dim)}
.type-badge{display:inline-block;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:600;text-transform:uppercase}
.t-qemu{background:rgba(88,166,255,.15);color:var(--blue)}
.t-lxc{background:rgba(63,185,80,.15);color:var(--green)}
.t-template{background:rgba(139,148,158,.15);color:var(--dim)}
.dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:5px;vertical-align:middle}
.s-running{background:var(--green);box-shadow:0 0 6px var(--green)}
.s-stopped{background:var(--dim)}
.s-unknown{background:var(--yellow)}
.mono-sm{font-family:var(--mono);font-size:12px;color:var(--dim)}
.notes-cell{max-width:200px;color:var(--dim);font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.notes-cell.warn{color:var(--yellow)}
.token-dot{width:8px;height:8px;border-radius:50%;display:inline-block}
.tk-yes{background:var(--green)}
.tk-no{background:var(--red)}
.act-btns{display:flex;gap:6px}
.row-count{font-size:12px;color:var(--dim);text-align:right;margin-top:6px}
.empty,.loading{text-align:center;padding:40px;color:var(--dim)}

/* Modal */
.backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:200;align-items:center;justify-content:center}
.backdrop.open{display:flex}
.modal{background:var(--surf);border:1px solid var(--border);border-radius:10px;width:100%;max-width:540px;max-height:92vh;overflow-y:auto;padding:24px;position:relative}
.modal h2{font-size:16px;margin-bottom:18px}
.modal .x{position:absolute;top:14px;right:16px;background:none;border:none;color:var(--dim);font-size:22px;cursor:pointer;line-height:1}
.fg{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.full{grid-column:1/-1}
label{display:block;font-size:12px;color:var(--dim);margin-bottom:4px}
.fc{width:100%;padding:8px 10px;background:var(--bg);border:1px solid var(--border);border-radius:var(--r);color:var(--text);font-size:13px;font-family:inherit}
textarea.fc{resize:vertical;min-height:60px}
.hint{font-size:11px;color:var(--dim);margin-top:4px}
.form-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px;padding-top:14px;border-top:1px solid var(--border)}


/* VM Info Panel */
.info-modal{max-width:700px}
.info-section{margin-bottom:20px}
.info-section-title{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--dim);margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid var(--border)}
.info-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px}
.info-item{background:var(--surf2);border:1px solid var(--border);border-radius:var(--r);padding:10px 12px}
.info-label{font-size:11px;color:var(--dim);margin-bottom:3px;text-transform:uppercase;letter-spacing:.04em}
.info-value{font-family:var(--mono);font-size:13px;color:var(--text);word-break:break-all}
.info-value.good{color:var(--green)}
.info-value.warn{color:var(--yellow)}
.info-value.off{color:var(--dim)}
.disk-row{background:var(--surf2);border:1px solid var(--border);border-radius:var(--r);padding:10px 12px;margin-bottom:8px}
.disk-row-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.disk-key{font-family:var(--mono);font-size:12px;font-weight:700;color:var(--blue)}
.disk-size{font-family:var(--mono);font-size:12px;color:var(--text)}
.disk-bar-wrap{height:6px;background:var(--border);border-radius:3px;overflow:hidden;margin-bottom:4px}
.disk-bar{height:100%;border-radius:3px;background:var(--blue);transition:width .4s}
.disk-bar.warn{background:var(--yellow)}
.disk-bar.crit{background:var(--red)}
.disk-meta{font-size:11px;color:var(--dim)}
.net-row{display:flex;gap:12px;flex-wrap:wrap;background:var(--surf2);border:1px solid var(--border);border-radius:var(--r);padding:9px 12px;margin-bottom:6px;font-size:12px}
.net-id{font-family:var(--mono);font-weight:700;color:var(--purple);min-width:40px}
.net-kv{color:var(--dim)}
.net-kv span{color:var(--text);font-family:var(--mono)}
.info-loading{text-align:center;padding:40px;color:var(--dim)}
.uptime-badge{display:inline-block;background:rgba(63,185,80,.12);border:1px solid var(--green);color:var(--green);font-size:11px;padding:2px 8px;border-radius:4px;font-family:var(--mono)}
.vm-name-link{cursor:pointer;color:var(--text);text-decoration:none;border-bottom:1px dashed var(--border);transition:color .15s, border-color .15s}
.vm-name-link:hover{color:var(--blue);border-color:var(--blue)}
/* Toast */
.toast{position:fixed;bottom:24px;right:24px;padding:11px 17px;border-radius:var(--r);font-size:13px;z-index:999;transform:translateY(80px);opacity:0;transition:transform .22s,opacity .22s;max-width:340px}
.toast.show{transform:none;opacity:1}
.toast.ok{background:#1a3328;border:1px solid var(--green);color:var(--green)}
.toast.err{background:#2d1116;border:1px solid var(--red);color:var(--red)}

/* Host card grid */
.host-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
.host-card{background:var(--surf2);border:1px solid var(--border);border-radius:var(--r);padding:16px}
.host-card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.host-card-name{font-family:var(--mono);font-size:15px;font-weight:700;color:var(--accent)}
.host-card-body{font-size:12px;color:var(--dim);display:flex;flex-direction:column;gap:5px}
.host-card-row{display:flex;justify-content:space-between}
.host-card-val{font-family:var(--mono);color:var(--text)}
.host-card-footer{margin-top:12px;padding-top:10px;border-top:1px solid var(--border);display:flex;gap:8px;flex-wrap:wrap}
</style>
</head>
<body>

<header>
  <div class="logo">pve<span>://</span>vmgr</div>
</header>

<div class="tabs">
  <button class="tab-btn active" onclick="switchTab('vms',this)">🖥 VMs &amp; Containers</button>
  <button class="tab-btn" onclick="switchTab('hosts',this)">🗄 Hosts</button>
</div>

<!-- ══════════════════════════════════════════════════════════
     TAB: VMs
══════════════════════════════════════════════════════════════ -->
<div class="tab-content active" id="tab-vms">

  <div class="stats-bar">
    <div class="stat"><span class="stat-val" id="st-total">–</span><span class="stat-lbl">Total</span></div>
    <div class="stat"><span class="stat-val" style="color:var(--green)" id="st-run">–</span><span class="stat-lbl">Running</span></div>
    <div class="stat"><span class="stat-val" style="color:var(--dim)" id="st-stop">–</span><span class="stat-lbl">Stopped</span></div>
    <div class="stat"><span class="stat-val" style="color:var(--dim)" id="st-tmpl">–</span><span class="stat-lbl">Templates</span></div>
    <div class="stat"><span class="stat-val" style="color:var(--yellow)" id="st-dup">–</span><span class="stat-lbl">Dup VMIDs</span></div>
  </div>

  <div class="toolbar">
    <div class="search-wrap">
      <span class="si">🔍</span>
      <input type="text" id="vmSearch" placeholder="Search VMID, name, host, IP, notes…" oninput="vmDebounce()">
    </div>
    <select class="fc" id="vmFilterHost" onchange="loadVMs()" style="width:auto">
      <option value="">All Hosts</option>
      <?= $host_filter_opts ?>
    </select>
    <select class="fc" id="vmFilterType" onchange="loadVMs()" style="width:auto">
      <option value="">All Types</option>
      <option value="qemu">QEMU</option>
      <option value="lxc">LXC</option>
      <option value="template">Template</option>
    </select>
    <div style="flex:1"></div>
    <button class="btn btn-secondary" onclick="openVMModal()">+ Add VM</button>
  </div>

  <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th class="sortable" data-col="vm_id">VMID <span class="si2">↕</span></th>
        <th class="sortable" data-col="name">Name <span class="si2">↕</span></th>
        <th class="sortable" data-col="host">Host <span class="si2">↕</span></th>
        <th class="sortable" data-col="ip">IP Address <span class="si2">↕</span></th>
        <th>MAC Address</th>
        <th class="sortable" data-col="vm_type">Type <span class="si2">↕</span></th>
        <th class="sortable" data-col="status">Status <span class="si2">↕</span></th>
        <th>Notes</th>
        <th>Actions</th>
      </tr></thead>
      <tbody id="vmBody"><tr><td colspan="9" class="loading">Loading…</td></tr></tbody>
    </table>
  </div>
  <div class="row-count" id="vmCount"></div>
</div>

<!-- ══════════════════════════════════════════════════════════
     TAB: Hosts
══════════════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-hosts">
  <div class="toolbar">
    <div style="flex:1"></div>
    <button class="btn btn-secondary" onclick="openHostModal()">+ Add Host</button>
  </div>
  <div class="host-grid" id="hostGrid"><div class="loading">Loading…</div></div>
</div>

<!-- ══ VM Modal ══════════════════════════════════════════════ -->
<div class="backdrop" id="vmBackdrop" onclick="closeIfOutside(event,'vmBackdrop','closeVMModal')">
  <div class="modal">
    <button class="x" onclick="closeVMModal()">×</button>
    <h2 id="vmModalTitle">Add VM</h2>
    <div class="fg">
      <input type="hidden" id="vf-id">
      <div>
        <label>VMID *</label>
        <input type="number" id="vf-vm_id" class="fc" placeholder="e.g. 101" min="1">
      </div>
      <div>
        <label>Host *</label>
        <select id="vf-host_id" class="fc"><?= $host_opts ?></select>
      </div>
      <div class="full">
        <label>Name *</label>
        <input type="text" id="vf-name" class="fc" placeholder="e.g. Docker-Prod">
      </div>
      <div>
        <label>IP Address</label>
        <input type="text" id="vf-ip" class="fc" placeholder="192.168.20.x">
      </div>
      <div>
        <label>MAC Address</label>
        <input type="text" id="vf-mac" class="fc" placeholder="bc:24:11:xx:xx:xx">
      </div>
      <div>
        <label>Type</label>
        <select id="vf-vm_type" class="fc">
          <option value="qemu">QEMU VM</option>
          <option value="lxc">LXC Container</option>
          <option value="template">Template</option>
        </select>
      </div>
      <div>
        <label>Status</label>
        <select id="vf-status" class="fc">
          <option value="unknown">Unknown</option>
          <option value="running">Running</option>
          <option value="stopped">Stopped</option>
        </select>
      </div>
      <div class="full">
        <label>Notes</label>
        <textarea id="vf-notes" class="fc" rows="3" placeholder="Optional…"></textarea>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn btn-secondary" onclick="closeVMModal()">Cancel</button>
      <button class="btn btn-primary" onclick="saveVM()">Save VM</button>
    </div>
  </div>
</div>

<!-- ══ Host Modal ════════════════════════════════════════════ -->
<div class="backdrop" id="hostBackdrop" onclick="closeIfOutside(event,'hostBackdrop','closeHostModal')">
  <div class="modal">
    <button class="x" onclick="closeHostModal()">×</button>
    <h2 id="hostModalTitle">Add Host</h2>
    <div class="fg">
      <input type="hidden" id="hf-id">
      <div>
        <label>Short Name *</label>
        <input type="text" id="hf-name" class="fc" placeholder="e.g. Black">
      </div>
      <div>
        <label>Hostname / IP *</label>
        <input type="text" id="hf-hostname" class="fc" placeholder="192.168.20.22">
      </div>
      <div>
        <label>MAC Address</label>
        <input type="text" id="hf-mac" class="fc" placeholder="aa:bb:cc:dd:ee:ff">
      </div>
      <div>
        <label>API Port</label>
        <input type="number" id="hf-api_port" class="fc" value="8006">
      </div>
      <div class="full">
        <label>API Token</label>
        <input type="password" id="hf-api_token" class="fc" placeholder="user@pam!tokenid=secret  (leave blank to keep existing)">
        <div class="hint">Create in PVE → Datacenter → Permissions → API Tokens. Assign <strong>PVEAuditor</strong> role for read-only sync.</div>
      </div>
      <div class="full" style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" id="hf-verify_ssl" style="width:auto">
        <label for="hf-verify_ssl" style="margin:0;color:var(--text)">Verify SSL certificate (uncheck for self-signed)</label>
      </div>
      <div class="full">
        <label>Notes</label>
        <textarea id="hf-notes" class="fc" rows="2" placeholder="Optional…"></textarea>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn btn-secondary" onclick="closeHostModal()">Cancel</button>
      <button class="btn btn-primary" onclick="saveHost()">Save Host</button>
    </div>
  </div>
</div>

<!-- ══ VM Info Modal ════════════════════════════════════════ -->
<div class="backdrop" id="infoBackdrop">
  <div class="modal info-modal">
    <button class="x" onclick="closeInfoModal()">×</button>
    <h2 id="infoTitle">VM Info</h2>
    <div id="infoBody"><div class="info-loading">Loading from Proxmox API…</div></div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
// ── Tab switching ─────────────────────────────────────────
function switchTab(name, btn) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('tab-' + name).classList.add('active');
  if (name === 'hosts') loadHosts();
}

// ── Generic helpers ───────────────────────────────────────
function esc(s) {
  return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ←←← MOVED UP: Close outside click handler
function closeIfOutside(e, bdId, closeFn) {
  if (e.target === document.getElementById(bdId)) {
    if (closeFn === 'closeVMModal')   closeVMModal();
    if (closeFn === 'closeHostModal') closeHostModal();
  }
}

async function post(fields) {
  try {
    const fd = new FormData();
    Object.entries(fields).forEach(([k,v]) => fd.append(k, v ?? ''));
    const r = await fetch(location.pathname, { method:'POST', body:fd });
    if (!r.ok) {
      console.error('HTTP error', r.status);
      return { ok: false, msg: `HTTP ${r.status}` };
    }
    const json = await r.json();
    return json;
  } catch (err) {
    console.error('Fetch error:', err);
    return { ok: false, msg: 'Network error' };
  }
}

let toastT;
function toast(msg, ok) {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.className = 'toast show ' + (ok ? 'ok' : 'err');
  clearTimeout(toastT);
  toastT = setTimeout(() => el.classList.remove('show'), 3400);
}

// ════════════════════════════════════════════════════════════
//  VMs Tab
// ════════════════════════════════════════════════════════════
let vmSort = 'vm_id', vmDir = 'asc', vmDebT;

document.querySelectorAll('th.sortable').forEach(th => {
  th.addEventListener('click', () => {
    const col = th.dataset.col;
    vmDir = vmSort === col ? (vmDir === 'asc' ? 'desc' : 'asc') : 'asc';
    vmSort = col;
    document.querySelectorAll('th').forEach(t => { t.classList.remove('active-sort'); if(t.querySelector('.si2')) t.querySelector('.si2').textContent='↕'; });
    th.classList.add('active-sort');
    th.querySelector('.si2').textContent = vmDir === 'asc' ? '↑' : '↓';
    loadVMs();
  });
});

function vmDebounce() { clearTimeout(vmDebT); vmDebT = setTimeout(loadVMs, 280); }

async function loadVMs() {
  const body = document.getElementById('vmBody');
  body.innerHTML = '<tr><td colspan="9" class="loading">Loading…</td></tr>';
  const data = await post({ action:'vm_list', sort:vmSort, dir:vmDir, q:document.getElementById('vmSearch').value });
  if (!data.ok) { body.innerHTML=`<tr><td colspan="9" class="empty">${esc(data.msg)}</td></tr>`; return; }

  let rows = data.rows;
  const fH = document.getElementById('vmFilterHost').value;
  const fT = document.getElementById('vmFilterType').value;
  if (fH) rows = rows.filter(r => r.host === fH);
  if (fT) rows = rows.filter(r => r.vm_type === fT);

  document.getElementById('st-total').textContent = rows.length;
  document.getElementById('st-run').textContent   = rows.filter(r=>r.status==='running').length;
  document.getElementById('st-stop').textContent  = rows.filter(r=>r.status==='stopped').length;
  document.getElementById('st-tmpl').textContent  = rows.filter(r=>r.vm_type==='template').length;
  const dupIds = [...new Set(rows.filter(r=>r.dup).map(r=>r.vm_id))];
  document.getElementById('st-dup').textContent   = dupIds.length;
  document.getElementById('vmCount').textContent  = `${rows.length} record${rows.length!==1?'s':''}`;

  if (!rows.length) { body.innerHTML='<tr><td colspan="9" class="empty">No VMs found.</td></tr>'; return; }

  const SC = {running:'s-running',stopped:'s-stopped',unknown:'s-unknown'};
  const TC = {qemu:'t-qemu',lxc:'t-lxc',template:'t-template'};
  // const syn = r => r.last_synced ? `<br><span style="font-size:10px;color:var(--dim)">synced ${r.last_synced.split(' ')[0]}</span>` : '';
  const syn = r => {
    if (!r.last_synced) return '';

    const dt = new Date(r.last_synced.replace(' ', 'T') + 'Z');

    return `<br><span style="font-size:10px;color:var(--dim)">
      synced ${dt.toLocaleString()}
    </span>`;
  };


  body.innerHTML = rows.map(r => `
    <tr class="${r.dup?'dup-row':''}">
      <td><span class="vmid${r.dup?' dup':''}">${r.vm_id}</span>${r.dup?'<span class="dup-badge">DUP</span>':''}</td>
      <td><span class="vm-name-link" data-action="info-vm" data-id="${r.id}">${esc(r.name)}</span>${syn(r)}</td>
      <td><span class="host-chip">${esc(r.host)}</span></td>
      <td><span class="mono-sm">${esc(r.ip||'—')}</span></td>
      <td><span class="mono-sm">${esc(r.mac||'—')}</span></td>
      <td><span class="type-badge ${TC[r.vm_type]||''}">${esc(r.vm_type)}</span></td>
      <td><span class="dot ${SC[r.status]||''}"></span>${esc(r.status)}</td>
      <td><div class="notes-cell${r.notes&&(r.notes.includes('Remove')||r.notes.includes('⚠'))?' warn':''}" title="${esc(r.notes||'')}">${esc(r.notes||'')}</div></td>
      <td><div class="act-btns">
        <button class="btn btn-secondary btn-sm" data-action="edit-vm" data-id="${r.id}">Edit</button>
        <button class="btn btn-danger btn-sm" data-action="del-vm" data-id="${r.id}" data-name="${esc(r.name)}">Del</button>
      </div></td>
    </tr>`).join('');
}

function openVMModal(pre={}) {
  const defaults = { vm_type:'qemu', status:'unknown' };
  ['id','vm_id','host_id','name','ip','mac','vm_type','status','notes'].forEach(k => {
    const el = document.getElementById('vf-'+k);
    if (!el) return;
    const val = (pre[k] !== undefined && pre[k] !== null) ? pre[k] : (defaults[k] ?? '');
    el.value = val;
  });
  document.getElementById('vmModalTitle').textContent = pre.id ? 'Edit VM' : 'Add VM';
  document.getElementById('vmBackdrop').classList.add('open');
}
function closeVMModal() { document.getElementById('vmBackdrop').classList.remove('open'); }

async function editVM(id) {
  if (!id) return toast('Invalid VM ID', false);
  const d = await post({action:'vm_get', id});
  if (d.ok) {
    openVMModal(d.vm);
  } else {
    toast(d.msg || 'Failed to load VM', false);
  }
}

async function saveVM() {
  const fields = {action:'vm_save'};
  ['id','vm_id','host_id','name','ip','mac','vm_type','status','notes'].forEach(k => {
    fields[k] = document.getElementById('vf-'+k)?.value ?? '';
  });
  const d = await post(fields);
  if (d.ok) { toast('VM saved ✓', true); closeVMModal(); loadVMs(); }
  else toast(d.msg||'Save failed', false);
}
async function deleteVM(id, name) {
  if (!confirm(`Delete "${name}"? This cannot be undone.`)) return;
  const d = await post({action:'vm_delete',id});
  if (d.ok) { toast('VM deleted', true); loadVMs(); }
  else toast('Delete failed', false);
}

// ════════════════════════════════════════════════════════════
//  Hosts Tab
// ════════════════════════════════════════════════════════════
async function loadHosts() {
  const grid = document.getElementById('hostGrid');
  grid.innerHTML = '<div class="loading">Loading…</div>';
  const d = await post({action:'host_list'});
  if (!d.ok) { grid.innerHTML='<div class="empty">Error loading hosts.</div>'; return; }

  grid.innerHTML = d.rows.map(h => `
    <div class="host-card">
      <div class="host-card-header">
        <span class="host-card-name">${esc(h.name)}</span>
        <span class="type-badge t-qemu" style="font-size:11px">${h.vm_count} VM${h.vm_count!=1?'s':''}</span>
      </div>
      <div class="host-card-body">
        <div class="host-card-row"><span>IP</span><span class="host-card-val">${esc(h.hostname)}</span></div>
        <div class="host-card-row"><span>Port</span><span class="host-card-val">${esc(h.api_port)}</span></div>
        <div class="host-card-row"><span>MAC</span><span class="host-card-val mono-sm">${esc(h.mac||'—')}</span></div>
        <div class="host-card-row">
          <span>API Token</span>
          <span><span class="token-dot ${h.token_set?'tk-yes':'tk-no'}"></span> ${h.token_set?'Configured':'Not set'}</span>
        </div>
        <div class="host-card-row"><span>SSL Verify</span><span>${h.verify_ssl?'✓ Yes':'✗ Skip'}</span></div>
        ${h.notes ? `<div style="margin-top:4px;color:var(--dim);font-size:11px">${esc(h.notes)}</div>` : ''}
      </div>
      <div class="host-card-footer">
        <button class="btn btn-secondary btn-sm" data-action="edit-host" data-id="${h.id}">Edit</button>
        <button class="btn ${h.token_set?'btn-green':'btn-secondary'} btn-sm" data-action="sync-host" data-id="${h.id}" data-name="${esc(h.name)}" ${h.token_set?'':'title="Add API token first"'}>⟳ Sync</button>
        <button class="btn btn-danger btn-sm" data-action="del-host" data-id="${h.id}" data-name="${esc(h.name)}">Delete</button>
      </div>
    </div>`).join('');
}

function openHostModal(pre={}) {
  document.getElementById('hf-id').value         = pre.id       || '';
  document.getElementById('hf-name').value       = pre.name     || '';
  document.getElementById('hf-hostname').value   = pre.hostname || '';
  document.getElementById('hf-mac').value        = pre.mac      || '';
  document.getElementById('hf-api_port').value   = pre.api_port || 8006;
  document.getElementById('hf-api_token').value  = '';  // never pre-fill token
  document.getElementById('hf-verify_ssl').checked = !!pre.verify_ssl;
  document.getElementById('hf-notes').value      = pre.notes    || '';
  document.getElementById('hostModalTitle').textContent = pre.id ? 'Edit Host' : 'Add Host';
  document.getElementById('hostBackdrop').classList.add('open');
}
function closeHostModal() { document.getElementById('hostBackdrop').classList.remove('open'); }

async function editHost(id) {
  if (!id) return toast('Invalid host ID', false);
  const d = await post({action:'host_get', id});
  if (d.ok) {
    openHostModal(d.host);
  } else {
    toast(d.msg || 'Failed to load host', false);
  }
}

async function saveHost() {
  const fields = { action:'host_save' };
  ['id','name','hostname','mac','api_port','api_token','notes'].forEach(k => {
    fields[k] = document.getElementById('hf-'+k)?.value ?? '';
  });
  fields.verify_ssl = document.getElementById('hf-verify_ssl').checked ? '1' : '';
  const d = await post(fields);
  if (d.ok) { toast('Host saved ✓', true); closeHostModal(); loadHosts(); refreshVMHostDropdowns(); }
  else toast(d.msg||'Save failed', false);
}
async function deleteHost(id, name) {
  if (!confirm(`Delete host "${name}"? All VMs assigned to it will also be deleted.`)) return;
  const d = await post({action:'host_delete',id});
  if (d.ok) { toast('Host deleted', true); loadHosts(); }
  else toast(d.msg||'Delete failed', false);
}
async function syncHost(host_id, name) {
  toast(`Syncing ${name}…`, true);
  const d = await post({action:'host_sync', host_id});
  if (d.ok) {
    toast(`✓ ${name} — ${d.synced} VMs/containers synced from node "${d.node}"`, true);
    loadVMs();
  } else {
    toast(`✗ ${name}: ${d.msg}`, false);
  }
}

// Refresh host dropdowns in VM modal after host changes
async function refreshVMHostDropdowns() {
  const d = await post({action:'hosts_dropdown'});
  if (!d.ok) return;
  const opts = d.hosts.map(h=>`<option value="${h.id}">${esc(h.name)}</option>`).join('');
  document.getElementById('vf-host_id').innerHTML = opts;
  const flt = document.getElementById('vmFilterHost');
  const cur = flt.value;
  flt.innerHTML = '<option value="">All Hosts</option>' + d.hosts.map(h=>`<option value="${esc(h.name)}">${esc(h.name)}</option>`).join('');
  flt.value = cur;
}

// ── Delegated click handler (avoids inline onclick escaping bugs) ──
document.addEventListener('click', async e => {
  const btn = e.target.closest('[data-action]');
  if (!btn) return;

  const action = btn.dataset.action;
  const id = btn.dataset.id;
  const name = btn.dataset.name;

  console.log('Button clicked:', action, id); // ← Debug line

  if (action === 'info-vm') {
    showVMInfo(id);
  } else if (action === 'edit-vm') {
    editVM(id);
  } else if (action === 'del-vm') {
    deleteVM(id, name);
  } else if (action === 'edit-host') {
    editHost(id);
  } else if (action === 'del-host') {
    deleteHost(id, name);
  } else if (action === 'sync-host') {
    syncHost(id, name);
  }
});


// ════════════════════════════════════════════════════════════
//  VM Info Panel
// ════════════════════════════════════════════════════════════
function closeInfoModal() { document.getElementById('infoBackdrop').classList.remove('open'); }

document.getElementById('infoBackdrop').addEventListener('click', e => {
  if (e.target === e.currentTarget) closeInfoModal();
});

function fmtBytes(bytes) {
  if (!bytes && bytes !== 0) return '—';
  bytes = parseInt(bytes);
  const units = ['B','KB','MB','GB','TB'];
  let i = 0;
  while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
  return bytes.toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
}

function fmtUptime(seconds) {
  if (!seconds) return null;
  const d = Math.floor(seconds / 86400);
  const h = Math.floor((seconds % 86400) / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const parts = [];
  if (d) parts.push(d + 'd');
  if (h) parts.push(h + 'h');
  if (m || !parts.length) parts.push(m + 'm');
  return parts.join(' ');
}

function fmtCfgSize(s) {
  // Proxmox config sizes: 32G, 512M, 8T etc.
  if (!s) return '—';
  return s.replace(/([0-9]+)([KMGT])/i, (_, n, u) => n + ' ' + ({K:'KB',M:'MB',G:'GB',T:'TB'}[u.toUpperCase()] || u));
}

function infoRow(label, value, cls = '') {
  return `<div class="info-item"><div class="info-label">${esc(label)}</div><div class="info-value ${cls}">${value}</div></div>`;
}

function renderInfoPanel(d) {
  const isRunning = d.status === 'running';
  let html = '';

  // ── Status header ────────────────────────────────────────
  const statusDot = `<span class="dot s-${esc(d.status)}"></span>`;
  const uptimeStr = isRunning && d.uptime
    ? `<span class="uptime-badge">⬆ ${fmtUptime(d.uptime)}</span>` : '';
  html += `<div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap">
    <span class="type-badge t-${esc(d.type)}">${esc(d.type)}</span>
    <span>${statusDot}${esc(d.status)}${d.qmp_status && d.qmp_status !== d.status ? ` <span style="color:var(--dim);font-size:11px">(${esc(d.qmp_status)})</span>` : ''}</span>
    ${uptimeStr}
    <span style="color:var(--dim);font-size:12px">VMID <span style="color:var(--blue);font-family:var(--mono)">${esc(String(d.vmid))}</span>
      on <span style="color:var(--accent);font-family:var(--mono)">${esc(d.host)}</span></span>
    ${d.protection ? '<span style="color:var(--yellow);font-size:11px">🔒 Protected</span>' : ''}
    ${d.tags ? `<span style="color:var(--purple);font-size:11px">🏷 ${esc(d.tags)}</span>` : ''}
  </div>`;

  // description
  if (d.description) {
    html += `<div style="background:var(--surf2);border:1px solid var(--border);border-radius:var(--r);padding:10px 14px;margin-bottom:16px;font-size:12px;color:var(--dim);white-space:pre-wrap">${esc(d.description)}</div>`;
  }

  // ── Proxmox Host Info ────────────────────────────────────
  if (d.pve_version || d.pve_kernel) {
    html += `<div class="info-section"><div class="info-section-title">Proxmox Host</div><div class="info-grid">`;
    if (d.pve_version) html += infoRow('PVE Version', esc(d.pve_version));
    if (d.pve_kernel)  html += infoRow('Kernel', esc(d.pve_kernel));
    if (d.host_cpu)    html += infoRow('Host CPU', esc(d.host_cpu));
    if (d.host_cpus)   html += infoRow('Host CPU Threads', d.host_cpus);
    if (d.host_mem && d.host_mem.total) {
      const usedPct = Math.round(d.host_mem.used / d.host_mem.total * 100);
      html += `<div class="info-item"><div class="info-label">Host RAM</div>
        <div class="info-value">${fmtBytes(d.host_mem.used)} / ${fmtBytes(d.host_mem.total)}</div>
        <div class="disk-bar-wrap" style="margin-top:5px"><div class="disk-bar ${usedPct>85?'crit':usedPct>70?'warn':''}" style="width:${usedPct}%"></div></div>
        <div style="font-size:11px;color:var(--dim);margin-top:2px">${usedPct}% used</div></div>`;
    }
    if (d.host_rootfs && d.host_rootfs.total) {
      const usedPct = Math.round((d.host_rootfs.total - d.host_rootfs.free) / d.host_rootfs.total * 100);
      html += `<div class="info-item"><div class="info-label">Host Root FS</div>
        <div class="info-value">${fmtBytes(d.host_rootfs.total - d.host_rootfs.free)} / ${fmtBytes(d.host_rootfs.total)}</div>
        <div class="disk-bar-wrap" style="margin-top:5px"><div class="disk-bar ${usedPct>85?'crit':usedPct>70?'warn':''}" style="width:${usedPct}%"></div></div>
        <div style="font-size:11px;color:var(--dim);margin-top:2px">${usedPct}% used</div></div>`;
    }
    html += `</div></div>`;
  }

  // ── CPU & Memory ─────────────────────────────────────────
  html += `<div class="info-section"><div class="info-section-title">CPU &amp; Memory</div><div class="info-grid">`;
  if (d.type !== 'lxc') {
    html += infoRow('Sockets', d.cpu_sockets ?? '1');
    html += infoRow('Cores / Socket', d.cpu_cores ?? '—');
    html += infoRow('Total vCPUs', (d.cpu_sockets ?? 1) * (d.cpu_cores ?? 1));
    html += infoRow('CPU Type', esc(d.cpu_type || 'kvm64'));
    if (d.cpu_limit)  html += infoRow('CPU Limit', d.cpu_limit + ' cores max');
    if (d.cpu_units)  html += infoRow('CPU Weight', d.cpu_units);
    if (d.numa)       html += infoRow('NUMA', '<span class="good">Enabled</span>');
    if (d.hugepages)  html += infoRow('Hugepages', esc(String(d.hugepages)));
  } else {
    if (d.cpu_cores)  html += infoRow('vCPUs', d.cpu_cores);
    if (d.cpu_limit)  html += infoRow('CPU Limit', d.cpu_limit);
    if (d.cpu_units)  html += infoRow('CPU Weight', d.cpu_units);
  }

  const ramGb = d.ram_mb ? (d.ram_mb / 1024).toFixed(d.ram_mb >= 1024 ? 1 : 2) + ' GB' : '—';
  html += infoRow('Configured RAM', ramGb);

  if (d.type === 'lxc' && d.swap_mb !== null && d.swap_mb !== undefined) {
    html += infoRow('Swap', d.swap_mb + ' MB');
  }
  if (d.type !== 'lxc' && d.balloon !== null && d.balloon !== undefined) {
    html += infoRow('Balloon Min', d.balloon === 0 ? '<span class="off">Disabled</span>' : fmtBytes(d.balloon * 1024 * 1024));
  }

  if (isRunning && d.mem_used_bytes && d.mem_max_bytes) {
    const pct = Math.round(d.mem_used_bytes / d.mem_max_bytes * 100);
    html += `<div class="info-item"><div class="info-label">RAM Usage (live)</div>
      <div class="info-value">${fmtBytes(d.mem_used_bytes)} / ${fmtBytes(d.mem_max_bytes)}</div>
      <div class="disk-bar-wrap" style="margin-top:5px"><div class="disk-bar ${pct>90?'crit':pct>75?'warn':''}" style="width:${pct}%"></div></div>
      <div style="font-size:11px;color:var(--dim);margin-top:2px">${pct}% used</div></div>`;
  }
  if (isRunning && d.cpu_pct !== null && d.cpu_pct !== undefined) {
    html += `<div class="info-item"><div class="info-label">CPU Usage (live)</div>
      <div class="info-value">${d.cpu_pct}%</div>
      <div class="disk-bar-wrap" style="margin-top:5px"><div class="disk-bar ${d.cpu_pct>90?'crit':d.cpu_pct>70?'warn':''}" style="width:${Math.min(d.cpu_pct,100)}%"></div></div></div>`;
  }
  if (isRunning && (d.balloon_actual !== null && d.balloon_actual !== undefined) && d.balloon !== 0) {
    html += infoRow('Balloon Actual', fmtBytes(d.balloon_actual));
  }
  html += `</div></div>`;

  // ── I/O counters (since boot) ────────────────────────────
  if (isRunning && (d.disk_read || d.disk_write || d.net_in || d.net_out)) {
    html += `<div class="info-section"><div class="info-section-title">I/O Since Boot</div><div class="info-grid">`;
    if (d.disk_read  !== null && d.disk_read  !== undefined) html += infoRow('Disk Read',  fmtBytes(d.disk_read));
    if (d.disk_write !== null && d.disk_write !== undefined) html += infoRow('Disk Write', fmtBytes(d.disk_write));
    if (d.net_in     !== null && d.net_in     !== undefined) html += infoRow('Net In',     fmtBytes(d.net_in));
    if (d.net_out    !== null && d.net_out    !== undefined) html += infoRow('Net Out',    fmtBytes(d.net_out));
    html += `</div></div>`;
  }

  // ── System Config ────────────────────────────────────────
  html += `<div class="info-section"><div class="info-section-title">System Configuration</div><div class="info-grid">`;
  html += infoRow('Start at Boot', d.on_boot ? '<span class="good">Yes</span>' : '<span class="off">No</span>');
  if (d.startup)     html += infoRow('Startup Order', esc(d.startup));
  if (d.ostype)      html += infoRow('OS Type',       esc(d.ostype));
  if (d.type !== 'lxc') {
    html += infoRow('Machine',  esc(d.machine || 'i440fx'));
    html += infoRow('BIOS',     esc(d.bios    || 'seabios'));
    if (d.boot) html += infoRow('Boot Order', esc(d.boot));
  }
  if (d.type === 'lxc') {
    if (d.hostname)    html += infoRow('Hostname',      esc(d.hostname));
    html += infoRow('Unprivileged', d.unprivileged ? '<span class="good">Yes</span>' : '<span class="warn">No</span>');
    html += infoRow('Nesting',      d.nesting      ? '<span class="good">Enabled</span>' : '<span class="off">Disabled</span>');
  }
  html += `</div></div>`;

  // ── QEMU Agent ───────────────────────────────────────────
  if (d.type !== 'lxc') {
    html += `<div class="info-section"><div class="info-section-title">QEMU Guest Agent</div><div class="info-grid">`;
    if (d.agent_enabled) {
      html += infoRow('Configured',   '<span class="good">Enabled</span>');
      if (d.agent_type)     html += infoRow('Type', esc(d.agent_type));
      html += infoRow('Freeze FS on Backup', d.agent_freeze_fs ? '<span class="good">Yes</span>' : '<span class="off">No</span>');
      if (isRunning) {
        html += infoRow('Agent Running', d.agent_running
          ? '<span class="good">● Responding</span>'
          : '<span class="warn">● Not responding</span><span style="font-size:10px;color:var(--dim);display:block;margin-top:2px">Is qemu-guest-agent installed &amp; running inside the VM?</span>');
      }
    } else {
      html += infoRow('Configured', '<span class="warn">Not enabled</span>');
      html += `<div class="info-item full" style="grid-column:1/-1"><div class="info-label">How to enable</div>
        <div style="font-size:12px;color:var(--dim);line-height:1.6">
          1. Install inside VM: <code style="font-family:var(--mono);color:var(--blue)">apt install qemu-guest-agent</code><br>
          2. In PVE: VM → Options → QEMU Guest Agent → Enable<br>
          3. Start the service: <code style="font-family:var(--mono);color:var(--blue)">systemctl enable --now qemu-guest-agent</code>
        </div></div>`;
    }
    html += `</div></div>`;
  }

  // ── OS Info from agent ───────────────────────────────────
  if (d.agent_data?.osinfo) {
    const os = d.agent_data.osinfo;
    html += `<div class="info-section"><div class="info-section-title">Operating System (via Agent)</div><div class="info-grid">`;
    const pretty = os.pretty_name || os['pretty-name'] || os.name;
    if (pretty)                html += infoRow('OS',     esc(pretty));
    if (os.version)            html += infoRow('Version',esc(os.version));
    const kernel = os.kernel_release || os['kernel-release'];
    if (kernel)                html += infoRow('Kernel', esc(kernel));
    if (os.machine)            html += infoRow('Arch',   esc(os.machine));
    if (d.agent_data.hostname) html += infoRow('Hostname', esc(d.agent_data.hostname));
    if (d.agent_data.timezone) html += infoRow('Timezone', esc(d.agent_data.timezone));
    html += `</div></div>`;
  }

  // ── Agent network interfaces (richer IPs) ────────────────
  if (d.agent_data?.interfaces?.length) {
    html += `<div class="info-section"><div class="info-section-title">Network Interfaces (via Agent)</div>`;
    for (const iface of d.agent_data.interfaces) {
      html += `<div class="net-row">
        <span class="net-id">${esc(iface.name)}</span>
        ${iface.mac ? `<span class="net-kv">mac <span>${esc(iface.mac)}</span></span>` : ''}
        ${iface.ips.map(ip => `<span class="net-kv">ip <span>${esc(ip)}</span></span>`).join('')}
      </div>`;
    }
    html += `</div>`;
  } else if (d.networks?.length) {
    // Fall back to config-level net info
    html += `<div class="info-section"><div class="info-section-title">Network Interfaces (from Config)</div>`;
    for (const net of d.networks) {
      html += `<div class="net-row">
        <span class="net-id">${esc(net.id)}</span>
        ${net.model   ? `<span class="net-kv">model <span>${esc(net.model)}</span></span>`   : ''}
        ${net.bridge  ? `<span class="net-kv">bridge <span>${esc(net.bridge)}</span></span>` : ''}
        ${net.mac     ? `<span class="net-kv">mac <span>${esc(net.mac)}</span></span>`       : ''}
        ${net.vlan    ? `<span class="net-kv">vlan <span>${esc(net.vlan)}</span></span>`     : ''}
        ${net.ip      ? `<span class="net-kv">ip <span>${esc(net.ip)}</span></span>`         : ''}
        ${net.rate    ? `<span class="net-kv">rate <span>${esc(net.rate)}MB/s</span></span>` : ''}
        ${net.firewall? `<span class="net-kv good">fw ✓</span>` : ''}
      </div>`;
    }
    html += `</div>`;
  }

  // ── Disks (config) ───────────────────────────────────────
  if (d.disks?.length) {
    html += `<div class="info-section"><div class="info-section-title">Disks (from Config)</div>`;
    for (const disk of d.disks) {
      html += `<div class="disk-row">
        <div class="disk-row-header">
          <span class="disk-key">${esc(disk.key)}</span>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <span class="disk-size">${disk.size_cfg ? fmtCfgSize(disk.size_cfg) : '—'}</span>
            ${disk.storage ? `<span style="font-size:11px;color:var(--dim)">${esc(disk.storage)}</span>` : ''}
            ${disk.cache   ? `<span style="font-size:11px;color:var(--dim)">cache: ${esc(disk.cache)}</span>` : ''}
            ${disk.ssd_hint? `<span style="font-size:11px;color:var(--blue)">SSD/discard</span>` : ''}
            ${disk.ro      ? `<span style="font-size:11px;color:var(--yellow)">read-only</span>` : ''}
          </div>
        </div>
        <div class="disk-meta">${esc(disk.raw)}</div>
      </div>`;
    }

    // Filesystem usage from agent
    if (d.agent_data?.filesystems?.length) {
      html += `<div style="margin-top:10px"><div class="info-section-title" style="margin-top:0">Filesystem Usage (via Agent)</div>`;
      for (const fs of d.agent_data.filesystems) {
        if (!fs.total) continue;
        const pct  = Math.round(fs.used / fs.total * 100);
        const free = fs.total - fs.used;
        html += `<div class="disk-row">
          <div class="disk-row-header">
            <span class="disk-key">${esc(fs.mountpoint || fs.name)}</span>
            <span class="disk-size">${fmtBytes(fs.used)} / ${fmtBytes(fs.total)}
              <span style="color:var(--dim)">(${fmtBytes(free)} free)</span></span>
          </div>
          <div class="disk-bar-wrap"><div class="disk-bar ${pct>90?'crit':pct>75?'warn':''}" style="width:${pct}%"></div></div>
          <div class="disk-meta">${pct}% used · type: ${esc(fs.type)}${fs.disk ? ' · dev: ' + esc(fs.disk) : ''}</div>
        </div>`;
      }
      html += `</div>`;
    } else if (isRunning && d.type !== 'lxc') {
      if (!d.agent_enabled) {
        html += `<div class="disk-meta" style="margin-top:8px;color:var(--yellow)">⚠ QEMU Agent not enabled — filesystem usage unavailable.</div>`;
      } else if (!d.agent_running) {
        html += `<div class="disk-meta" style="margin-top:8px;color:var(--dim)">Agent is configured but not responding — filesystem usage unavailable.</div>`;
      }
    }
    html += `</div>`;
  }

  return html;
}

async function showVMInfo(id) {
  document.getElementById('infoTitle').textContent = 'Loading…';
  document.getElementById('infoBody').innerHTML = '<div class="info-loading">Fetching data from Proxmox API…</div>';
  document.getElementById('infoBackdrop').classList.add('open');

  const d = await post({ action: 'vm_info', id });
  if (!d.ok) {
    document.getElementById('infoBody').innerHTML = `<div class="info-loading" style="color:var(--red)">${esc(d.msg || 'Failed to load VM info')}</div>`;
    return;
  }

  document.getElementById('infoTitle').textContent = d.name;
  document.getElementById('infoBody').innerHTML = renderInfoPanel(d);
}

// ── Init ──────────────────────────────────────────────────
loadVMs();
</script>
</body>
</html>