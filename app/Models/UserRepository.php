<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Env;
use PDO;

final class UserRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
    }

    public function ensureDefaultAdmin(): void
    {
        $this->createTableIfMissing();

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM panel_users')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $email = Env::get('PANEL_ADMIN_EMAIL', 'admin@gocartagenarealestate.com');
        $password = Env::get('PANEL_ADMIN_PASSWORD', 'CambiaEstaClave123!');
        $statement = $this->pdo->prepare(
            'INSERT INTO panel_users (name, email, password_hash) VALUES (:name, :email, :password_hash)'
        );
        $statement->execute([
            'name' => 'Administrador',
            'email' => $email,
            'password_hash' => password_hash((string) $password, PASSWORD_DEFAULT),
        ]);
    }

    private function createTableIfMissing(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS panel_users (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              name VARCHAR(120) NOT NULL,
              email VARCHAR(180) NOT NULL,
              password_hash VARCHAR(255) NOT NULL,
              active TINYINT(1) NOT NULL DEFAULT 1,
              last_login_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_panel_users_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM panel_users WHERE email = :email AND active = 1 LIMIT 1');
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();
        return $user ?: null;
    }

    public function markLogin(int $id): void
    {
        $statement = $this->pdo->prepare('UPDATE panel_users SET last_login_at = NOW() WHERE id = :id');
        $statement->execute(['id' => $id]);
    }
}
