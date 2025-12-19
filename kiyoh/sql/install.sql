-- Kiyoh Module Database Installation
-- This file contains the SQL statements to create the necessary database tables

-- Product sync tracking table
CREATE TABLE IF NOT EXISTS `PREFIX_kiyoh_product_sync` (
    `id_sync` int(11) NOT NULL AUTO_INCREMENT,
    `id_product` int(11) NOT NULL,
    `last_sync` datetime DEFAULT NULL,
    `sync_status` varchar(20) DEFAULT 'pending',
    `error_message` text,
    `date_add` datetime NOT NULL,
    `date_upd` datetime NOT NULL,
    PRIMARY KEY (`id_sync`),
    KEY `id_product` (`id_product`),
    KEY `sync_status` (`sync_status`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8;

-- Invitation tracking table
CREATE TABLE IF NOT EXISTS `PREFIX_kiyoh_invitations` (
    `id_invitation` int(11) NOT NULL AUTO_INCREMENT,
    `id_order` int(11) NOT NULL,
    `customer_email` varchar(255) NOT NULL,
    `invitation_type` varchar(20) DEFAULT 'both',
    `sent_at` datetime DEFAULT NULL,
    `delay_days` int(11) DEFAULT 0,
    `status` varchar(20) DEFAULT 'pending',
    `response_data` text,
    `date_add` datetime NOT NULL,
    PRIMARY KEY (`id_invitation`),
    KEY `id_order` (`id_order`),
    KEY `customer_email` (`customer_email`),
    KEY `status` (`status`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8;