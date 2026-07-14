#
# Additional columns added to sys_file_metadata by EXT:mai_assets
#

CREATE TABLE sys_file_metadata (
    tx_maiassets_youtube_consent_enabled tinyint(1) unsigned NOT NULL DEFAULT '1',
    tx_maiassets_youtube_consent_text text,
    tx_maiassets_icon_source_folder varchar(255) NOT NULL DEFAULT ''
);
