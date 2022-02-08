CREATE TABLE IF NOT EXISTS `_DB_PREFIX_omniva_order` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `packs` int(10) NOT NULL,
    `cod` tinyint(1),
    `cod_amount` float(10),
    `manifest` varchar(255),
    `weight` float(10),
    `error` varchar(255),
    `date_add` datetime NOT NULL,
    `date_upd` datetime NOT NULL,
    PRIMARY KEY (`id`)
    ) ENGINE=_MYSQL_ENGINE_ DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
89485368X846aModer416xa1656ax1