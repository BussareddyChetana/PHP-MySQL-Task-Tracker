-- Task Tracker schema + demo data. Import with:  mysql -u root -p < database.sql
CREATE DATABASE IF NOT EXISTS task_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE task_tracker;

DROP TABLE IF EXISTS tasks;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,              -- output of password_hash(), never plain text
  role          ENUM('user','admin') NOT NULL DEFAULT 'user',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

CREATE TABLE tasks (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,                -- owner; basis of the ownership check
  title       VARCHAR(150) NOT NULL,
  description TEXT NULL,
  status      ENUM('Pending','In Progress','Completed') NOT NULL DEFAULT 'Pending',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tasks_user_status (user_id, status),      -- dashboard list + status filter
  KEY idx_tasks_title (title),
  CONSTRAINT fk_tasks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Demo accounts (passwords: Admin@123 / Alice@123 / Bob@12345). Change or remove outside local testing.
INSERT INTO users (id, name, email, password_hash, role) VALUES
 (1, 'Admin User', 'admin@example.com', '$2y$10$dglzWQ0e1ZbWe93qL07MzOJSQYhYyygL5hN9.C5H.sgiCGVCOHhGa', 'admin'),
 (2, 'Alice',      'alice@example.com', '$2y$10$X2CG8egP.Ldhk6q1kmgOoeRwKadqs.ud5p81E/Y9cNC9dFvpTO2La', 'user'),
 (3, 'Bob',        'bob@example.com',   '$2y$10$0H70JbmN7inXP8K8tLBwqO7pO.9X1tSUW0vzf66BtXkcNVqAoPBSu', 'user');

INSERT INTO tasks (user_id, title, description, status) VALUES
 (2, 'Write project proposal', 'Draft the one-page proposal for the review meeting.', 'In Progress'),
 (2, 'Book meeting room', 'Room for Friday, 3 PM.', 'Pending'),
 (2, 'Send weekly report', NULL, 'Completed'),
 (3, 'Fix login page typo', 'The heading says "Loging".', 'Pending'),
 (3, 'Review pull request #12', 'Check validation changes.', 'In Progress');
