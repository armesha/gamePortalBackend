<?php

require_once '../config/config.php';
require_once '../helpers/response.php';
require_once '../helpers/auth.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Invalid request method'], 405);
}

requireLogin();

// Get input data
$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['game_id']) || !is_numeric($input['game_id'])) {
    sendResponse(['error' => 'Invalid or missing game ID'], 400);
}

$gameId = (int)$input['game_id'];
$userId = (int)$_SESSION['user_id'];

// Check if game exists
$stmt = $pdo->prepare("SELECT game_id FROM games WHERE game_id = :game_id");
$stmt->bindValue(':game_id', $gameId, PDO::PARAM_INT);
$stmt->execute();
if (!$stmt->fetch()) {
    sendResponse(['error' => 'Game not found'], 404);
}

// Check if already liked
$stmt = $pdo->prepare("SELECT 1 FROM favorite_games WHERE user_id = :user_id AND game_id = :game_id");
$stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
$stmt->bindValue(':game_id', $gameId, PDO::PARAM_INT);
$stmt->execute();
if ($stmt->fetch()) {
    sendResponse(['error' => 'Game already in favorites'], 409);
}

// Add to favorites
$stmt = $pdo->prepare("INSERT INTO favorite_games (user_id, game_id) VALUES (:user_id, :game_id)");
try {
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':game_id', $gameId, PDO::PARAM_INT);
    $stmt->execute();
    sendResponse(['message' => 'Game added to favorites'], 201);
} catch (PDOException $e) {
    error_log("Like Game Error: " . $e->getMessage());
    sendResponse(['error' => 'Failed to like game'], 500);
}
?>
