<?php
class modules_scaler_installer {
    
    function update_1() {
        // Create a table to store content versions
        // which can be used by caching modules
        $sql[] = "create table `dataface__content_versions` (
            `tablename` VARCHAR(100) NOT NULL,
            `username` VARCHAR(100) NOT NULL,
            `version` BIGINT(20) NOT NULL,
            PRIMARY KEY (`tablename`, `username`),
            KEY (`username`)
        ) ENGINE=MyISAM";
        df_q($sql);
    }
}
?>