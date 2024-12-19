<?php
// api/admin.php

require_once '../config/config.php';
require_once '../helpers/response.php';
require_once '../helpers/auth.php';

// Povolit hlášení chyb (pouze pro vývoj; vypnout v produkci)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Ujistit se, že je uživatel přihlášen
requireLogin();

// Zkontrolovat, zda je uživatel admin
try {
    $userId = (int)$_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT user_role FROM users WHERE user_id = :user_id");
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    $currentUser = $stmt->fetch();

    if (!$currentUser) {
        sendResponse(['error' => 'Uživatel nenalezen'], 404);
    }

    if ($currentUser['user_role'] !== 'admin') {
        sendResponse(['error' => 'Přístup odepřen'], 403);
    }
} catch (PDOException $e) {
    error_log("Admin Auth Error: " . $e->getMessage());
    sendResponse(['error' => 'Chyba databáze'], 500);
}

// Zpracování admin akcí: úprava profilů, blokování uživatelů, mazání zpráv/komentářů
$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['action'])) {
    sendResponse(['error' => 'Akce je povinná'], 400);
}

switch ($input['action']) {
    case 'block_user':
        if (empty($input['user_id']) || !is_numeric($input['user_id'])) {
            sendResponse(['error' => 'ID uživatele je povinné'], 400);
        }

        $targetUserId = (int)$input['user_id'];
        
        // Zabránit blokování jiných adminů nebo sebe sama
        $stmt = $pdo->prepare("SELECT user_role FROM users WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $targetUserId, PDO::PARAM_INT);
        $stmt->execute();
        $targetUser = $stmt->fetch();

        if (!$targetUser) {
            sendResponse(['error' => 'Uživatel nenalezen'], 404);
        }

        if ($targetUser['user_role'] === 'admin') {
            sendResponse(['error' => 'Nelze blokovat administrátora'], 403);
        }

        if ($targetUserId === $userId) {
            sendResponse(['error' => 'Nelze blokovat sám sebe'], 403);
        }

        // Aktualizovat stav blokování
        $stmt = $pdo->prepare("UPDATE users SET is_blocked = NOT is_blocked WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $targetUserId, PDO::PARAM_INT);
        $stmt->execute();

        sendResponse(['message' => 'Stav blokování byl úspěšně změněn'], 200);
        break;

    case 'edit_profile':
        if (empty($input['user_id']) || !is_numeric($input['user_id']) || empty($input['data'])) {
            sendResponse(['error' => 'ID uživatele a data jsou povinná'], 400);
        }

        $targetUserId = (int)$input['user_id'];
        $data = $input['data'];

        // Validace a sanitizace dat
        $allowedFields = ['first_name', 'last_name', 'email', 'user_nickname'];
        $updates = [];
        $params = [':user_id' => $targetUserId];

        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields)) {
                $updates[] = "$field = :$field";
                $params[":$field"] = htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
            }
        }

        if (empty($updates)) {
            sendResponse(['error' => 'Žádná platná pole k aktualizaci'], 400);
        }

        $query = "UPDATE users SET " . implode(", ", $updates) . " WHERE user_id = :user_id";
        $stmt = $pdo->prepare($query);
        
        foreach ($params as $key => &$value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        
        $stmt->execute();
        sendResponse(['message' => 'Profil byl úspěšně aktualizován'], 200);
        break;

    case 'delete_message':
        if (empty($input['message_id'])) {
            sendResponse(['error' => 'ID zprávy je povinné'], 400);
        }
        // Smazat zprávu z chatu
        try {
            $stmt = $pdo->prepare("DELETE FROM messages WHERE message_id = :message_id");
            $stmt->bindValue(':message_id', $input['message_id'], PDO::PARAM_INT);
            $stmt->execute();
            sendResponse(['message' => 'Zpráva byla smazána'], 200);
        } catch (PDOException $e) {
            sendResponse(['error' => 'Chyba databáze: ' . $e->getMessage()], 500);
        }
        break;

    case 'delete_comment':
        if (empty($input['comment_id'])) {
            sendResponse(['error' => 'ID komentáře je povinné'], 400);
        }
        // Smazat komentář
        try {
            $stmt = $pdo->prepare("DELETE FROM feedbacks WHERE feedback_id = :comment_id");
            $stmt->bindValue(':comment_id', $input['comment_id'], PDO::PARAM_INT);
            $stmt->execute();
            sendResponse(['message' => 'Komentář byl smazán'], 200);
        } catch (PDOException $e) {
            sendResponse(['error' => 'Chyba databáze: ' . $e->getMessage()], 500);
        }
        break;

    case 'assign_admin':
        if (empty($input['user_id'])) {
            sendResponse(['error' => 'ID uživatele je povinné'], 400);
        }
        // Přiřadit roli admina
        try {
            $stmt = $pdo->prepare("UPDATE users SET user_role = 'admin' WHERE user_id = :user_id");
            $stmt->bindValue(':user_id', $input['user_id'], PDO::PARAM_INT);
            $stmt->execute();
            sendResponse(['message' => 'Uživatel byl povýšen na admina'], 200);
        } catch (PDOException $e) {
            sendResponse(['error' => 'Chyba databáze: ' . $e->getMessage()], 500);
        }
        break;

    case 'toggle_admin':
        if (empty($input['user_id'])) {
            sendResponse(['error' => 'ID uživatele je povinné'], 400);
        }

        // Zabránit odebrání admin statusu sobě samému
        if ($input['user_id'] == $_SESSION['user_id']) {
            sendResponse(['error' => 'Nelze upravit vlastní admin status'], 403);
        }

        try {
            $newRole = isset($input['set_admin']) && $input['set_admin'] ? 'admin' : 'user';
            $stmt = $pdo->prepare("UPDATE users SET user_role = :role WHERE user_id = :user_id");
            $stmt->bindValue(':role', $newRole, PDO::PARAM_STR);
            $stmt->bindValue(':user_id', $input['user_id'], PDO::PARAM_INT);
            $stmt->execute();

            sendResponse(['message' => 'Admin status uživatele byl úspěšně aktualizován'], 200);
        } catch (PDOException $e) {
            sendResponse(['error' => 'Chyba databáze: ' . $e->getMessage()], 500);
        }
        break;

    default:
        sendResponse(['error' => 'Neplatná akce'], 400);
}
?>