<?php

require_once '../config/config.php';
require_once '../helpers/response.php';
require_once '../helpers/auth.php';

// Determine the request method
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Fetch feedbacks by game_id
        try {
            if (!isset($_GET['game_id']) || !is_numeric($_GET['game_id'])) {
                sendResponse(['error' => 'Invalid or missing game ID'], 400);
            }

            $gameId = (int)$_GET['game_id'];

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
                    u.avatar_filename,
                    g.game_id,
                    g.game_name
                FROM feedbacks f
                JOIN users u ON f.user_id = u.user_id
                JOIN games g ON f.game_id = g.game_id
                WHERE g.game_id = :game_id
                ORDER BY f.created_at DESC
            ");
            $stmt->execute(['game_id' => $gameId]);
            $feedbacks = $stmt->fetchAll();

            // Append avatar URLs
            foreach ($feedbacks as &$feedback) {
                $feedback['avatar_url'] = $feedback['avatar_filename'] ? 'uploads/avatars/' . $feedback['avatar_filename'] : null;
                unset($feedback['avatar_filename']);
            }

            sendResponse(['feedbacks' => $feedbacks], 200);
        } catch (PDOException $e) {
            error_log("Feedback Fetch Error: " . $e->getMessage());
            sendResponse(['error' => 'Internal Server Error'], 500);
        }
        break;

    case 'DELETE':
        // Delete a feedback and update game rating
        requireAdmin();

        try {
            if (!isset($_GET['feedback_id']) || !is_numeric($_GET['feedback_id'])) {
                sendResponse(['error' => 'Invalid or missing feedback ID'], 400);
            }

            $feedbackId = (int)$_GET['feedback_id'];

            $stmt = $pdo->prepare("DELETE FROM feedbacks WHERE feedback_id = :feedback_id");
            $stmt->bindValue(':feedback_id', $feedbackId, PDO::PARAM_INT);

            if ($stmt->execute()) {
                sendResponse(['message' => 'Feedback deleted successfully'], 200);
            } else {
                sendResponse(['error' => 'Failed to delete feedback'], 500);
            }
        } catch (PDOException $e) {
            error_log("Feedback Delete Error: " . $e->getMessage());
            sendResponse(['error' => 'Internal Server Error'], 500);
        }
        break;

    default:
        sendResponse(['error' => 'Invalid request method'], 405);
        break;
}
?>