<?php

/**
 * Class Workapp_Session
 */
class Workapp_Session extends Pimcore_Model_Abstract
{
    /**
     * @var
     */
    protected $id;
    /**
     * @var
     */
    protected $user_id;
    /**
     * @var
     */
    protected $session_uid;
    /**
     * @var
     */
    protected $created_date;
    /**
     * @var
     */
    protected $last_action_date;
    /**
     * @var
     */
    protected $last_action_ip;
    /**
     * @var
     */
    protected $device_token;


    /**
     * @param $username
     * @param $password
     * @return bool|Workapp_Session
     */
    public static function login($username, $password)
    {
        $user = Object_User::getList(array(
            'condition' => "`Name` LIKE " . Pimcore_Resource::get()->quote($username)
                . " AND `Password` LIKE " . Pimcore_Resource::get()->quote($password)
        ))->current();

        if (!$user) {
            return false;
        }

        $session = new self;
        $session->setUserId($user->getId());
        $session->setSessionUid(md5(uniqid()));
        $session->setCreatedDate(Zend_Date::now());
        $session->setLastActionDate(Zend_Date::now());

        return $session;
    }


    /**
     * Returns a session by session_uid if one exists
     * @param $sessionUid
     * @return bool|Workapp_Session
     */
    public static function getBySessionUid($sessionUid)
    {
        $session = new self;
        try {
            $session->getResource()->getBySessionUid($sessionUid);
        } catch (Zend_Exception $e) {
            return false;
        }
        return $session;
    }


    /**
     * A proxy nice method that returns User Object
     * @return Object_User
     */
    public function getUser()
    {
        return Object_User::getById($this->getUserId());
    }


    /**
     * Call this method whenever you want to register activity.
     * E.g. you don't need it when doing status reports or things like that
     * @param $ip
     */
    public function registerAction($ip)
    {
        $this->setLastActionDate(Zend_Date::now());
        $this->setLastActionIp($ip);
        $this->save();
    }


    /**
     * @param $sessionId
     * @param $userId
     */
    public function logoutAction($sessionId, $userId){
        $this->getResource()->logout($sessionId, $userId);
    }


    /**
     * Call this method whenever you want to update device_token
     * @param $token
     */
    public function addDeviceToken($token){
        $this->setDeviceToken($token);
        $this->save();
    }


    /**
     * This overwrite is used to show IDE what kind of resource is returned (for auto complete)
     * @return Workapp_Session_Resource
     */
    public function getResource()
    {
        return parent::getResource();
    }


    /**
     * Basic Pimcore setValue method uses awful conversion conventions only useful if you put CapitaliseCase names to
     * mysql columns which I'm not a fan of
     * @param $key
     * @param $value
     * @return $this
     */
    public function setValue($key, $value)
    {
        $method = "set" . ucfirst(preg_replace_callback('/_[a-z]?/', function ($matches) {
                return strtoupper(ltrim($matches[0], "_"));
            }, $key));
        if (method_exists($this, $method)) {
            $this->$method($value);
        } else if (method_exists($this, "set" . preg_replace("/^o_/", "", $key))) {
            // compatibility mode for objects (they do not have any set_oXyz() methods anymore)
            $this->$method($value);
        }
        return $this;
    }


    /**
     * Simple save method
     */
    public function save()
    {
        $this->getResource()->save();
    }

    /**
     * Nothing to interesting from this point - only setters and getters
     */

    /**
     * @param mixed $created_date
     */
    public function setCreatedDate($created_date)
    {
        $this->created_date = $created_date instanceof Zend_Date ? $created_date : new Zend_Date($created_date, Zend_Date::ISO_8601);
    }

    /**
     * @return mixed
     */
    public function getCreatedDate()
    {
        return $this->created_date;
    }

    /**
     * @param mixed $id
     */
    public function setId($id)
    {
        $this->id = $id;
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param mixed $last_action_date
     */
    public function setLastActionDate($last_action_date)
    {
        $this->last_action_date = $last_action_date instanceof Zend_Date ? $last_action_date : new Zend_Date($last_action_date, Zend_Date::ISO_8601);
    }

    /**
     * @return mixed
     */
    public function getLastActionDate()
    {
        return $this->last_action_date;
    }

    /**
     * @param mixed $last_action_ip
     */
    public function setLastActionIp($last_action_ip)
    {
        $this->last_action_ip = $last_action_ip;
    }

    /**
     * @return mixed
     */
    public function getLastActionIp()
    {
        return $this->last_action_ip;
    }

    /**
     * @param mixed $user_id
     */
    public function setUserId($user_id)
    {
        $this->user_id = $user_id;
    }

    /**
     * @return mixed
     */
    public function getUserId()
    {
        return $this->user_id;
    }

    /**
     * @param mixed $device_token
     */
    public function setDeviceToken($device_token)
    {
        $this->device_token = $device_token;
    }

    /**
     * @return mixed
     */
    public function getDeviceToken()
    {
        return $this->device_token;
    }

    /**
     * @param mixed $session_uid
     */
    public function setSessionUid($session_uid)
    {
        $this->session_uid = $session_uid;
    }

    /**
     * @return mixed
     */
    public function getSessionUid()
    {
        return $this->session_uid;
    }



}