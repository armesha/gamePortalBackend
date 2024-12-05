<?php

require_once '../config/config.php';
require_once '../helpers/response.php';
require_once '../helpers/auth.php';

// Only allow DELETE requests
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    sendResponse(['error' => 'Invalid request method'], 405);
}

requireLogin();

// Get input data
$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['game_id']) || !is_numeric($input['game_id'])) {
    sendResponse(['error' => 'Invalid or missing game ID'], 400);
}

$gameId = (int)$input['game_id'];
$userId = $_SESSION['user_id'];

// Check if favorite exists
$stmt = $pdo->prepare("SELECT * FROM favorite_games WHERE user_id = :user_id AND game_id = :game_id");
$stmt->execute(['user_id' => $userId, 'game_id' => $gameId]);
if (!$stmt->fetch()) {
    sendResponse(['error' => 'Game not in favorites'], 404);
}

// Remove from favorites
$stmt = $pdo->prepare("DELETE FROM favorite_games WHERE user_id = :user_id AND game_id = :game_id");
try {
    $stmt->execute(['user_id' => $userId, 'game_id' => $gameId]);
    sendResponse(['message' => 'Game removed from favorites'], 200);
} catch (PDOException $e) {
    sendResponse(['error' => 'Failed to unlike game: ' . $e->getMessage()], 500);
}
?>
