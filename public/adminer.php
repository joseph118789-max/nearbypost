<?php
function adminer_object() {
    class AdminerDatabase extends AdminerPlugin {
        function credentials() {
            return ["pgsql:host=127.0.0.1;port=5432;dbname=nearbypost", "postgres", "nearbypost123"];
        }
    }
    return new AdminerDatabase([]);
}
include_once dirname(__FILE__) . "/adminer.php";
