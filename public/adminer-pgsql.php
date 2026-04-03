<?php
function adminer_object() {
    class AutoLoginAdminer extends Adminer {
        function credentials() {
            return ['pgsql:host=127.0.0.1;port=5432;dbname=nearbypost', 'postgres', 'nearbypost123'];
        }
    }
    return new AutoLoginAdminer;
}
include dirname(__FILE__) . '/adminer.php';
