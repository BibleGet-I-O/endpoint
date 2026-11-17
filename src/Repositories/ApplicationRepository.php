<?php

declare(strict_types=1);

namespace BibleGet\Api\Repositories;

class ApplicationRepository
{
    private const UPDATABLE = ['name', 'description', 'website'];

    public function __construct(private readonly \PDO $db)
    {
    }

    /** Creates a pending application and returns its UUID. */
    public function create(string $ownerId, string $name, ?string $description, ?string $website): string
    {
        $stmt = $this->db->prepare(
            'INSERT INTO applications (zitadel_user_id, name, description, website)
             VALUES (:u, :n, :d, :w) RETURNING id'
        );
        $stmt->execute([':u' => $ownerId, ':n' => $name, ':d' => $description, ':w' => $website]);
        return Cast::string($stmt->fetchColumn());
    }

    /** @return array<string, mixed>|null */
    public function findForOwner(string $id, string $ownerId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM applications WHERE id = :id AND zitadel_user_id = :u AND NOT is_system');
        $stmt->execute([':id' => $id, ':u' => $ownerId]);
        return Cast::row($stmt->fetch());
    }

    /** @return list<array<string, mixed>> */
    public function listForOwner(string $ownerId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM applications WHERE zitadel_user_id = :u AND NOT is_system ORDER BY created_at DESC'
        );
        $stmt->execute([':u' => $ownerId]);
        return Cast::rows($stmt->fetchAll());
    }

    /**
     * Updates name, description and website; returns false when the application is not the owner's.
     *
     * @param array<string, mixed> $fields
     */
    public function update(string $id, string $ownerId, array $fields): bool
    {
        $sets   = ['updated_at = now()'];
        $params = [':id' => $id, ':u' => $ownerId];
        foreach (self::UPDATABLE as $col) {
            if (array_key_exists($col, $fields)) {
                $v               = $fields[$col];
                $sets[]          = "$col = :$col";
                $params[":$col"] = is_scalar($v) ? (string) $v : null;
            }
        }
        $stmt = $this->db->prepare(
            'UPDATE applications SET ' . implode(', ', $sets) . ' WHERE id = :id AND zitadel_user_id = :u AND NOT is_system'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function delete(string $id, string $ownerId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM applications WHERE id = :id AND zitadel_user_id = :u AND NOT is_system');
        $stmt->execute([':id' => $id, ':u' => $ownerId]);
        return $stmt->rowCount() > 0;
    }

    /** Moves a rejected application back to pending. */
    public function resubmit(string $id, string $ownerId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE applications SET status = 'pending', review_notes = NULL, reviewed_by = NULL,
                    reviewed_at = NULL, updated_at = now()
             WHERE id = :id AND zitadel_user_id = :u AND status = 'rejected' AND NOT is_system"
        );
        $stmt->execute([':id' => $id, ':u' => $ownerId]);
        return $stmt->rowCount() > 0;
    }

    public function approve(string $id, string $reviewer, ?string $notes): bool
    {
        return $this->review($id, 'approved', $reviewer, $notes);
    }

    public function reject(string $id, string $reviewer, ?string $notes): bool
    {
        return $this->review($id, 'rejected', $reviewer, $notes);
    }

    /** Revokes the application and deactivates all of its keys. */
    public function revoke(string $id, string $reviewer, ?string $notes): bool
    {
        $this->db->beginTransaction();
        try {
            $ok = $this->review($id, 'revoked', $reviewer, $notes);
            if ($ok) {
                $this->db->prepare('UPDATE api_keys SET is_active = FALSE WHERE application_id = :id')
                    ->execute([':id' => $id]);
            }
            $this->db->commit();
            return $ok;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $status = null): array
    {
        if ($status === null) {
            $stmt = $this->db->query('SELECT * FROM applications WHERE NOT is_system ORDER BY created_at DESC');
        } else {
            $stmt = $this->db->prepare('SELECT * FROM applications WHERE NOT is_system AND status = :s ORDER BY created_at DESC');
            $stmt->execute([':s' => $status]);
        }
        return $stmt === false ? [] : Cast::rows($stmt->fetchAll());
    }

    private function review(string $id, string $status, string $reviewer, ?string $notes): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE applications SET status = :s, reviewed_by = :r, review_notes = :n, reviewed_at = now(), updated_at = now()
             WHERE id = :id AND NOT is_system'
        );
        $stmt->execute([':s' => $status, ':r' => $reviewer, ':n' => $notes, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
