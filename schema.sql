-- =====================================================
-- Quiniela Mundial 2026 – Esquema de Base de Datos
-- Para MySQL / MariaDB
-- =====================================================

CREATE TABLE IF NOT EXISTS `User` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('USER', 'ADMIN') NOT NULL DEFAULT 'USER',
  `points` INT NOT NULL DEFAULT 0,
  `hasPaid` TINYINT(1) NOT NULL DEFAULT 0,
  `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Match` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `teamA` VARCHAR(100) NOT NULL,
  `teamB` VARCHAR(100) NOT NULL,
  `flagA` VARCHAR(255) DEFAULT '',
  `flagB` VARCHAR(255) DEFAULT '',
  `date` DATETIME NOT NULL,
  `venue` VARCHAR(255) DEFAULT '',
  `scoreA` INT DEFAULT NULL,
  `scoreB` INT DEFAULT NULL,
  `status` VARCHAR(20) DEFAULT 'SCHEDULED',
  `matchMinute` VARCHAR(10) DEFAULT NULL,
  `externalId` INT DEFAULT NULL,
  `lastApiUpdate` DATETIME DEFAULT NULL,
  `scorersData` TEXT DEFAULT NULL,
  `cardsData` TEXT DEFAULT NULL,
  `isFinished` TINYINT(1) NOT NULL DEFAULT 0,
  `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Prediction` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `userId` INT NOT NULL,
  `matchId` INT NOT NULL,
  `scoreA` INT NOT NULL DEFAULT 0,
  `scoreB` INT NOT NULL DEFAULT 0,
  `points` INT DEFAULT 0,
  `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `user_match_unique` (`userId`, `matchId`),
  FOREIGN KEY (`userId`) REFERENCES `User`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`matchId`) REFERENCES `Match`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Datos iniciales: Partidos de la fase de grupos
-- Fechas en UTC (restar 6h para hora de México)
-- =====================================================

INSERT INTO `Match` (`teamA`, `teamB`, `date`, `venue`) VALUES
-- Jornada 1 – 11 de junio 2026
('México', 'Canadá', '2026-06-12 00:00:00', 'Estadio Azteca, Ciudad de México'),
-- Jornada 1 – 12 de junio 2026
('Argentina', 'Marruecos', '2026-06-12 19:00:00', 'Hard Rock Stadium, Miami'),
('Brasil', 'Japón', '2026-06-12 22:00:00', 'SoFi Stadium, Los Ángeles'),
-- Jornada 1 – 13 de junio 2026
('Francia', 'Colombia', '2026-06-13 19:00:00', 'MetLife Stadium, Nueva Jersey'),
('Alemania', 'Ecuador', '2026-06-14 01:00:00', 'Lincoln Financial Field, Filadelfia'),
-- Jornada 1 – 14 de junio 2026
('España', 'Uruguay', '2026-06-14 22:00:00', 'Mercedes-Benz Stadium, Atlanta'),
('Inglaterra', 'Senegal', '2026-06-15 01:00:00', 'AT&T Stadium, Dallas'),
-- Jornada 1 – 15 de junio 2026
('Portugal', 'Paraguay', '2026-06-15 19:00:00', 'Gillette Stadium, Boston'),
('Estados Unidos', 'Arabia Saudí', '2026-06-16 01:00:00', 'Lumen Field, Seattle');

-- Nota: Puedes agregar más partidos desde el panel de administración.
-- Las fechas/horas exactas del calendario oficial de FIFA 2026
-- pueden variar; actualiza según la información oficial.
