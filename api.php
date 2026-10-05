<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;
$id = isset($_GET['id']) ? intval($_GET['id']) : null;

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// API DOKUMENTATION (GET /api.php?action=help)
if ($action === 'help' || ($method === 'GET' && isset($_GET['help']))) {
    echo json_encode([
        "message" => "Välkommen till Public CMS API",
        "endpoints" => [
            "GET /api.php?action=help" => "Visa denna API-dokumentation",
            "GET /api.php" => "Hämta alla sidor (Filtrera: ?status=published|draft & search=sökord)",
            "GET /api.php?id={id}" => "Hämta specifik sida med tillhörande bilder",
            "POST /api.php" => "Skapa ny sida (JSON: title, content, status)",
            "PUT /api.php?id={id}" => "Redigera sida (JSON: title, content, status)",
            "DELETE /api.php?id={id}" => "Ta bort sida och raderar bildfiler från hårddisken",
            "POST /api.php?action=upload_image" => "Ladda upp bild för sida (FormData: page_id, image_file)"
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($method) {

    // HÄMTA SIDOR & BILDER
    case 'GET':
        if ($id) {
            $stmt = $conn->prepare("SELECT * FROM pages WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $page = $stmt->get_result()->fetch_assoc();

            if (!$page) {
                http_response_code(404);
                echo json_encode(["message" => "Sidan hittades inte"]);
                exit;
            }

            $imgStmt = $conn->prepare("SELECT * FROM images WHERE page_id = ?");
            $imgStmt->bind_param("i", $id);
            $imgStmt->execute();
            $page['images'] = $imgStmt->get_result()->fetch_all(MYSQLI_ASSOC);

            echo json_encode($page, JSON_UNESCAPED_UNICODE);
        } else {
            $sql = "SELECT p.*, COUNT(i.id) AS image_count FROM pages p LEFT JOIN images i ON p.id = i.page_id WHERE 1=1";
            $params = [];
            $types = "";

            if (!empty($_GET['status'])) {
                $sql .= " AND p.status = ?";
                $params[] = $_GET['status'];
                $types .= "s";
            }

            if (!empty($_GET['search'])) {
                $sql .= " AND (p.title LIKE ? OR p.content LIKE ?)";
                $searchQuery = '%' . $_GET['search'] . '%';
                $params[] = $searchQuery;
                $params[] = $searchQuery;
                $types .= "ss";
            }

            $sql .= " GROUP BY p.id ORDER BY p.created_at DESC";

            if (!empty($params)) {
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
            } else {
                $result = $conn->query($sql);
            }

            $pages = $result->fetch_all(MYSQLI_ASSOC);

            // Hämta bilder för varje sida i listan
            foreach ($pages as &$p) {
                $imgStmt = $conn->prepare("SELECT * FROM images WHERE page_id = ?");
                $imgStmt->bind_param("i", $p['id']);
                $imgStmt->execute();
                $p['images'] = $imgStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            }

            echo json_encode($pages, JSON_UNESCAPED_UNICODE);
        }
        break;

    // SKAPA SIDA ELLER LADDA UPP BILD
    case 'POST':
        if ($action === 'upload_image') {
            $page_id = $_POST['page_id'] ?? null;

            if (!$page_id || !isset($_FILES['image_file'])) {
                http_response_code(400);
                echo json_encode(["message" => "Saknar page_id eller bildfil"]);
                exit;
            }

            $file = $_FILES['image_file'];
            $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $mime_type = mime_content_type($file['tmp_name']);
            $file_size = $file['size'];

            if (!in_array($mime_type, $allowed_mimes)) {
                http_response_code(400);
                echo json_encode(["message" => "Otillåtet filformat. Endast JPG, PNG, GIF och WEBP tillåts."]);
                exit;
            }

            if ($file_size > 5 * 1024 * 1024) {
                http_response_code(400);
                echo json_encode(["message" => "Filen överstiger maximal tillåten storlek på 5MB."]);
                exit;
            }

            $uploadDir = 'uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $fileName = uniqid() . '_' . preg_replace("/[^a-zA-Z0-9\._-]/", "", basename($file['name']));
            $targetPath = $uploadDir . $fileName;

            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $now = date('Y-m-d H:i:s');
                $stmt = $conn->prepare("INSERT INTO images (page_id, img_path, mime_type, file_size, created_at) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("issis", $page_id, $targetPath, $mime_type, $file_size, $now);
                $stmt->execute();

                http_response_code(201);
                echo json_encode(["message" => "Bild uppladdad!", "id" => $conn->insert_id, "img_path" => $targetPath]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Kunde inte spara filen på servern."]);
            }
        } else {
            $data = json_decode(file_get_contents("php://input"), true);

            if (empty($data['title']) || empty($data['content'])) {
                http_response_code(400);
                echo json_encode(["message" => "Titel och innehåll krävs"]);
                exit;
            }

            $title = $data['title'];
            $content = $data['content'];
            $status = in_array($data['status'] ?? '', ['draft', 'published']) ? $data['status'] : 'draft';
            $now = date('Y-m-d H:i:s');

            $stmt = $conn->prepare("INSERT INTO pages (title, content, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $title, $content, $status, $now, $now);
            
            if ($stmt->execute()) {
                http_response_code(201);
                echo json_encode(["message" => "Sida skapad!", "id" => $conn->insert_id]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Databasfel vid skapande av sida"]);
            }
        }
        break;

    // REDIGERA SIDA (PUT)
    case 'PUT':
        if (!$id) {
            http_response_code(400);
            echo json_encode(["message" => "ID krävs för uppdatering"]);
            exit;
        }

        $data = json_decode(file_get_contents("php://input"), true);
        $now = date('Y-m-d H:i:s');

        $fields = [];
        $params = [];
        $types = "";

        if (isset($data['title'])) { $fields[] = "title = ?"; $params[] = $data['title']; $types .= "s"; }
        if (isset($data['content'])) { $fields[] = "content = ?"; $params[] = $data['content']; $types .= "s"; }
        if (isset($data['status'])) { $fields[] = "status = ?"; $params[] = $data['status']; $types .= "s"; }

        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(["message" => "Inga fält att uppdatera skickades"]);
            exit;
        }

        $fields[] = "updated_at = ?";
        $params[] = $now;
        $types .= "s";

        $sql = "UPDATE pages SET " . implode(", ", $fields) . " WHERE id = ?";
        $params[] = $id;
        $types .= "i";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        echo json_encode(["message" => "Sidan har uppdaterats!"]);
        break;

    // TA BORT SIDA (DELETE)
    case 'DELETE':
        if (!$id) {
            http_response_code(400);
            echo json_encode(["message" => "ID krävs för radering"]);
            exit;
        }

        // Ta bort bildfiler från disk
        $imgStmt = $conn->prepare("SELECT img_path FROM images WHERE page_id = ?");
        $imgStmt->bind_param("i", $id);
        $imgStmt->execute();
        $images = $imgStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($images as $img) {
            if (file_exists($img['img_path'])) {
                unlink($img['img_path']);
            }
        }

        // Ta bort raden ur pages
        $stmt = $conn->prepare("DELETE FROM pages WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        echo json_encode(["message" => "Sidan och tillhörande bildfiler har raderats!"]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["message" => "Metoden tillåts inte"]);
        break;
}