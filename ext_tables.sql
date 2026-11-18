#
# Table structure for table 'tx_maiassets_warmup_queue'
#
CREATE TABLE tx_maiassets_warmup_queue (
    uid int(11) NOT NULL auto_increment,
    cache_url varchar(500) NOT NULL,
    cache_priority int(11) DEFAULT '0' NOT NULL,
    page_uid int(11) DEFAULT '0' NOT NULL,
    queued_date int(11) DEFAULT '0' NOT NULL,
    call_date int(11) DEFAULT '0' NOT NULL,
    call_result varchar(255) NOT NULL,
    PRIMARY KEY (uid),
    INDEX call_date (call_date, cache_url(100)),
    INDEX cache_url (cache_url(255)),
    INDEX cache_priority (cache_priority)
);

#
# Table structure for table 'tx_maiassets_image_measure'
#
CREATE TABLE tx_maiassets_image_measure (
    uid int(11) NOT NULL auto_increment,
    slot_key varchar(40) DEFAULT '' NOT NULL,
    vp_step int(11) DEFAULT '0' NOT NULL,
    css_width int(11) DEFAULT '0' NOT NULL,
    hits int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) DEFAULT '0' NOT NULL,
    PRIMARY KEY (uid),
    UNIQUE KEY slot_sample (slot_key, vp_step, css_width),
    KEY tstamp (tstamp)
);

#
# Critical override on content elements: 0 = automatic, 1 = critical, 2 = not critical.
# Declared explicitly so the column keeps the former checkbox type (no data migration needed).
#
CREATE TABLE tt_content (
    tx_maiassets_is_critical smallint(5) unsigned DEFAULT '0' NOT NULL
);

#
# Table structure for table 'tx_maiassets_image_measure_page'
# Pages an image slot was reported from, so a changed profile refreshes all of them.
#
CREATE TABLE tx_maiassets_image_measure_page (
    uid int(11) NOT NULL auto_increment,
    slot_key varchar(40) DEFAULT '' NOT NULL,
    page_uid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) DEFAULT '0' NOT NULL,
    PRIMARY KEY (uid),
    UNIQUE KEY slot_page (slot_key, page_uid)
);
