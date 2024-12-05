<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/config.php';
require_once '../helpers/response.php';
require_once '../helpers/auth.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(['error' => 'Invalid request method'], 405);
}

// Retrieve query parameters with defaults
$type = isset($_GET['type']) ? trim($_GET['type']) : 'all';
$count = isset($_GET['count']) && is_numeric($_GET['count']) ? (int)$_GET['count'] : 10;
$offset = isset($_GET['offset']) && is_numeric($_GET['offset']) ? (int)$offset = (int)$_GET['offset'] : 0;
$favorite = isset($_GET['favorite']) ? filter_var($_GET['favorite'], FILTER_VALIDATE_BOOLEAN) : false;
$search = isset($_GET['search']) ? trim($_GET['search']) : null;

$maxLimit = 100;
if ($count > $maxLimit) {
    $count = $maxLimit;
}

// Validate 'type' parameter
$allowedTypes = ['popular', 'new', 'old', 'all', 'random_popular'];
if (!in_array($type, $allowedTypes)) {
    sendResponse(['error' => 'Invalid type parameter'], 400);
}

try {
    // Base query without genres and tags
    $query = "
        SELECT 
            g.game_id, 
            g.game_name, 
            g.header_image, 
            g.banner_image, 
            g.rating, 
            g.count_reviews, 
            g.required_age, 
            g.developer, 
            g.steam_link
        FROM games g
    ";

    $params = [];
    $conditions = [];

    // Apply filters based on 'type'
    switch ($type) {
        case 'popular':
            $conditions[] = "g.count_reviews > 100000";
            $orderBy = "g.count_reviews DESC";
            break;
        case 'new':
            $conditions[] = "g.release_date IS NOT NULL";
            $orderBy = "g.release_date DESC";
            break;
        case 'old':
            $conditions[] = "g.release_date IS NOT NULL";
            $orderBy = "g.release_date ASC";
            break;
        case 'random_popular':
            $conditions[] = "g.count_reviews >= 100000";
            $orderBy = "RAND()";
            break;
        case 'all':
        default:
            $orderBy = "g.game_id ASC";
            break;
    }

    // Filter by search term if provided
    if ($search) {
        $conditions[] = "MATCH(g.game_name) AGAINST(:search IN BOOLEAN MODE)";
        $orderBy = "g.count_reviews DESC";
        $params[':search'] = $search . '*'; // Добавляем '*' для поиска по префиксу
    }

    // Filter by favorite games if requested
    if ($favorite) {
        if (!isLoggedIn()) {
            sendResponse(['error' => 'Unauthorized'], 401);
        }
        $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $_SESSION['user_id'];
        $conditions[] = "g.game_id IN (
                            SELECT game_id FROM favorite_games WHERE user_id = :user_id
                        )";
        $params[':user_id'] = $userId;
    }

    // Append conditions to the query
    if (!empty($conditions)) {
        $query .= " WHERE " . implode(" AND ", $conditions);
    }

    // Append ordering
    $query .= " ORDER BY " . $orderBy;

    // Append pagination
    $query .= " LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($query);

    // Bind parameters
    foreach ($params as $key => &$val) {
        $stmt->bindParam($key, $val);
    }

    $stmt->bindParam(':limit', $count, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();
    $games = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get user ID if logged in
    $userId = isLoggedIn() ? $_SESSION['user_id'] : null;

    // If user is logged in, get their liked games
    $likedGames = [];
    if ($userId) {
        $likeStmt = $pdo->prepare("SELECT game_id FROM favorite_games WHERE user_id = :user_id");
        $likeStmt->execute(['user_id' => $userId]);
        $likedGames = $likeStmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    // Process each game
    foreach ($games as &$game) {
        $game['header_image_url'] = $game['header_image'] ? $game['header_image'] : null;
        $game['banner_image_url'] = $game['banner_image'] ? $game['banner_image'] : null;
        // Add liked status
        $game['liked'] = $userId ? in_array($game['game_id'], $likedGames) : false;
        // Remove unnecessary fields
        unset($game['header_image'], $game['banner_image'], $game['count_reviews']);
    }

    sendResponse(['games' => $games], 200);

} catch (PDOException $e) {
    // Log the error for debugging (do not expose details to the user)
    error_log("Games Fetch Error: " . $e->getMessage());
    sendResponse(['error' => 'Internal Server Error'], 500);
}
?>