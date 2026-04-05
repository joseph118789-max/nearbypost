<?php
function adminer_object() {
    $plugins = [];
    class AdminerDatabase extends AdminerPlugin {
        function credentials() {
            return ["pgsql:host=127.0.0.1;port=5432;dbname=nearbypost", "postgres", "nearbypost123"];
        }
    }
    return new AdminerDatabase($plugins);
}
include_once __DIR__ . "/adminer.php";
