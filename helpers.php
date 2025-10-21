<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

date_default_timezone_set(APP_TIMEZONE);

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

// Composer autoload if present
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

// Guard: only define functions once even if file is included multiple times.
if (!defined('HELPERS_BOOTSTRAPPED')) {
    define('HELPERS_BOOTSTRAPPED', true);

    function get_pdo(): PDO {
        static $pdo;
        if ($pdo === null) {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        }
        return $pdo;
    }

    function csrf_token(): string {
        if (empty($_SESSION[CSRF_TOKEN_NAME])) {
            $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        }
        return $_SESSION[CSRF_TOKEN_NAME];
    }

    function verify_csrf_token(?string $token): bool {
        return isset($_SESSION[CSRF_TOKEN_NAME]) && hash_equals($_SESSION[CSRF_TOKEN_NAME], (string)$token);
    }

    function redirect_with_message(string $location, string $message, string $type='success'): void {
        $_SESSION['flash'] = ['message'=>$message,'type'=>$type];
        header('Location: '.$location);
        exit;
    }

    function flash_message(): void {
        if (!empty($_SESSION['flash'])) {
            $flash = $_SESSION['flash'];
            unset($_SESSION['flash']);
            $type = htmlspecialchars($flash['type']);
            $message = htmlspecialchars($flash['message']);
            echo "<div class=\"flash flash-$type\">$message</div>";
        }
    }

    function current_user(): ?array { return $_SESSION['user'] ?? null; }
    function require_login(): void { if (!current_user()) { header('Location: login.php'); exit; } }
    function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; }
    function sanitize(string $v): string { return htmlspecialchars($v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

    function get_priorities(): array { return ['', 'low','low/mid','mid','mid/high','high']; }
    function get_statuses(): array { return ['open','in_progress','done']; }

    function priority_label(string $p): string {
        $map = [''=>'No priority','low'=>'Low','low/mid'=>'Low/Mid','mid'=>'Mid','mid/high'=>'Mid/High','high'=>'High'];
        return $map[$p] ?? $p;
    }
    function priority_class(string $p): string {
        return match ($p) { 'low'=>'priority-low','low/mid'=>'priority-lowmid','mid'=>'priority-mid','mid/high'=>'priority-midhigh','high'=>'priority-high', default=>'priority-none' };
    }
    function status_label(string $s): string {
        return match ($s) { 'open'=>'Open','in_progress'=>'In Progress','done'=>'Done', default=>ucfirst($s) };
    }
    function status_class(string $s): string {
        return match ($s) { 'open'=>'status-open','in_progress'=>'status-inprogress','done'=>'status-done', default=>'status-open' };
    }

    function fetch_buildings(): array {
        $stmt = get_pdo()->query('SELECT id,name FROM buildings ORDER BY name');
        return $stmt->fetchAll();
    }

    function fetch_rooms_by_building(?int $buildingId): array {
        if (!$buildingId) return [];
        $stmt = get_pdo()->prepare('SELECT id,room_number,label FROM rooms WHERE building_id=? ORDER BY room_number');
        $stmt->execute([$buildingId]);
        return $stmt->fetchAll();
    }

    function fetch_task_photos(int $taskId): array {
        $stmt = get_pdo()->prepare('SELECT * FROM task_photos WHERE task_id=? ORDER BY position');
        $stmt->execute([$taskId]);
        $indexed = [];
        foreach ($stmt->fetchAll() as $p) { $indexed[(int)$p['position']] = $p; }
        return $indexed;
    }

    function s3_client(): Aws\S3\S3Client {
        static $client;
        if ($client === null) {
            $client = new Aws\S3\S3Client([
                'version' => 'latest',
                'region'  => S3_REGION,
                'credentials' => ['key'=>S3_KEY,'secret'=>S3_SECRET],
                'endpoint' => S3_ENDPOINT,
                'use_path_style_endpoint' => S3_USE_PATH_STYLE,
            ]);
        }
        return $client;
    }
    function s3_signed_url_for_key(string $key, int $ttlSeconds = 900): string {
    $client = s3_client();
    $cmd = $client->getCommand('GetObject', ['Bucket' => S3_BUCKET, 'Key' => $key]);
    $req = $client->createPresignedRequest($cmd, '+' . $ttlSeconds . ' seconds');
    return (string) $req->getUri();
}

/** Produce a browser-usable URL for a photo row */
function photo_public_url(array|string $photoOrKey, ?int $maxWidth = null): string {
    $key = is_array($photoOrKey) ? ($photoOrKey['s3_key'] ?? '') : $photoOrKey;
    if ($key === '') return '#';
    // (maxWidth reserved for future; not used by file.php now)
    return '/file.php?key=' . rawurlencode($key);
}

    function s3_object_url(string $key): string {
        if (S3_URL_BASE !== '') return rtrim(S3_URL_BASE,'/').'/'.ltrim($key,'/');
        $endpoint = rtrim(S3_ENDPOINT,'/');
        if (S3_USE_PATH_STYLE) {
            return $endpoint.'/'.rawurlencode(S3_BUCKET).'/'.implode('/', array_map('rawurlencode', explode('/', $key)));
        }
        $parts = parse_url($endpoint);
        $host = $parts['scheme'].'://'.S3_BUCKET.'.'.$parts['host'];
        $path = $parts['path'] ?? '';
        return rtrim($host.$path,'/').'/'.ltrim($key,'/');
    }

    function build_task_filter_query(array $filters, array &$params): string {
        $conditions = [];
        if (!empty($filters['search'])) { $conditions[]='(t.title LIKE :search OR t.description LIKE :search)'; $params[':search']='%'.$filters['search'].'%'; }
        if (!empty($filters['building_id'])) { $conditions[]='t.building_id=:building_id'; $params[':building_id']=(int)$filters['building_id']; }
        if (!empty($filters['room_id'])) { $conditions[]='t.room_id=:room_id'; $params[':room_id']=(int)$filters['room_id']; }
        if (!empty($filters['priority']) && is_array($filters['priority'])) {
            $ph=[]; foreach ($filters['priority'] as $i=>$p){ $k=":priority$i"; $ph[]=$k; $params[$k]=$p; }
            if ($ph) $conditions[]='t.priority IN ('.implode(',',$ph).')';
        }
        if (!empty($filters['status'])) { $conditions[]='t.status=:status'; $params[':status']=$filters['status']; }
        if (!empty($filters['assigned_to'])) { $conditions[]='t.assigned_to LIKE :assigned_to'; $params[':assigned_to']='%'.$filters['assigned_to'].'%'; }
        if (!empty($filters['created_from'])) { $conditions[]='DATE(t.created_at)>=:created_from'; $params[':created_from']=$filters['created_from']; }
        if (!empty($filters['created_to'])) { $conditions[]='DATE(t.created_at)<=:created_to'; $params[':created_to']=$filters['created_to']; }
        if (!empty($filters['due_from'])) { $conditions[]='t.due_date>=:due_from'; $params[':due_from']=$filters['due_from']; }
        if (!empty($filters['due_to'])) { $conditions[]='t.due_date<=:due_to'; $params[':due_to']=$filters['due_to']; }
        if (isset($filters['has_photos']) && $filters['has_photos']!=='') {
            $conditions[] = ($filters['has_photos']==='1')
                ? 'EXISTS (SELECT 1 FROM task_photos tp WHERE tp.task_id=t.id)'
                : 'NOT EXISTS (SELECT 1 FROM task_photos tp WHERE tp.task_id=t.id)';
        }
        return $conditions ? 'WHERE '.implode(' AND ',$conditions) : '';
    }

    function fetch_tasks(array $filters, string $sort, string $direction, int $limit, int $offset, int &$total): array {
        $params = [];
        $where = build_task_filter_query($filters, $params);
        $sortColumns = ['created_at'=>'t.created_at','priority'=>'t.priority','due_date'=>'t.due_date','room'=>'r.room_number'];
        $sortColumn = $sortColumns[$sort] ?? 't.created_at';
        $direction = strtoupper($direction)==='ASC' ? 'ASC' : 'DESC';

        $pdo = get_pdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks t $where");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $sql = "SELECT t.*, b.name AS building_name, r.room_number, r.label AS room_label,
                (SELECT COUNT(*) FROM task_photos tp WHERE tp.task_id=t.id) AS photo_count
                FROM tasks t
                JOIN buildings b ON b.id=t.building_id
                JOIN rooms r ON r.id=t.room_id
                $where
                ORDER BY $sortColumn $direction
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k,$v);
        $stmt->bindValue(':limit',$limit,PDO::PARAM_INT);
        $stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    function fetch_task(int $taskId): ?array {
        $stmt = get_pdo()->prepare("SELECT t.*, b.name AS building_name, r.room_number, r.label AS room_label
                                    FROM tasks t
                                    JOIN buildings b ON b.id=t.building_id
                                    JOIN rooms r ON r.id=t.room_id
                                    WHERE t.id=?");
        $stmt->execute([$taskId]);
        $t = $stmt->fetch();
        return $t ?: null;
    }

    function insert_task(array $data): int {
        // convert '' -> NULL for due_date to avoid "Incorrect date value: ''"
        $due = isset($data['due_date']) && $data['due_date'] !== '' ? $data['due_date'] : null;

        $sql = "INSERT INTO tasks (building_id, room_id, title, description, priority, assigned_to, status, due_date, created_by)
                VALUES (:building_id,:room_id,:title,:description,:priority,:assigned_to,:status,:due_date,:created_by)";
        $stmt = get_pdo()->prepare($sql);
        $stmt->execute([
            ':building_id'=>$data['building_id'],
            ':room_id'=>$data['room_id'],
            ':title'=>$data['title'],
            ':description'=>$data['description'] ?? null,
            ':priority'=>$data['priority'] ?? '',
            ':assigned_to'=>$data['assigned_to'] ?? null,
            ':status'=>$data['status'] ?? 'open',
            ':due_date'=>$due,
            ':created_by'=>$data['created_by'] ?? null,
        ]);
        return (int)get_pdo()->lastInsertId();
    }

    function update_task(int $taskId, array $data): void {
        $due = isset($data['due_date']) && $data['due_date'] !== '' ? $data['due_date'] : null;

        $sql = "UPDATE tasks SET building_id=:building_id, room_id=:room_id, title=:title,
                description=:description, priority=:priority, assigned_to=:assigned_to,
                status=:status, due_date=:due_date WHERE id=:id";
        $stmt = get_pdo()->prepare($sql);
        $stmt->execute([
            ':building_id'=>$data['building_id'],
            ':room_id'=>$data['room_id'],
            ':title'=>$data['title'],
            ':description'=>$data['description'] ?? null,
            ':priority'=>$data['priority'] ?? '',
            ':assigned_to'=>$data['assigned_to'] ?? null,
            ':status'=>$data['status'] ?? 'open',
            ':due_date'=>$due,
            ':id'=>$taskId,
        ]);
    }

    function delete_task(int $taskId): void {
        // Delete S3 photos first (best-effort)
        foreach (fetch_task_photos($taskId) as $p) {
            try { s3_client()->deleteObject(['Bucket'=>S3_BUCKET,'Key'=>$p['s3_key']]); } catch (Throwable $e) {}
        }
        $stmt = get_pdo()->prepare('DELETE FROM tasks WHERE id=?');
        $stmt->execute([$taskId]);
    }

    function upsert_photo(int $taskId, int $position, string $key, string $url): void {
        $sql = "INSERT INTO task_photos (task_id,position,s3_key,url)
                VALUES (:task_id,:position,:s3_key,:url)
                ON DUPLICATE KEY UPDATE s3_key=VALUES(s3_key), url=VALUES(url), created_at=NOW()";
        $stmt = get_pdo()->prepare($sql);
        $stmt->execute([':task_id'=>$taskId,':position'=>$position,':s3_key'=>$key,':url'=>$url]);
    }

    function remove_photo(int $photoId): void {
        $pdo = get_pdo();
        $stmt = $pdo->prepare('SELECT * FROM task_photos WHERE id=?');
        $stmt->execute([$photoId]);
        $p = $stmt->fetch();
        if ($p) {
            try { s3_client()->deleteObject(['Bucket'=>S3_BUCKET,'Key'=>$p['s3_key']]); } catch (Throwable $e) {}
            $pdo->prepare('DELETE FROM task_photos WHERE id=?')->execute([$photoId]);
        }
    }

    function get_filter_values(): array {
        return [
            'search' => trim($_GET['search'] ?? ''),
            'building_id' => ($_GET['building_id'] ?? '') !== '' ? (int)$_GET['building_id'] : null,
            'room_id' => ($_GET['room_id'] ?? '') !== '' ? (int)$_GET['room_id'] : null,
            'priority' => isset($_GET['priority']) ? array_filter((array)$_GET['priority'], fn($p)=>$p!=='') : [],
            'status' => $_GET['status'] ?? '',
            'assigned_to' => trim($_GET['assigned_to'] ?? ''),
            'created_from' => $_GET['created_from'] ?? '',
            'created_to' => $_GET['created_to'] ?? '',
            'due_from' => $_GET['due_from'] ?? '',
            'due_to' => $_GET['due_to'] ?? '',
            'has_photos' => $_GET['has_photos'] ?? '',
        ];
    }

    function filter_summary(array $f): string {
        $parts = [];
        if ($f['search']) $parts[] = 'Search: "'.sanitize($f['search']).'"';
        if ($f['building_id']) { $b = fetch_building_name($f['building_id']); if ($b) $parts[]='Building: '.sanitize($b); }
        if ($f['room_id']) { $r = fetch_room_label($f['room_id']); if ($r) $parts[]='Room: '.sanitize($r); }
        if ($f['priority']) $parts[] = 'Priority: '.implode(', ', array_map('priority_label',$f['priority']));
        if ($f['status']) $parts[] = 'Status: '.status_label($f['status']);
        if ($f['assigned_to']) $parts[] = 'Assigned To: '.sanitize($f['assigned_to']);
        if ($f['created_from'] || $f['created_to']) $parts[]='Created: '.($f['created_from']?:'any').' to '.($f['created_to']?:'any');
        if ($f['due_from'] || $f['due_to']) $parts[]='Due: '.($f['due_from']?:'any').' to '.($f['due_to']?:'any');
        if ($f['has_photos']!=='') $parts[] = $f['has_photos']==='1' ? 'Has Photos' : 'No Photos';
        return $parts ? implode(' • ', $parts) : 'No filters applied';
    }

    function fetch_building_name(int $buildingId): ?string {
        $stmt = get_pdo()->prepare('SELECT name FROM buildings WHERE id=?');
        $stmt->execute([$buildingId]);
        $v = $stmt->fetchColumn();
        return $v !== false ? $v : null;
    }

    function fetch_room_label(int $roomId): ?string {
        $stmt = get_pdo()->prepare('SELECT CONCAT(room_number, IF(label IS NULL OR label="", "", CONCAT(" - ", label))) FROM rooms WHERE id=?');
        $stmt->execute([$roomId]);
        $v = $stmt->fetchColumn();
        return $v !== false ? $v : null;
    }

    function validate_task_payload(array $data, array &$errors): bool {
        $errors=[];
        if (empty($data['building_id'])) $errors['building_id']='Building is required';
        if (empty($data['room_id'])) $errors['room_id']='Room is required';
        if (empty(trim($data['title'] ?? ''))) $errors['title']='Title is required';
        if (!in_array($data['priority'] ?? '', get_priorities(), true)) $errors['priority']='Invalid priority';
        if (!in_array($data['status'] ?? 'open', get_statuses(), true)) $errors['status']='Invalid status';
        if (!empty($data['due_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$data['due_date'])) $errors['due_date']='Due date must be YYYY-MM-DD';
        return empty($errors);
    }

    function ensure_building_room_valid(int $buildingId, int $roomId): bool {
        $stmt = get_pdo()->prepare('SELECT COUNT(*) FROM rooms WHERE id=? AND building_id=?');
        $stmt->execute([$roomId,$buildingId]);
        return (bool)$stmt->fetchColumn();
    }

    function get_age_bucket_sql(): string {
        return "CASE
            WHEN DATEDIFF(CURDATE(), DATE(t.created_at)) <= 7 THEN '0-7'
            WHEN DATEDIFF(CURDATE(), DATE(t.created_at)) BETWEEN 8 AND 14 THEN '8-14'
            WHEN DATEDIFF(CURDATE(), DATE(t.created_at)) BETWEEN 15 AND 30 THEN '15-30'
            ELSE '>30' END";
    }

    function analytics_counts(): array {
        $pdo = get_pdo();
        $open = $pdo->query("SELECT COUNT(*) FROM tasks WHERE status='open'")->fetchColumn();
        $done30 = $pdo->query("SELECT COUNT(*) FROM tasks WHERE status='done' AND updated_at >= (CURDATE() - INTERVAL 30 DAY)")->fetchColumn();
        $dueWeek = $pdo->query("SELECT COUNT(*) FROM tasks WHERE status <> 'done' AND due_date BETWEEN CURDATE() AND (CURDATE() + INTERVAL 7 DAY)")->fetchColumn();
        $overdue = $pdo->query("SELECT COUNT(*) FROM tasks WHERE status <> 'done' AND due_date IS NOT NULL AND due_date < CURDATE()")->fetchColumn();
        return ['open'=>(int)$open,'done30'=>(int)$done30,'dueWeek'=>(int)$dueWeek,'overdue'=>(int)$overdue];
    }

    function analytics_group(string $sql): array {
        return get_pdo()->query($sql)->fetchAll();
    }

    function export_tasks(array $filters): array {
        $params=[]; $where=build_task_filter_query($filters,$params);
        $sql="SELECT t.*, b.name AS building_name, r.room_number, r.label AS room_label
              FROM tasks t
              JOIN buildings b ON b.id=t.building_id
              JOIN rooms r ON r.id=t.room_id
              $where
              ORDER BY b.name, r.room_number, t.priority DESC, t.created_at DESC";
        $stmt=get_pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    function fetch_tasks_by_ids(array $ids): array {
        if (empty($ids)) return [];
        $ph = implode(',', array_fill(0,count($ids),'?'));
        $sql="SELECT t.*, b.name AS building_name, r.room_number, r.label AS room_label
              FROM tasks t
              JOIN buildings b ON b.id=t.building_id
              JOIN rooms r ON r.id=t.room_id
              WHERE t.id IN ($ph)
              ORDER BY t.created_at DESC";
        $stmt=get_pdo()->prepare($sql);
        $stmt->execute($ids);
        return $stmt->fetchAll();
    }

    function room_tasks(int $roomId): array {
        $stmt = get_pdo()->prepare("SELECT t.*, b.name AS building_name, r.room_number, r.label AS room_label
                                    FROM tasks t
                                    JOIN buildings b ON b.id=t.building_id
                                    JOIN rooms r ON r.id=t.room_id
                                    WHERE t.room_id=:room
                                    ORDER BY t.status, t.priority DESC, t.created_at DESC");
        $stmt->execute([':room'=>$roomId]);
        return $stmt->fetchAll();
    }

    function group_tasks_by_status(array $tasks): array {
        $g=['open'=>[],'in_progress'=>[],'done'=>[]];
        foreach ($tasks as $t) { $g[$t['status']][]=$t; }
        return $g;
    }

    function task_photo_thumbnails(int $taskId): array {
    $photos = fetch_task_photos($taskId);  // indexed by position already
    $out = [];
    for ($i = 1; $i <= 3; $i++) {
        $out[$i] = !empty($photos[$i]) ? photo_public_url($photos[$i], 900) : null;
    }
    return $out;
}


    function fetch_photos_for_tasks(array $ids): array {
        if (empty($ids)) return [];
        $ph = implode(',', array_fill(0,count($ids),'?'));
        $stmt = get_pdo()->prepare("SELECT * FROM task_photos WHERE task_id IN ($ph) ORDER BY task_id, position");
        $stmt->execute($ids);
        $out=[];
        while ($r=$stmt->fetch()) { $out[$r['task_id']][]=$r; }
        return $out;
    }

    function json_response(array $data, int $status=200): void {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
