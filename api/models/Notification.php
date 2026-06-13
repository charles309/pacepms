<?php

declare(strict_types=1);

/**
 * In-app notifications (client DB).
 */
final class Notification
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function create(
        PDO $clientPdo,
        int $userId,
        string $userRole,
        string $type,
        string $title,
        string $body
    ): int {
        $stmt = $clientPdo->prepare(
            'INSERT INTO notifications (user_id, user_role, type, title, body, is_read)
             VALUES (:uid, :role, :type, :title, :body, 0)'
        );
        $stmt->execute([
            ':uid'   => $userId,
            ':role'  => $userRole,
            ':type'  => $type,
            ':title' => $title,
            ':body'  => $body,
        ]);
        return (int) $clientPdo->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listUnread(PDO $clientPdo, int $userId, string $userRole): array
    {
        $stmt = $clientPdo->prepare(
            'SELECT id, type, title, body, is_read, created_at
             FROM notifications
             WHERE user_id = :uid AND user_role = :role AND is_read = 0
             ORDER BY id DESC'
        );
        $stmt->execute([':uid' => $userId, ':role' => $userRole]);
        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(PDO $clientPdo, int $userId, string $userRole): array
    {
        $stmt = $clientPdo->prepare(
            'SELECT id, type, title, body, is_read, created_at
             FROM notifications
             WHERE user_id = :uid AND user_role = :role
             ORDER BY id DESC'
        );
        $stmt->execute([':uid' => $userId, ':role' => $userRole]);
        return $stmt->fetchAll();
    }

    /**
     * Mark a notification read, scoped to its owner. Returns true if a row changed.
     */
    public function markRead(PDO $clientPdo, int $id, int $userId, string $userRole): bool
    {
        $stmt = $clientPdo->prepare(
            'UPDATE notifications SET is_read = 1
             WHERE id = :id AND user_id = :uid AND user_role = :role'
        );
        $stmt->execute([':id' => $id, ':uid' => $userId, ':role' => $userRole]);
        return $stmt->rowCount() > 0;
    }
}
