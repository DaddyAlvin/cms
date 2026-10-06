<?php
header("Content-Type: application/json; charset=UTF-8");

// LANSERING: Ändra '*' till din domän för subdomänen när du lanserar, t.ex. "https://cms.mindoman.se"
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

if ($action === 'help' || ($method === 'GET' && isset($_GET['help']))) {
    echo json_encode([
        "message" => "Välkommen till Public CMS API",
        "endpoints" => [
            "GET /api.php?action=languages" => "Hämta alla tillgängliga språk i databasen",
            "GET /api.php" => "Hämta sidor (Filtrera med: status, sprak_id, year, month, week, search)",
            "GET /api.php?id={id}&sprak_id={sprak_id}" => "Hämta specifik sida (optionellt specifik översättning)",
            "POST /api.php" => "Skapa ny sida (JSON: title, content, status, sprak_id)",
            "PUT /api.php?id={id}" => "Uppdatera eller lägg till språköversättning (JSON: title, content, status, sprak_id)",
            "DELETE /api.php?id={id}" => "Ta bort sida och alla dess översättningar och bilder",
            "POST /api.php?action=upload_image" => "Ladda upp bild för sida"
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($method) {

    case 'GET':
        // Hämta aktiva språk i systemet
        if ($action === 'languages') {
            $result = $conn->query("SELECT id, sprak, namn FROM sprak ORDER BY id ASC");
            $languages = $result->fetch_all(MYSQLI_ASSOC);
            echo json_encode($languages, JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Hämta en specifik sida
        if ($id) {
            $requested_sprak_id = isset($_GET['sprak_id']) ? intval($_GET['sprak_id']) : null;
            $requested_sprak_code = $_GET['sprak'] ?? null;

            $stmt = $conn->prepare("SELECT id, status, created_at, updated_at FROM pages WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $page = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$page) {
                http_response_code(404);
                echo json_encode(["message" => "Sidan hittades inte"]);
                exit;
            }

            // Hämta alla översättningar som finns för sidan
            $transStmt = $conn->prepare("SELECT pc.sprak_id, s.sprak AS sprak_kod, s.namn AS sprak_namn, pc.title, pc.content 
                                         FROM page_content pc 
                                         INNER JOIN sprak s ON pc.sprak_id = s.id 
                                         WHERE pc.page_id = ?");
            $transStmt->bind_param("i", $id);
            $transStmt->execute();
            $translations = $transStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $transStmt->close();

            $page['translations'] = $translations;

            // Välj vilket innehåll som ska visas
            $activeContent = null;
            if ($requested_sprak_id) {
                foreach ($translations as $t) {
                    if ($t['sprak_id'] == $requested_sprak_id) { $activeContent = $t; break; }
                }
            } elseif ($requested_sprak_code) {
                foreach ($translations as $t) {
                    if ($t['sprak_kod'] === $requested_sprak_code) { $activeContent = $t; break; }
                }
            }

            if (!$activeContent && !empty($translations)) {
                $activeContent = $translations[0];
            }

            $page['sprak_id'] = $activeContent['sprak_id'] ?? null;
            $page['sprak_kod'] = $activeContent['sprak_kod'] ?? '';
            $page['sprak_namn'] = $activeContent['sprak_namn'] ?? '';
            $page['title'] = $activeContent['title'] ?? '';
            $page['content'] = $activeContent['content'] ?? '';

            // Hämta bilder
            $imgStmt = $conn->prepare("SELECT * FROM images WHERE page_id = ? ORDER BY id ASC");
            $imgStmt->bind_param("i", $id);
            $imgStmt->execute();
            $page['images'] = $imgStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $imgStmt->close();

            echo json_encode($page, JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Hämta lista över sidor (varje sida visas 1 gång)
        $sprak_id = isset($_GET['sprak_id']) && $_GET['sprak_id'] !== '' ? intval($_GET['sprak_id']) : null;

        $sql = "SELECT p.id, p.status, p.created_at, p.updated_at, 
                       pc.sprak_id, pc.title, pc.content, s.sprak as sprak_kod, s.namn as sprak_namn
                FROM pages p
                INNER JOIN page_content pc ON p.id = pc.page_id";

        if ($sprak_id) {
            $sql .= " AND pc.sprak_id = " . $sprak_id;
        } else {
            // Om inget språk är valt som filter, välj det lägsta sprak_id så sidan inte dubbleras
            $sql .= " AND pc.sprak_id = (SELECT MIN(sprak_id) FROM page_content WHERE page_id = p.id)";
        }

        $sql .= " INNER JOIN sprak s ON pc.sprak_id = s.id WHERE 1=1";

        $params = [];
        $types = "";

        if (!empty($_GET['status'])) { $sql .= " AND p.status = ?"; $params[] = $_GET['status']; $types .= "s"; }
        if (!empty($_GET['year'])) { $sql .= " AND YEAR(p.created_at) = ?"; $params[] = intval($_GET['year']); $types .= "i"; }
        if (!empty($_GET['month'])) { $sql .= " AND MONTH(p.created_at) = ?"; $params[] = intval($_GET['month']); $types .= "i"; }
        if (!empty($_GET['week'])) { $sql .= " AND WEEK(p.created_at, 1) = ?"; $params[] = intval($_GET['week']); $types .= "i"; }
        if (!empty($_GET['search'])) {
            $sql .= " AND (pc.title LIKE ? OR pc.content LIKE ?)";
            $searchStr = '%' . $_GET['search'] . '%';
            $params[] = $searchStr; $params[] = $searchStr;
            $types .= "ss";
        }

        $sql .= " ORDER BY p.created_at DESC";

        $stmt = $conn->prepare($sql);
        if (!empty($params)) { $stmt->bind_param($types, ...$params); }
        $stmt->execute();
        $pages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (!empty($pages)) {
            $pageIds = array_column($pages, 'id');
            $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
            $idTypes = str_repeat('i', count($pageIds));

            // Hämta alla språköversättningar som finns tillgängliga per sida
            $transStmt = $conn->prepare("SELECT pc.page_id, pc.sprak_id, s.sprak, s.namn FROM page_content pc INNER JOIN sprak s ON pc.sprak_id = s.id WHERE pc.page_id IN ($placeholders)");
            $transStmt->bind_param($idTypes, ...$pageIds);
            $transStmt->execute();
            $allTrans = $transStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $transStmt->close();

            $transMap = [];
            foreach ($allTrans as $t) { $transMap[$t['page_id']][] = $t; }

            // Hämta bilderna
            $imgStmt = $conn->prepare("SELECT * FROM images WHERE page_id IN ($placeholders) ORDER BY id ASC");
            $imgStmt->bind_param($idTypes, ...$pageIds);
            $imgStmt->execute();
            $allImages = $imgStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $imgStmt->close();

            $imgMap = [];
            foreach ($allImages as $img) { $imgMap[$img['page_id']][] = $img; }

            foreach ($pages as &$p) {
                $p['available_languages'] = $transMap[$p['id']] ?? [];
                $p['images'] = $imgMap[$p['id']] ?? [];
            }
        }

        echo json_encode($pages, JSON_UNESCAPED_UNICODE);
        break;

    case 'POST':
        if ($action === 'upload_image') {
            $page_id = $_POST['page_id'] ?? null;
            if (!$page_id || !isset($_FILES['image_file'])) {
                http_response_code(400); echo json_encode(["message" => "Saknar page_id eller bildfil"]); exit;
            }

            $file = $_FILES['image_file'];
            $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $mime_type = mime_content_type($file['tmp_name']);
            $file_size = $file['size'];

            if (!in_array($mime_type, $allowed_mimes) || $file_size > 5 * 1024 * 1024) {
                http_response_code(400); echo json_encode(["message" => "Ogiltigt filformat eller för stor fil (max 5MB)."]); exit;
            }

            $uploadDir = 'uploads/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }

            $fileName = uniqid() . '_' . preg_replace("/[^a-zA-Z0-9\._-]/", "", basename($file['name']));
            $targetPath = $uploadDir . $fileName;

            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $now = date('Y-m-d H:i:s');
                $stmt = $conn->prepare("INSERT INTO images (page_id, img_path, mime_type, file_size, created_at) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("issis", $page_id, $targetPath, $mime_type, $file_size, $now);
                $stmt->execute();
                $newId = $conn->insert_id;
                $stmt->close();

                http_response_code(201);
                echo json_encode(["message" => "Bild uppladdad!", "id" => $newId, "img_path" => $targetPath]);
            } else {
                http_response_code(500); echo json_encode(["message" => "Kunde inte spara filen på servern."]);
            }
        } else {
            $data = json_decode(file_get_contents("php://input"), true);

            if (empty($data['title']) || empty($data['content']) || empty($data['sprak_id'])) {
                http_response_code(400); echo json_encode(["message" => "Titel, innehåll och språk krävs"]); exit;
            }

            $conn->begin_transaction();
            try {
                $now = date('Y-m-d H:i:s');
                $status = in_array($data['status'] ?? '', ['draft', 'published']) ? $data['status'] : 'draft';

                $stmt1 = $conn->prepare("INSERT INTO pages (status, created_at, updated_at) VALUES (?, ?, ?)");
                $stmt1->bind_param("sss", $status, $now, $now);
                $stmt1->execute();
                $page_id = $conn->insert_id;
                $stmt1->close();

                $stmt2 = $conn->prepare("INSERT INTO page_content (page_id, sprak_id, title, content) VALUES (?, ?, ?, ?)");
                $stmt2->bind_param("iiss", $page_id, $data['sprak_id'], $data['title'], $data['content']);
                $stmt2->execute();
                $stmt2->close();

                $conn->commit();
                http_response_code(201);
                echo json_encode(["message" => "Sida skapad!", "id" => $page_id]);
            } catch (Exception $e) {
                $conn->rollback();
                http_response_code(500); echo json_encode(["message" => "Kunde inte skapa sida"]);
            }
        }
        break;

    case 'PUT':
        if (!$id) { http_response_code(400); echo json_encode(["message" => "ID krävs för uppdatering"]); exit; }

        $data = json_decode(file_get_contents("php://input"), true);
        $conn->begin_transaction();

        try {
            if (isset($data['status'])) {
                $now = date('Y-m-d H:i:s');
                $stmt = $conn->prepare("UPDATE pages SET status = ?, updated_at = ? WHERE id = ?");
                $stmt->bind_param("ssi", $data['status'], $now, $id);
                $stmt->execute();
                $stmt->close();
            }

            if (isset($data['title']) && isset($data['content']) && !empty($data['sprak_id'])) {
                $stmt2 = $conn->prepare("INSERT INTO page_content (page_id, sprak_id, title, content) VALUES (?, ?, ?, ?)
                                         ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content)");
                $stmt2->bind_param("iiss", $id, $data['sprak_id'], $data['title'], $data['content']);
                $stmt2->execute();
                $stmt2->close();
            }

            $conn->commit();
            echo json_encode(["message" => "Sidan har uppdaterats!"]);
        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(500); echo json_encode(["message" => "Kunde inte uppdatera sidan"]);
        }
        break;

    case 'DELETE':
        if (!$id) { http_response_code(400); echo json_encode(["message" => "ID krävs för radering"]); exit; }

        $imgStmt = $conn->prepare("SELECT img_path FROM images WHERE page_id = ?");
        $imgStmt->bind_param("i", $id);
        $imgStmt->execute();
        $images = $imgStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $imgStmt->close();

        foreach ($images as $img) {
            if (file_exists($img['img_path'])) { unlink($img['img_path']); }
        }

        $stmt = $conn->prepare("DELETE FROM pages WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        echo json_encode(["message" => "Sidan och tillhörande material raderades!"]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["message" => "Metoden tillåts inte"]);
        break;
}