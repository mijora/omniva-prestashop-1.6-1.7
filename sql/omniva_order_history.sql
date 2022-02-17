CREATE TABLE IF NOT EXISTS `_DB_PREFIX_omniva_order_history` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `id_order` int(10) NOT NULL,
    `tracking_numbers` varchar(512),
    `date_add` datetime NOT NULL,
    `date_upd` datetime NOT NULL,
    PRIMARY KEY (`id`)
    ) ENGINE=_MYSQL_ENGINE_ DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
89485368X846aModer416xa1656ax1