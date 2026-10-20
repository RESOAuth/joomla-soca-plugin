CREATE TABLE IF NOT EXISTS `#__soca_links` (
  `identityhash` char(64) NOT NULL,
  `userid` int unsigned NOT NULL,
  `created` bigint NOT NULL,
  PRIMARY KEY (`identityhash`), UNIQUE KEY `user_unique` (`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `#__soca_transactions` (
  `statehash` char(64) NOT NULL,
  `expires` bigint NOT NULL,
  PRIMARY KEY (`statehash`), KEY `expiry` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `#__soca_evidence` (
  `kind` varchar(16) NOT NULL,
  `revision` char(64) NOT NULL,
  PRIMARY KEY (`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `#__soca_audit` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `actor` int unsigned NOT NULL,
  `action` varchar(32) NOT NULL,
  `userid` int unsigned NOT NULL,
  `created` bigint NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
