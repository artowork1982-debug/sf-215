<?php
// app/actions/helpers.php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

function sf_get_pdo(): PDO {
    return Database::getInstance();
}

function sf_redirect(string $url): never {
    header("Location: $url");
    exit;
}

function sf_validate_id(): int {
    return max(0, (int)($_GET['id'] ?? 0));
}

/**
 * Update SafetyFlash state for all language versions
 * 
 * @param PDO $pdo Database connection
 * @param int $flashId Any language version ID
 * @param string $newState New state value
 * @return int Number of rows updated
 */
function sf_update_state_all_languages(PDO $pdo, int $flashId, string $newState): int {
    // Fetch translation group info
    $stmt = $pdo->prepare("SELECT id, translation_group_id FROM sf_flashes WHERE id = :id");
    $stmt->execute([':id' => $flashId]);
    $flash = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$flash) {
        return 0;
    }
    
    // Determine group ID (parent or self)
    $groupId = $flash['translation_group_id'] ?: $flash['id'];
    
    // Update all language versions
    // KORJATTU: Käytä kahta eri parametria koska PDO ei salli samaa parametria kahdesti
    $updateStmt = $pdo->prepare("
        UPDATE sf_flashes 
        SET state = :new_state, 
            updated_at = NOW()
        WHERE translation_group_id = :group_id1 OR id = :group_id2
    ");
    $updateStmt->execute([
        ':new_state' => $newState,
        ':group_id1' => $groupId,
        ':group_id2' => $groupId
    ]);
    
    return $updateStmt->rowCount();
}