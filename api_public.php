<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type");

require_once __DIR__ . '/db.php';

class ApiDatabase {
    private $connection;
    public function __construct($connection) { $this->connection = $connection; }
    public function fetchAll($sql, $types = '', $params = []) { return $this->prepare($sql, $types, $params)->get_result()->fetch_all(MYSQLI_ASSOC); }
    public function fetchOne($sql, $types = '', $params = []) { return $this->prepare($sql, $types, $params)->get_result()->fetch_assoc(); }
    public function execute($sql, $types = '', $params = []) { $this->prepare($sql, $types, $params)->close(); }
    public function insert($sql, $types = '', $params = []) { $this->execute($sql, $types, $params); return $this->connection->insert_id; }
    private function prepare($sql, $types, $params) {
        $stmt = $this->connection->prepare($sql);
        if ($types !== '') { $stmt->bind_param($types, ...$params); }
        $stmt->execute();
        return $stmt;
    }
}

$db = new ApiDatabase($conn);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;
$id = isset($_GET['id']) ? intval($_GET['id']) : null;

switch ($method) {
    case 'GET':
        // 1. Hämta språk
        if ($action === 'languages') {
            echo json_encode($db->fetchAll("SELECT id, sprak, namn FROM sprak ORDER BY id ASC"), JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 2. Hämta enskild sida
        if ($id) {
            $page = $db->fetchOne("SELECT id, status, created_at, updated_at FROM pages WHERE id = ?", "i", [$id]);
            if (!$page) { http_response_code(404); echo json_encode(["message" => "Sidan hittades inte"]); exit; }

            $translations = $db->fetchAll("SELECT pc.sprak_id, s.sprak AS sprak_kod, s.namn AS sprak_namn, pc.title, pc.content FROM page_content pc INNER JOIN sprak s ON pc.sprak_id = s.id WHERE pc.page_id = ?", "i", [$id]);
            $page['translations'] = $translations;
            
            $req_id = isset($_GET['sprak_id']) ? intval($_GET['sprak_id']) : null;
            $req_code = $_GET['sprak'] ?? null;
            $active = $translations[0] ?? null;
            foreach ($translations as $t) {
                if (($req_id && $t['sprak_id'] == $req_id) || (!$req_id && $req_code && $t['sprak_kod'] === $req_code)) {
                    $active = $t; break;
                }
            }
            $page['sprak_id'] = $active['sprak_id'] ?? null;
            $page['sprak_kod'] = $active['sprak_kod'] ?? '';
            $page['sprak_namn'] = $active['sprak_namn'] ?? '';
            $page['title'] = $active['title'] ?? '';
            $page['content'] = $active['content'] ?? '';
            $page['images'] = $db->fetchAll("SELECT * FROM images WHERE page_id = ? ORDER BY id ASC", "i", [$id]);

            echo json_encode($page, JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 3. Hämta lista med sidor
        $sprak_id = isset($_GET['sprak_id']) && $_GET['sprak_id'] !== '' ? intval($_GET['sprak_id']) : null;
        $langCond = $sprak_id ? "pc.sprak_id = ?" : "pc.sprak_id = (SELECT MIN(sprak_id) FROM page_content WHERE page_id = p.id)";
        $conditions = [$langCond]; $params = []; $types = "";

        if ($sprak_id) { $params[] = $sprak_id; $types .= "i"; }
        if (!empty($_GET['status'])) { $conditions[] = "p.status = ?"; $params[] = $_GET['status']; $types .= "s"; }
        if (!empty($_GET['month'])) { $conditions[] = "MONTH(p.created_at) = ?"; $params[] = intval($_GET['month']); $types .= "i"; }
        if (!empty($_GET['search'])) {
            $conditions[] = "(pc.title LIKE ? OR pc.content LIKE ?)";
            $s = '%' . $_GET['search'] . '%';
            $params[] = $s; $params[] = $s; $types .= "ss";
        }

        $sql = "SELECT p.id, p.status, p.created_at, p.updated_at, pc.sprak_id, pc.title, pc.content, s.sprak AS sprak_kod, s.namn AS sprak_namn FROM pages p INNER JOIN page_content pc ON p.id = pc.page_id INNER JOIN sprak s ON pc.sprak_id = s.id WHERE " . implode(" AND ", $conditions) . " ORDER BY p.created_at DESC";
        
        $pages = $db->fetchAll($sql, $types, $params);

        if (!empty($pages)) {
            $pageIds = array_column($pages, 'id');
            $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
            $idTypes = str_repeat('i', count($pageIds));

            $allTrans = $db->fetchAll("SELECT pc.page_id, pc.sprak_id, s.sprak, s.namn FROM page_content pc INNER JOIN sprak s ON pc.sprak_id = s.id WHERE pc.page_id IN ($placeholders)", $idTypes, $pageIds);
            $transMap = []; foreach ($allTrans as $t) { $transMap[$t['page_id']][] = $t; }

            $allImages = $db->fetchAll("SELECT * FROM images WHERE page_id IN ($placeholders) ORDER BY id ASC", $idTypes, $pageIds);
            $imgMap = []; foreach ($allImages as $img) { $imgMap[$img['page_id']][] = $img; }

            foreach ($pages as &$p) {
                $p['available_languages'] = $transMap[$p['id']] ?? [];
                $p['images'] = $imgMap[$p['id']] ?? [];
            }
        }
        echo json_encode($pages, JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(405); echo json_encode(["message" => "Metoden tillåts inte"]);
        break;
}