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


    /**
     * get list of user activities
     * getoperations optional field for loading relative operations
     */
    public function getUserActivitiesAction()
    {
        $getoperations = false;
        $activity = new Workapp_Activity();
        $session = $this->getDeviceSession();
        $user_id = $session ? $session->getUserId() : 0;
        $data = $this->getRequestData();
        if (isset($data['getoperations'])) {
            $getoperations = $data['getoperations'];
        }

        $activities = $activity->getActivityList(array('user_id' => $user_id, 'getoperations' => $getoperations));
        $this->_helper->json($activities);
    }


    /**
     * returns user activity by activity_id
     * activity_id mandatory field
     */
    public function getUserActivityByIdAction()
    {
        $data = $this->getRequestData();
        $activity = Object_Activity::getById($data['activity_id']);
        if (!$this->getDeviceSession() || !$activity || $this->getDeviceSession()->getUserId() != $activity->Creator->o_id) {
            $activity = null;
        }
        $this->_helper->json($activity);
    }


    /**
     * delete user activity with relative operations
     * activity_id mandatory field
     */
    public function deleteUserActivityAction()
    {
        $response['response'] = false;
        $data = $this->getRequestData();
        $activity = Object_Activity::getById($data['activity_id']);

        if ($this->getDeviceSession() && $activity && $this->getDeviceSession()->getUserId() == $activity->Creator->o_id) {
            $activity->o_published = false;
            if ($activity->save()) {
                $operations = new Workapp_Activity();
                $op = $operations->getActivityRequiredByOperations($activity);
                foreach ($op as $operation) {
                    $operation = Object_Operation::getById($operation->o_id);
                    $operation->o_published = false;
                    if ($operation->save()) {
                        $response['response'] = true;
                    }
                }
            }
        }
        $this->_helper->json($response);
    }


    /**
     * this action creates user activity
     * title is mandatory field
     */
    public function createUserActivityAction()
    {
        $data = $this->getRequestData();
        $activity = null;
        if ($this->getDeviceSession() && isset($data['title'])) {
            $user = Object_User::getById($this->getDeviceSession()->getUserId());

            $folder = Object_Folder::getByPath('/activities/' . $user->o_key . "-activities");
            if (!$folder) {
                $folder = new Object_Folder();
                $folder->setKey($user->o_key . "-activities");
                $folder->setParentId(3);
                $folder->save();
            }

            $activity = new Object_Activity();
            $activity->setCreator($user);
            $activity->setTitle($data['title']);
            $activity->setKey(Pimcore_File::getValidFilename($data['title']));
            $activity->setPublished(true);
            $activity->setParentId($folder->o_id);
            if (!$activity->save()) {
                $activity = null;
            }
        }

        $this->_helper->json($activity);
    }


    /**
     * get list of user todos
     */
    public function getUserTodosAction()
    {
        $todo = new Workapp_Todo();
        $session = $this->getDeviceSession();
        $user_id = $session ? $session->getUserId() : 0;
        $todos = $todo->getTodoList(array('user_id' => $user_id));
        $this->_helper->json($todos);
    }


    /**
     * returns user tod by todo_id
     * todo_id mandatory field
     */
    public function getUserTodoByIdAction()
    {
        $data = $this->getRequestData();
        $todo = Object_Todo::getById($data['todo_id']);
        if (!$this->getDeviceSession() || !$todo || $this->getDeviceSession()->getUserId() != $todo->Creator->o_id) {
            $todo = null;
        }
        $this->_helper->json($todo);
    }


    /**
     * delete user todos by todo_id
     * todo_id mandatory field
     */
    public function deleteUserTodoAction()
    {
        $response['response'] = false;
        $data = $this->getRequestData();
        $todo = Object_Todo::getById($data['todo_id']);
        if ($this->getDeviceSession() && $todo && $this->getDeviceSession()->getUserId() == $todo->Creator->o_id) {
            $todo->o_published = false;
            if ($todo->save()) {
                $response['response'] = true;
            }
        }
        $this->_helper->json($response);
    }


    /**
     * this action creates user todo
     * todo_type is mandatory field
     */
    public function createUserTodoAction()
    {
        $data = $this->getRequestData();
        $todo = null;
        if ($this->getDeviceSession() && isset($data['todo_type'])) {
            $user = Object_User::getById($this->getDeviceSession()->getUserId());

            $folder = Object_Folder::getByPath('/todo/' . $user->o_key . "-todo");
            if (!$folder) {
                $folder = new Object_Folder();
                $folder->setKey($user->o_key . "-todo");
                $folder->setParentId(30);
                $folder->save();
            }
            $todo = new Object_Todo();
            $todo->setCreator($user);
            $todo->setTodo_type($data['todo_type']);
            $todo->setText(isset($data['text']) ? $data['text'] : "");
            $todo->setKey(Pimcore_File::getValidFilename($user->o_key . "-" . time()));
            $todo->setPublished(true);
            $todo->setParentId($folder->o_id);
            if (!$todo->save()) {
                $todo = null;
            }
        }

        $this->_helper->json($todo);
    }


    /**
     * get list of user operations
     */
    public function getUserOperationsAction()
    {
        $operation = new Workapp_Operation();
        $session = $this->getDeviceSession();
        $user_id = $session ? $session->getUserId() : 0;
        $operations = $operation->getOperationList(array('user_id' => $user_id));
        $this->_helper->json($operations);
    }


    /**
     * returns user operation by operation_id
     * operation_id mandatory field
     */
    public function getUserOperationByIdAction()
    {
        $data = $this->getRequestData();
        $operation = Object_Operation::getById($data['operation_id']);
        if (!$this->getDeviceSession() || !$operation || $this->getDeviceSession()->getUserId() != $operation->Creator->o_id) {
            $operation = null;
        }
        $this->_helper->json($operation);
    }


    /**
     * delete user operation by operation_id
     * operation_id mandatory field
     */
    public function deleteUserOperationAction()
    {
        $response['response'] = false;
        $data = $this->getRequestData();
        $operation = Object_Operation::getById($data['operation_id']);
        if ($this->getDeviceSession() && $operation && $this->getDeviceSession()->getUserId() == $operation->Creator->o_id) {
            $operation->o_published = false;
            if ($operation->save()) {
                $response['response'] = true;
            }
        }
        $this->_helper->json($response);
    }


    /**
     * this action creates user operation
     * title and activity_id is mandatory field
     */
    public function createUserOperationAction()
    {
        $data = $this->getRequestData();
        $operation = null;
        if ($this->getDeviceSession() && isset($data['title']) && isset($data['activity_id'])) {
            $user = Object_User::getById($this->getDeviceSession()->getUserId());

            $folder = Object_Folder::getByPath('/operations/' . $user->o_key . "-operations");
            if (!$folder) {
                $folder = new Object_Folder();
                $folder->setKey($user->o_key . "-operations");
                $folder->setParentId(4);
                $folder->save();
            }

            //$geo = new Object_Data_Geopoint($data['longtitude'], $data['latitude']);

            $operation = new Object_Operation();
            $operation->setCreator($user);
            $operation->setTitle($data['title']);
            $operation->setExplanation(isset($data['explanation']) ? $data['explanation'] : "");
            //$operation->setLocation()
            $operation->setKey(Pimcore_File::getValidFilename($user->o_key . "-" . $data['title'] . "-" . time()));
            $operation->setPublished(true);
            $operation->setParentId($folder->o_id);
            if (!$operation->save()) {
                $operation = null;
            }
        }

        $this->_helper->json($operation);
    }


    /**
     * get list of user agendas
     */
    public function getUserAgendasAction()
    {
        $agenda = new Workapp_Agenda();
        $session = $this->getDeviceSession();
        $user_id = $session ? $session->getUserId() : 0;
        $agendas = $agenda->getAgendaList(array('user_id' => $user_id));
        $this->_helper->json($agendas);
    }


    /**
     * returns user agenda by agenda_id
     * agenda_id mandatory field
     */
    public function getUserAgendaByIdAction()
    {
        $data = $this->getRequestData();
        $agenda = Object_Agenda::getById($data['agenda_id']);
        if (!$this->getDeviceSession() || !$agenda || $this->getDeviceSession()->getUserId() != $agenda->Creator->o_id) {
            $agenda = null;
        }
        $this->_helper->json($agenda);
    }


    /**
     * delete user agenda by agenda_id
     * agenda_id mandatory field
     */
    public function deleteUserAgendaAction()
    {
        $response['response'] = false;
        $data = $this->getRequestData();
        $agenda = Object_Agenda::getById($data['agenda_id']);
        if ($this->getDeviceSession() && $agenda && $this->getDeviceSession()->getUserId() == $agenda->Creator->o_id) {
            $agenda->o_published = false;
            if ($agenda->save()) {
                $response['response'] = true;
            }
        }
        $this->_helper->json($response);
    }


    /**
     * this action creates user agenda
     * topic and title is mandatory field
     */
    public function createUserAgendaAction()
    {
        $data = $this->getRequestData();
        $agenda = null;
        if ($this->getDeviceSession() && isset($data['topic']) && isset($data['title'])) {
            $user = Object_User::getById($this->getDeviceSession()->getUserId());

            $folder = Object_Folder::getByPath('/agenda/' . $user->o_key . "-agenda");
            if (!$folder) {
                $folder = new Object_Folder();
                $folder->setKey($user->o_key . "-agenda");
                $folder->setParentId(51);
                $folder->save();
            }
            $agenda = new Object_Agenda();
            $agenda->setCreator($user);
            $agenda->setTopic($data['topic']);
            $agenda->setTitle($data['title']);
            $agenda->setNotes(isset($data['notes']) ? $data['notes'] : "");
            $agenda->setStart_time(isset($data['start_time']) ? $data['start_time'] : "");
            $agenda->setEnd_time(isset($data['end_time']) ? $data['end_time'] : "");
            $agenda->setWith_whom(isset($data['with_whom']) ? $data['with_whom'] : "");
            $agenda->setLocation(isset($data['location']) ? $data['location'] : "");
            $agenda->setAlarm(isset($data['alarm']) ? $data['alarm'] : "");
            $agenda->setRepeat_days(isset($data['repeat_days']) ? $data['repeat_days'] : array());
            $agenda->setKey(Pimcore_File::getValidFilename($user->o_key . "-" . $data['title'] . "-" . time()));
            $agenda->setPublished(true);
            $agenda->setParentId($folder->o_id);
            if (!$agenda->save()) {
                $agenda = null;
            }
        }

        $this->_helper->json($agenda);
    }


    /**
     * get list of user peoples
     */
    public function getUserPeoplesAction()
    {
        $people = new Workapp_People();
        $session = $this->getDeviceSession();
        $user_id = $session ? $session->getUserId() : 0;
        $peoples = $people->getPeopleList(array('user_id' => $user_id));
        $this->_helper->json($peoples);
    }


    /**
     * returns user people by people_id
     * people_id mandatory field
     */
    public function getUserPeopleByIdAction()
    {
        $data = $this->getRequestData();
        $people = Object_People::getById($data['people_id']);
        if (!$this->getDeviceSession() || !$people || $this->getDeviceSession()->getUserId() != $people->Creator->o_id) {
            $people = null;
        }
        $this->_helper->json($people);
    }


    /**
     * delete user people by people_id
     * people_id mandatory field
     */
    public function deleteUserPeopleAction()
    {
        $response['response'] = false;
        $data = $this->getRequestData();
        $people = Object_People::getById($data['people_id']);
        if ($this->getDeviceSession() && $people && $this->getDeviceSession()->getUserId() == $people->Creator->o_id) {
            $people->o_published = false;
            if ($people->save()) {
                $response['response'] = true;
            }
        }
        $this->_helper->json($response);
    }


    /**
     * this action creates user people
     * name is mandatory field
     */
    public function createUserPeopleAction()
    {
        $data = $this->getRequestData();
        $people = null;
        if ($this->getDeviceSession() && isset($data['name'])) {
            $user = Object_User::getById($this->getDeviceSession()->getUserId());

            $folder = Object_Folder::getByPath('/peoples/' . $user->o_key . "-people");
            if (!$folder) {
                $folder = new Object_Folder();
                $folder->setKey($user->o_key . "-people");
                $folder->setParentId(52);
                $folder->save();
            }
            $people = new Object_People();
            $people->setCreator($user);
            $people->setName($data['name']);
            $people->setCompany(isset($data['company']) ? $data['company'] : "");
            $people->setPhone(isset($data['phone']) ? $data['phone'] : "");
            $people->setEmail(isset($data['email']) ? $data['email'] : "");

            $people->setKey(Pimcore_File::getValidFilename($user->o_key . "-" . $data['name'] . "-" . time()));
            $people->setPublished(true);
            $people->setParentId($folder->o_id);
            if (!$people->save()) {
                $people = null;
            }
        }

        $this->_helper->json($people);
    }
}