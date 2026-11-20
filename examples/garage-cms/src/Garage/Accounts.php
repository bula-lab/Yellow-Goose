<?php
namespace Garage;

use PDO;
use ZoltaGaska\Security\{Account, Identity, UserProvider};

final class Accounts implements UserProvider
{
    public function __construct(private PDO $db) {}

    public function findByLogin(string $login): ?Account { return $this->find('login', $login); }
    public function findById(string $id): ?Account { return $this->find('id', $id); }

    public function canEdit(?Identity $viewer, string $projectId): bool
    {
        if ($viewer === null) return false;
        $query = $this->db->prepare("SELECT 1 FROM garage_accounts WHERE id=? AND project_id=? AND role='editor' AND enabled=1");
        $query->execute([$viewer->id, $projectId]);
        return (bool) $query->fetchColumn();
    }

    private function find(string $column, string $value): ?Account
    {
        $query = $this->db->prepare("SELECT id,password_hash,role,enabled FROM garage_accounts WHERE $column=?");
        $query->execute([$value]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row ? new Account(new Identity($row['id'], [$row['role']]), $row['password_hash'], (bool) $row['enabled']) : null;
    }
}
