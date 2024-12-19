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
$offset = isset($_GET['offset']) && is_numeric($_GET['offset']) ? (int)$offset : 0;
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
        WHERE 1=1
    ";

    $params = [];

    if ($search) {
        $query .= " AND g.game_name LIKE :search";
        $params['search'] = "%$search%";
    }

    if ($favorite && isLoggedIn()) {
        $query .= " AND g.game_id IN (SELECT game_id FROM liked_games WHERE user_id = :user_id)";
        $params['user_id'] = $_SESSION['user_id'];
    }

    // Add ordering based on type
    switch ($type) {
        case 'popular':
            $query .= " ORDER BY g.rating DESC, g.count_reviews DESC";
            break;
        case 'new':
            $query .= " ORDER BY g.release_date DESC";
            break;
        case 'old':
            $query .= " ORDER BY g.release_date ASC";
            break;
        case 'random_popular':
            $query .= " ORDER BY RAND() LIMIT 1";
            break;
        default:
            $query .= " ORDER BY g.game_id DESC";
    }

    if ($type !== 'random_popular') {
        $query .= " LIMIT :offset, :count";
        $params['offset'] = $offset;
        $params['count'] = $count;
    }

    $stmt = $pdo->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
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