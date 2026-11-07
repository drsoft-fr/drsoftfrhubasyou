-- External reference: identifier of HubAsYou, compared exactly (utf8mb4_bin), the same on every
-- server whatever its default collation. Constraints without a name: unique per database, not
-- per table prefix. Their rules also apply where the module checks them (ShipmentStatus, ShipmentRules)
CREATE TABLE IF NOT EXISTS `_DB_PREFIX_drsoft_fr_hubasyou_shipment`
(
    `id_shipment`        INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `external_reference` VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    `id_order`           INT(10) UNSIGNED NOT NULL,
    `carrier_name`       VARCHAR(64)      NOT NULL,
    `tracking_number`    VARCHAR(64)      NOT NULL,
    `tracking_url`       VARCHAR(255)              DEFAULT NULL,
    `status`             VARCHAR(16)      NOT NULL,
    `shipped_at`         DATETIME                  DEFAULT NULL,
    `delivered_at`       DATETIME                  DEFAULT NULL,
    `mail_sent_at`       DATETIME                  DEFAULT NULL,
    `date_add`           DATETIME         NOT NULL,
    `date_upd`           DATETIME         NOT NULL,
    PRIMARY KEY (`id_shipment`),
    UNIQUE KEY `uniq_dfhays_external_reference` (`external_reference`),
    KEY `idx_dfhays_order` (`id_order`),
    CHECK (`status` IN ('shipped', 'in_transit', 'delivered', 'exception'))
) ENGINE = _MYSQL_ENGINE_
  DEFAULT CHARSET = utf8mb4;

-- One row per order fully delivered: the "order delivered" e-mail is sent only once
CREATE TABLE IF NOT EXISTS `_DB_PREFIX_drsoft_fr_hubasyou_order_mail`
(
    `id_order`               INT(10) UNSIGNED NOT NULL,
    `delivered_mail_sent_at` DATETIME         NOT NULL,
    PRIMARY KEY (`id_order`)
) ENGINE = _MYSQL_ENGINE_
  DEFAULT CHARSET = utf8mb4;

-- The product is copied from the order line: the parcel still says what it held if the
-- line is removed from the order afterwards
CREATE TABLE IF NOT EXISTS `_DB_PREFIX_drsoft_fr_hubasyou_shipment_line`
(
    `id_shipment_line` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shipment`      INT(10) UNSIGNED NOT NULL,
    `id_order_detail`  INT(10) UNSIGNED NOT NULL,
    `quantity`         INT(10) UNSIGNED NOT NULL,
    `product_name`      VARCHAR(255)     NOT NULL DEFAULT '',
    `product_reference` VARCHAR(255)     NOT NULL DEFAULT '',
    PRIMARY KEY (`id_shipment_line`),
    UNIQUE KEY `uniq_dfhaysl_shipment_detail` (`id_shipment`, `id_order_detail`),
    KEY `idx_dfhaysl_order_detail` (`id_order_detail`),
    CHECK (`quantity` > 0),
    FOREIGN KEY (`id_shipment`) REFERENCES `_DB_PREFIX_drsoft_fr_hubasyou_shipment` (`id_shipment`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = _MYSQL_ENGINE_
  DEFAULT CHARSET = utf8mb4;
