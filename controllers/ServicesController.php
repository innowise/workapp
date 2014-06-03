<?php

/**
 * Class Workapp_ServicesController
 */
class Workapp_ServicesController extends Pimcore_Controller_Action_Admin
{
    /**
     * Called before actually calling any action of controller
     * In fact we can execute some methods and checks that are used by any action
     */
    public function postDispatch()
    {
        parent::postDispatch();

        $this->getRequestData();
        $this->getDeviceSession();
    }

    /**
     * @var array
     */
    protected $requestData = null;

    /**
     * @var null|Workapp_Session
     */
    protected $session = null;

    /**
     * Parses data from payload
     * If you want to change source of data or add some debug parameters this is the best place to
     * @return bool|mixed
     */
    protected function getRequestData()
    {
        if ($this->requestData) {
            return $this->requestData;
        }

        $body = $this->getRequest()->getRawBody();
        try {
            $data = Zend_Json::decode($body);
        } catch (Zend_Exception $e) {
            $this->_response->setHttpResponseCode(400);
            $this->_helper->json(array(
                'message' => 'Broken JSON provided with request'
            ));
        }

        $this->requestData = $data;

        return $this->requestData;
    }


    /**
     * Retrieves session of this device if one exists
     * Remember that device_uid is mandatory field of request payload. Of it is absent, error is always shown!
     * @return bool|Workapp_Session
     * @throws Zend_Exception
     */
    protected function getDeviceSession()
    {
        if ($this->session) {
            return $this->session;
        }

        $data = $this->getRequestData();

        if (!isset($data['device_uid'])) {
            $this->_response->setHttpResponseCode(400);
            $this->_helper->json(array(
                'message' => 'device_uid is absolutely mandatory field for any request!'
            ));
        }

        $session = Workapp_Session::getByDeviceUid($data['device_uid']);
        if ($session) {
            $session->registerAction($_SERVER['REMOTE_ADDR']);
        }

        $this->session = $session;

        return $this->session;
    }


    /**
     * Logs in device if correct username and password provided
     * username and password and mandatory fields in payload
     */
    public function loginAction()
    {
        $data = $this->getRequestData();

        if ($this->getDeviceSession()) {
            $this->_response->setHttpResponseCode(400);
            $this->_helper->json(array(
                'message' => 'Your device is already running a session. Please logout first.'
            ));
        }

        $session = Workapp_Session::login($data['username'], $data['password']);

        if (!$session) {
            $this->_response->setHttpResponseCode(404);
            $this->_helper->json(array(
                'message' => 'No user with such username and password'
            ));
        }

        $session->setDeviceUid($data['device_uid']);
        $session->registerAction($_SERVER['REMOTE_ADDR']);

        $user = $session->getUser();

        $this->_helper->json($user);
    }


    /**
     * Logs out. Obviously.
     */
    public function logoutAction()
    {
        $this->_helper->json($this->getDeviceSession()->getUser());
    }


    /**
     * Returns that data about user by device_uid
     */
    public function getUserAction()
    {
        $session = $this->getDeviceSession();

        if (!$session) {
            $this->_response->setHttpResponseCode(404);
            $this->_helper->json(array(
                'message' => 'This device has no running session'
            ));
        }

        $session->registerAction($_SERVER['REMOTE_ADDR']);

        $user = $session->getUser();

        $this->_helper->json($user);
    }
}