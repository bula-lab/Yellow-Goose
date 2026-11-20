<?php
namespace SceneStudio;

use PDO;
use ZoltaGaska\Security\{Account, Identity, UserProvider};

final class Accounts implements UserProvider
{
    public function __construct(private PDO $db) {}
    public function findByLogin(string $login): ?Account { return $this->find('login', $login); }
    public function findById(string $id): ?Account { return $this->find('id', $id); }
    private function find(string $column, string $value): ?Account
    {
        $query = $this->db->prepare("SELECT id,password_hash,enabled FROM scene_studio_accounts WHERE $column=?");
        $query->execute([$value]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row ? new Account(new Identity($row['id'], ['user']), $row['password_hash'], (bool) $row['enabled']) : null;
    }
}
