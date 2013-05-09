<?php

date_default_timezone_set("America/Vancouver");

#The base location of the site
#$SITEURL="https://www.ugrad.cs.ubc.ca/~cs430/mtanew/";
#$SITEURL="https://www.cs.ubc.ca/mta/";
$SITEURL="http://localhost/~chris/mta/";

$SITEMASTER="cwthornt@cs.ubc.ca";
$SITEMASTERNAME = "Chris";

#What theme you're going to be using
$MTA_THEME="oceania";

#Specify the data manager
$MTA_DATAMANAGER="pdo";
    #Data manager settings
    $MTA_DATAMANAGER_PDO_CONFIG["dsn"] = "mysql:host=localhost;dbname=mta";
    $MTA_DATAMANAGER_PDO_CONFIG["username"] = "mta";
    $MTA_DATAMANAGER_PDO_CONFIG["password"] = "mta";
    //$MTA_DATAMANAGER_PDO_CONFIG["dsn"] = "mysql:host=kunghit.ugrad.cs.ubc.ca;dbname=mta;port=4045";
    //$MTA_DATAMANAGER_PDO_CONFIG["username"] = "mtauser";
    //$MTA_DATAMANAGER_PDO_CONFIG["password"] = "turnitin";

//What type of assignments have been installed?
$MTA_ASSIGNMENTS = array(
    "peerreview",
    "grouppicker",
);

//TODO: Remove this and put then in the data manager
$cfgLDAPAuthMgrHost = 'ldap.cs.ubc.ca';
$cfgLDAPAuthMgrPort = 389;
$cfgLDAPAuthMgrBaseDN  = 'ou=People,dc=cs,dc=ubc,dc=ca';
?>
