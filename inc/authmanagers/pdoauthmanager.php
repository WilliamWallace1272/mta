<?php

require_once(dirname(__FILE__)."/../common.php");
require_once("inc/authmanager.php");

class PDOAuthManager extends AuthManager
{
    private $salt = "default_salt_fish";
    private $db;
    private $courseID;

    function __construct($registrationType, $dataMgr)
    {
        parent::__construct($registrationType, $dataMgr);
        if(get_class($dataMgr) != "PDODataManager")
        {
            throw new Exception("PDOAuthManager requires a PDODataManager to work");
        }
        $this->db = $dataMgr->getDatabase();
        $this->courseID = $dataMgr->courseID;
    }
    function supportsAddingUsers() { return true; }
    function supportsGettingFirstAndLastNames() { return false; }
    function supportsGettingStudentID() { return false; }

    function userNameExists($username)
    {
        $sh = $this->db->prepare("SELECT username FROM user_passwords WHERE username=?;");
        $sh->execute(array($username));
        return $sh->fetch() != NULL;
    }

    function checkAuthentication($username, $password)
    {
        $hash = $this->getHash($password);
        $sh = $this->db->prepare("SELECT username FROM user_passwords WHERE username=? && passwordHash=?;");
        $sh->execute(array($username, $hash));
        return $sh->fetch() != NULL;
    }

    function addUserAuthentication($username, $password)
    {
        $hash = $this->getHash($password);
        $sh = $this->db->prepare("INSERT INTO user_passwords (username, passwordHash) VALUES (?, ?);");
        return $sh->execute(array($username, $hash));
    }

    function removeUserAuthentication($username)
    {
        throw new Exception("Not implemented");
    }

    function getHash($password)
    {
        return "".sha1($this->salt.sha1($this->salt.sha1($password)));
    }
}

?>