<?php
// File: api/game.php

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

// Get game ID from query parameters
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    sendResponse(['error' => 'Invalid or missing game ID'], 400);
}

$gameId = (int)$_GET['id'];

try {
    // Fetch game details including genres and tags
    $query = "
        SELECT 
            g.game_id, 
            g.steam_id,
            g.game_name, 
            g.release_date,
            g.required_age,
            g.price,
            g.detailed_description,
            g.header_image, 
            g.banner_image, 
            g.steam_link,
            g.developer,
            g.positive,
            g.negative,
            g.rating,
            GROUP_CONCAT(DISTINCT ge.genre_name) AS genres,
            GROUP_CONCAT(DISTINCT t.tag_name) AS tags
        FROM games g
        LEFT JOIN game_genres gg ON g.game_id = gg.game_id
        LEFT JOIN genres ge ON gg.genre_id = ge.genre_id
        LEFT JOIN game_tags gt ON g.game_id = gt.game_id
        LEFT JOIN tags t ON gt.tag_id = t.tag_id
        WHERE g.game_id = :game_id
        GROUP BY g.game_id
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute(['game_id' => $gameId]);
    $game = $stmt->fetch();

    if (!$game) {
        sendResponse(['error' => 'Game not found'], 404);
    }

    // Append image URLs to game
    $game['header_image_url'] = $game['header_image'] ? $game['header_image'] : null;
    $game['banner_image_url'] = $game['banner_image'] ? $game['banner_image'] : null;

    // Format release_date
    $game['release_date'] = $game['release_date'] ? $game['release_date'] : null;

    // Process genres and tags into arrays
    $game['genres'] = $game['genres'] ? explode(',', $game['genres']) : [];
    $game['tags'] = $game['tags'] ? explode(',', $game['tags']) : [];

    // Remove unnecessary fields
    unset($game['header_image'], $game['banner_image'], $game['count_reviews']);

    // Determine if the user has liked the game
    if (isLoggedIn()) {
        $userId = $_SESSION['user_id'];
        $stmt = $pdo->prepare("SELECT 1 FROM favorite_games WHERE user_id = :user_id AND game_id = :game_id");
        $stmt->execute(['user_id' => $userId, 'game_id' => $gameId]);
        $liked = $stmt->fetch() ? true : false;
    } else {
        $liked = false;
    }

    // Append 'liked' to game data
    $game['liked'] = $liked;

    // Fetch feedbacks (comments) related to the game
    if (isLoggedIn()) {
        // All feedbacks for authorized users
        $stmt = $pdo->prepare("
            SELECT 
                f.feedback_id, 
                f.rating, 
                f.comment, 
                f.created_at, 
                u.user_id, 
                u.first_name, 
                u.last_name, 
                u.user_nickname, 
                u.avatar_filename
            FROM feedbacks f
            JOIN users u ON f.user_id = u.user_id
            WHERE f.game_id = :game_id
            ORDER BY f.created_at DESC
        ");
        $stmt->execute(['game_id' => $gameId]);
    } else {
        // Limited feedbacks for non-authorized users
        $stmt = $pdo->prepare("
            SELECT 
                f.feedback_id, 
                f.rating, 
                f.comment, 
                f.created_at, 
                u.user_id, 
                u.user_nickname, 
                u.avatar_filename
            FROM feedbacks f
            JOIN users u ON f.user_id = u.user_id
            WHERE f.game_id = :game_id
            ORDER BY f.created_at DESC
            LIMIT 2
        ");
        $stmt->execute(['game_id' => $gameId]);
    }

    $feedbacks = $stmt->fetchAll();

    // Append avatar URLs to feedbacks
    foreach ($feedbacks as &$feedback) {
        $feedback['avatar_url'] = $feedback['avatar_filename'] ? 'uploads/avatars/' . $feedback['avatar_filename'] : null;
        unset($feedback['avatar_filename']);
    }

    // Append feedbacks to the game
    $game['feedbacks'] = $feedbacks;

    sendResponse(['game' => $game], 200);

} catch (PDOException $e) {
    // Log the error for debugging (do not expose details to the user)
    error_log("Game Fetch Error [Game ID: {$gameId}]: " . $e->getMessage());
    sendResponse(['error' => 'An error occurred while fetching the game details'], 500);
}
?>