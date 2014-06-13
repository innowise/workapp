<?php

/**
 * Class Workapp_ServicesController
 */
class Workapp_ServicesController extends Pimcore_Controller_Action
{
    /**
     * Called before actually calling any action of controller
     * In fact we can execute some methods and checks that are used by any action
     */
    public function postDispatch()
    {
        parent::postDispatch();

        $this->getRequestData();
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
            $this->setErrorResponse('Broken JSON provided with request');
        }

        $this->requestData = $data;

        return $this->requestData;
    }


    /**
     * Retrieves session of this device if one exists
     * Remember that device_uid is mandatory field of request payload. Of it is absent, error is always shown!
     * @param bool
     * @return bool|Workapp_Session
     * @throws Zend_Exception
     */
    protected function getDeviceSession($sessionRequired = true)
    {
        if ($this->session) {
            return $this->session;
        }

        $data = $this->getRequestData();

        if (!isset($data['session_uid']) && $sessionRequired) {
            $this->setErrorResponse('session_uid is mandatory for this request!');
        }

        $session = Workapp_Session::getBySessionUid($data['session_uid']);
        if ($session) {
            $session->registerAction($_SERVER['REMOTE_ADDR']);
        } else if ($sessionRequired) {
            $this->setErrorResponse('This device has no running session which is required by service');
        }

        $this->session = $session;

        return $this->session;
    }


    /**
     * Serves as a single entry point for universal static route
     */
    public function proxyAction()
    {
        $action = $this->getParam('call');
        $this->forward($action);
    }


    /**
     * Logs in device if correct username and password provided
     * username and password and mandatory fields in payload
     */
    public function loginAction()
    {
        $data = $this->getRequestData();
        if ($this->getDeviceSession(false)) {
            $this->setErrorResponse('Your device is already running a session. Please logout first.');
        }
        $session = Workapp_Session::login($data['username'], $data['password']);
        if (!$session) {
            $this->setErrorResponse('No user with such username and password');
        }
        $session->registerAction($_SERVER['REMOTE_ADDR']);
        $this->_helper->json(array('session_uid' => $session->getSessionUid()));
    }


    /**
     * Logs out. Obviously.
     */
    public function logoutAction()
    {
        $session = new Workapp_Session();
        $devSession = $this->getDeviceSession(true);
        $session->logoutAction($devSession->getId(), $devSession->getUserId());
        $this->_helper->json(array('logout' => true));
    }


    /**
     * Returns that data about user by device_uid
     */
    public function getUserAction()
    {
        $session = $this->getDeviceSession();

        if (!$session) {
            $this->setErrorResponse('This device has no running session');
        }

        $session->registerAction($_SERVER['REMOTE_ADDR']);

        $user = $session->getUser();

        $this->_helper->json($user);
    }


    /**
     * This method allows to set error message
     * @param $message
     * @param int $errorCode
     */
    private function setErrorResponse($message, $errorCode = 200)
    {
        $this->_response->setHttpResponseCode($errorCode);
        $this->_helper->json(array(
            'message' => $message
        ));
    }


    /**
     * get list of user activities
     * getoperations optional field for loading relative operations
     */
    public function getUserActivitiesAction()
    {
        $getOperations = false;
        $activity = new Workapp_Activity();
        $data = $this->getRequestData();
        if (isset($data['getoperations'])) {
            $getOperations = $data['getoperations'];
        }
        $this->_helper->json($activity->getActivityList(array('user_id' => $this->getDeviceSession()->getUserId(), 'getoperations' => $getOperations)));
    }


    /**
     * returns user activity by activity_id
     * activity_id mandatory field
     */
    public function getUserActivityByIdAction()
    {
        /** @var Object_Activity $activity */
        $data = $this->getRequestData();
        if (isset($data['activity_id'])) {
            $activity = Object_Activity::getById($data['activity_id']);
            if (!$activity) {
                $this->setErrorResponse('no Activity with this activity_id!');
            } elseif ($this->getDeviceSession()->getUserId() != $activity->getCreator()->getId()) {
                $this->setErrorResponse('no Activity for this user with current activity_id!');
            }
        } else {
            $this->setErrorResponse('activity_id is mandatory field for this request!');
        }

        $this->_helper->json($activity);
    }

    /**
     * delete user activity with relative operations
     * activity_id mandatory field
     */
    public function deleteUserActivityAction()
    {
        /** @var Object_Activity $activity */
        /** @var Object_Operation $operation */
        $data = $this->getRequestData();
        if (isset($data['activity_id'])) {
            $activity = Object_Activity::getById($data['activity_id']);
            if (!$activity) {
                $this->setErrorResponse('no Activity with this activity_id!');
            } elseif ($this->getDeviceSession()->getUserId() == $activity->getCreator()->getId()) {
                $activity->setPublished(false);
                if ($activity->save()) {
                    $operations = new Workapp_Activity();
                    $op = $operations->getActivityRequiredByOperations($activity);
                    foreach ($op as $operation) {
                        $operation = Object_Operation::getById($operation->getId());
                        $operation->setPublished(false);
                        if (!$operation->save()) {
                            $this->setErrorResponse('cannot delete relative Operation objects!');
                        }
                    }
                } else {
                    $this->setErrorResponse('cannot delete Activity object!');
                }
            } else {
                $this->setErrorResponse('no Activity for this user with current activity_id!');
            }
        } else {
            $this->setErrorResponse('activity_id is mandatory field for this request!');

        }
        $this->_helper->json(array('deleted' => true));
    }


    /**
     * this action creates user activity
     * title is mandatory field
     * photo is optional field
     */
    public function createUserActivityAction()
    {
        $data = $this->getRequestData();
        if (isset($data['title'])) {
            $user = Object_User::getById($this->getDeviceSession()->getUserId());

            $folder = Object_Folder::getByPath('/activities/' . $user->getKey() . "-activities");
            if (!$folder) {
                $folder = new Object_Folder();
                $folder->setKey($user->getKey() . "-activities");
                $folder->setParentId(3);
                $folder->save();
            }

            $activity = new Object_Activity();
            $activity->setCreator($user);
            $activity->setTitle($data['title']);
            $activity->setPhoto(isset($data['photo']) ? Asset_Image::getById($data['photo']) : "");
            $activity->setKey(Pimcore_File::getValidFilename($user->getKey() . "-" . $data['title'] . "-" . time()));
            $activity->setPublished(true);
            $activity->setParentId($folder->getId());
            if (!$activity->save()) {
                $this->setErrorResponse('cannot save Activity object');
            }
        } else {
            $this->setErrorResponse('title is mandatory field for this request!');
        }

        $this->_helper->json($activity);
    }


    /**
     * this action updates user activity by activity_id
     * title is mandatory field
     * photo is optional field
     */
    public function updateUserActivityAction()
    {
        /** @var Object_Activity $activity */
        $data = $this->getRequestData();
        if (isset($data['activity_id'])) {
            $activity = Object_Activity::getById($data['activity_id']);
            if (!$activity) {
                $this->setErrorResponse('no Activity with this activity_id!');
            } elseif ($this->getDeviceSession()->getUserId() != $activity->getCreator()->getId()) {
                $this->setErrorResponse('you have no rights to change this Activity!');
            } else {
                if (isset($data['title'])) {
                    $activity->setTitle($data['title']);
                }
                if (isset($data['photo'])) {
                    $activity->setPhoto(Asset_Image::getById($data['photo']));
                }
                if (!$activity->save()) {
                    $this->setErrorResponse('cannot update Activity object');
                }
            }
        } else {
            $this->setErrorResponse('activity_id is mandatory field for this request!');
        }

        $this->_helper->json($activity);
    }


    /**
     * this methos allows user to add photo to objects
     * ['photo']['name'], ['photo']['content'] and type is mandatory fields
     */
    public function addPhotoAction(){
        $data = $this->getRequestData();
        $types = array('activity', 'operation', 'people');
        if(isset($data['photo']['name']) && isset($data['photo']['content']) && isset($data['type']) && in_array($data['type'], $types)){
            $user = Object_User::getById($this->getDeviceSession()->getUserId());
            $folder = Asset_Folder::getByPath('/images/'.$data['type'].'/'.$user->getKey().'-'.$data['type']);
            if (!$folder) {
                switch($data['type']){
                    case 'activity':
                        $fid = 3;
                        break;
                    case 'operation':
                        $fid = 4;
                        break;
                    case 'people':
                        $fid = 7;
                        break;
                }
                $folder = new Object_Folder();
                $folder->setKey($user->getKey() . "-" . $data['type']);
                $folder->setParentId($fid);
                $folder->save();
            }

            $asset = new Asset_Image();
            $asset->setCreationDate(time());
            $asset->setUserOwner(1);
            $asset->setUserModification(1);
            $asset->setParentId($folder->getId());
            $asset->setFilename(Pimcore_File::getValidFilename($data['name'] . "-" . time()));
            $asset->setData(base64_decode($data['content']));
            if(!$asset->save()){
                $this->setErrorResponse('cannot save photo!');
            }
        } else {
            $this->setErrorResponse('photo and type is mandatory for this request!');
        }

        $this->_helper->json(array('photo' => $asset->getId()));
    }


    /**
     * this methos allows user to add video to operation object
     * ['video']['name'], ['video']['content'] is mandatory fields
     */
    public function addVideoAction(){
        $data = $this->getRequestData();
        if(isset($data['video']['name']) && isset($data['video']['content'])){
            $user = Object_User::getById($this->getDeviceSession()->getUserId());
            $folder = Asset_Folder::getByPath('/video/operation/'.$user->getKey().'-video');
            if (!$folder) {
                $folder = new Object_Folder();
                $folder->setKey($user->getKey() . "-video");
                $folder->setParentId(6);
                $folder->save();
            }

            $asset = new Asset_Video();
            $asset->setCreationDate(time());
            $asset->setUserOwner(1);
            $asset->setUserModification(1);
            $asset->setParentId($folder->getId());
            $asset->setFilename(Pimcore_File::getValidFilename($data['name'] . "-" . time()));
            $asset->setData(base64_decode($data['content']));
            if(!$asset->save()){
                $this->setErrorResponse('cannot save video!');
            }
        } else {
            $this->setErrorResponse('video is mandatory for this request!');
        }

        $this->_helper->json(array('video' => $asset->getId()));
    }


    /**
     * get list of user todos
     */
    public function getUserTodosAction()
    {
        $todo = new Workapp_Todo();
        $this->_helper->json($todo->getTodoList(array('user_id' => $this->getDeviceSession()->getUserId())));
    }


    /**
     * returns user tod by todo_id
     * todo_id mandatory field
     */
    public function getUserTodoByIdAction()
    {
        /** @var Object_Todo $todo */
        $data = $this->getRequestData();
        if (isset($data['todo_id'])) {
            $todo = Object_Todo::getById($data['todo_id']);
            if (!$todo) {
                $this->setErrorResponse('no Todo with this todo_id!');
            } elseif ($this->getDeviceSession()->getUserId() != $todo->getCreator()->getId()) {
                $this->setErrorResponse('no Todo for this user with current todo_id!');
            }
        } else {
            $this->setErrorResponse('todo_id is mandatory field for this request!');
        }
        $this->_helper->json($todo);
    }


    /**
     * delete user todos by todo_id
     * todo_id mandatory field
     */
    public function deleteUserTodoAction()
    {
        /** @var Object_Todo $todo */
        $data = $this->getRequestData();
        if (isset($data['todo_id'])) {
            $todo = Object_Todo::getById($data['todo_id']);
            if (!$todo) {
                $this->setErrorResponse('no Todo with this todo_id!');
            } elseif ($this->getDeviceSession()->getUserId() == $todo->getCreator()->getId()) {
                $todo->setPublished(false);
                if (!$todo->save()) {
                    $this->setErrorResponse('cannot delete Todo object!');
                }
            } else {
                $this->setErrorResponse('no Todo for this user with current todo_id!');
            }
        } else {
            $this->setErrorResponse('todo_id is mandatory field for this request!');
        }
        $this->_helper->json(array('deleted' => true));
    }


    /**
     * this action creates user todos
     * todo_type is mandatory field
     * text is optional field
     */
    public function createUserTodoAction()
    {
        $data = $this->getRequestData();
        if (isset($data['todo_type'])) {
            $user = Object_User::getById($this->getDeviceSession()->getUserId());

            $folder = Object_Folder::getByPath('/todo/' . $user->getKey() . "-todo");
            if (!$folder) {
                $folder = new Object_Folder();
                $folder->setKey($user->getKey() . "-todo");
                $folder->setParentId(30);
                $folder->save();
            }
            $todo = new Object_Todo();
            $todo->setCreator($user);
            $todo->setTodo_type($data['todo_type']);
            $todo->setText(isset($data['text']) ? $data['text'] : "");
            $todo->setKey(Pimcore_File::getValidFilename($user->getKey() . "-" . time()));
            $todo->setPublished(true);
            $todo->setParentId($folder->getId());
            if (!$todo->save()) {
                $this->setErrorResponse('cannot save Todo object!');
            }
        } else {
            $this->setErrorResponse('todo_type is mandatory field for this request!');
        }

        $this->_helper->json($todo);
    }


    /**
     * this action updates user todos by todo_id
     * todo_type is mandatory field
     * text is optional field
     */
    public function updateUserTodoAction()
    {
        /** @var Object_Todo $todo */
        $data = $this->getRequestData();
        if (isset($data['todo_id'])) {
            $todo = Object_Todo::getById($data['todo_id']);
            if (!$todo) {
                $this->setErrorResponse('no Todo with this todo_id!');
            } elseif ($this->getDeviceSession()->getUserId() != $todo->getCreator()->getId()) {
                $this->setErrorResponse('you have no rights to change this Todo!');
            } else {
                if (isset($data['todo_type'])) {
                    $todo->setTodo_type($data['todo_type']);
                }
                if (isset($data['text'])) {
                    $todo->setText($data['text']);
                }
                if (!$todo->save()) {
                    $this->setErrorResponse('cannot update Todo object');
                }
            }
        } else {
            $this->setErrorResponse('todo_id is mandatory field for this request!');
        }

        $this->_helper->json($todo);
    }


    /**
     * this method gets todos type list
     */
    public function getTodoTypeListAction(){
        /** @var Object_Todo $todo */
        $this->getDeviceSession()->getUserId();
        $todo = new Object_Todo();
        $this->_helper->json($todo->getClass()->getFieldDefinition('Todo_type'));
    }


    /**
     * get list of user operations
     */
    public function getUserOperationsAction()
    {
        $operation = new Workapp_Operation();
        $this->_helper->json($operation->getOperationList(array('user_id' => $this->getDeviceSession()->getUserId())));
    }


    /**
     * returns user operation by operation_id
     * operation_id mandatory field
     */
    public function getUserOperationByIdAction()
    {
        /** @var Object_Operation $operation */
        $data = $this->getRequestData();
        if (isset($data['operation_id'])) {
            $operation = Object_Operation::getById($data['operation_id']);
            if (!$operation) {
                $this->setErrorResponse('no Operation with this operation_id!');
            } elseif ($this->getDeviceSession()->getUserId() != $operation->getCreator()->getId()) {
                $this->setErrorResponse('no Operation for this user with current operation_id!');
            }
        } else {
            $this->setErrorResponse('operation_id is mandatory field for this request!');
        }
        $this->_helper->json($operation);
    }


    /**
     * delete user operation by operation_id
     * operation_id mandatory field
     */
    public function deleteUserOperationAction()
    {
        /** @var Object_Operation $operation */
        $data = $this->getRequestData();
        if (isset($data['operation_id'])) {
            $operation = Object_Operation::getById($data['operation_id']);
            if (!$operation) {
                $this->setErrorResponse('no Operation with this operation_id!');
            } elseif ($this->getDeviceSession()->getUserId() == $operation->getCreator()->getId()) {
                $operation->setPublished(false);
                if (!$operation->save()) {
                    $this->setErrorResponse('cannot delete Operation object!');
                }
            } else {
                $this->setErrorResponse('no Operation for this user with current operation_id!');
            }
        } else {
            $this->setErrorResponse('operation_id is mandatory field for this request!');
        }
        $this->_helper->json(array('deleted' => true));
    }


    /**
     * this action creates user operation
     * title and activity_id is mandatory field
     * explanation, photo, video, latitude, longtitude is optional field
     */
    public function createUserOperationAction()
    {
        /** @var Object_Operation $operation */
        $data = $this->getRequestData();
        if (isset($data['title']) && isset($data['activity_id'])) {
            $user = Object_User::getById($this->getDeviceSession()->getUserId());

            $folder = Object_Folder::getByPath('/operations/' . $user->getKey() . "-operations");
            if (!$folder) {
                $folder = new Object_Folder();
                $folder->setKey($user->getKey() . "-operations");
                $folder->setParentId(4);
                $folder->save();
            }

            $geo = new Object_Data_Geopoint($data['longtitude'], $data['latitude']);

            $operation = new Object_Operation();
            $operation->setCreator($user);
            $operation->setTitle($data['title']);
            $operation->setExplanation(isset($data['explanation']) ? $data['explanation'] : "");
            $operation->setLocation($geo);
            $operation->setPhoto(isset($data['photo']) ? Asset_Image::getById($data['photo']) : "");
            $operation->setVideo(isset($data['video']) ? Asset_Video::getById($data['video']) : "");
            $operation->setActivity(Object_Activity::getById($data['activity_id']));
            $operation->setKey(Pimcore_File::getValidFilename($user->getKey() . "-" . $data['title'] . "-" . time()));
            $operation->setPublished(true);
            $operation->setParentId($folder->getId());
            if (!$operation->save()) {
                $this->setErrorResponse('cannot save Operation object');
            }
        } else {
            $this->setErrorResponse('title and activity_id is mandatory field for this request!');
        }

        $this->_helper->json($operation);
    }


    /**
     * this action updates user operation by operation_id
     * title and activity_id is mandatory field
     * explanation, photo, video, latitude, longtitude is optional field
     */
    public function updateUserOperationAction()
    {
        /** @var Object_Operation $operation */
        $data = $this->getRequestData();
        if (isset($data['operation_id'])) {
            $operation = Object_Operation::getById($data['operation_id']);
            if (!$operation) {
                $this->setErrorResponse('no Operation with this operation_id!');
            } elseif ($this->getDeviceSession()->getUserId() != $operation->getCreator()->getId()) {
                $this->setErrorResponse('you have no rights to change this Operation!');
            } else {
                if (isset($data['title'])) {
                    $operation->setTitle($data['title']);
                }
                if (isset($data['explanation'])) {
                    $operation->setExplanation($data['explanation']);
                }
                if (isset($data['photo'])) {
                    $operation->setPhoto(Asset_Image::getById($data['photo']));
                }
                if (isset($data['video'])) {
                    $operation->setVideo(Asset_Video::getById($data['video']));
                }
                if (isset($data['latitude']) && isset($data['longtitude'])) {
                    $geo = new Object_Data_Geopoint($data['longtitude'], $data['latitude']);
                    $operation->setLocation($geo);
                }
                if (isset($data['activity_id'])) {
                    $operation->setActivity(Object_Activity::getById($data['activity_id']));
                }
                if (!$operation->save()) {
                    $this->setErrorResponse('cannot update Operation object');
                }
            }
        } else {
            $this->setErrorResponse('operation_id is mandatory field for this request!');
        }

        $this->_helper->json($operation);
    }


    /**
     * get list of user agendas
     */
    public function getUserAgendasAction()
    {
        $agenda = new Workapp_Agenda();
        $this->_helper->json($agenda->getAgendaList(array('user_id' => $this->getDeviceSession()->getUserId())));
    }


    /**
     * returns user agenda by agenda_id
     * agenda_id mandatory field
     */
    public function getUserAgendaByIdAction()
    {
        /** @var Object_Agenda $agenda */
        $data = $this->getRequestData();
        if (isset($data['agenda_id'])) {
            $agenda = Object_Agenda::getById($data['agenda_id']);
            if (!$agenda) {
                $this->setErrorResponse('no Agenda with this agenda_id!');
            } elseif ($this->getDeviceSession()->getUserId() != $agenda->getCreator()->getId()) {
                $this->setErrorResponse('no Agenda for this user with current agenda_id!');
            }
        } else {
            $this->setErrorResponse('agenda_id is mandatory field for this request!');
        }
        $this->_helper->json($agenda);
    }


    /**
     * delete user agenda by agenda_id
     * agenda_id mandatory field
     */
    public function deleteUserAgendaAction()
    {
        /** @var Object_Agenda $agenda */
        $data = $this->getRequestData();
        if (isset($data['agenda_id'])) {
            $agenda = Object_Agenda::getById($data['agenda_id']);
            if (!$agenda) {
                $this->setErrorResponse('no Agenda with this agenda_id!');
            } elseif ($this->getDeviceSession()->getUserId() == $agenda->getCreator()->getId()) {
                $agenda->setPublished(false);
                if (!$agenda->save()) {
                    $this->setErrorResponse('cannot delete Agenda object!');
                }
            } else {
                $this->setErrorResponse('no Agenda for this user with current agenda_id!');
            }
        } else {
            $this->setErrorResponse('agenda_id is mandatory field for this request!');
        }
        $this->_helper->json(array('deleted' => true));
    }


    /**
     * this action creates user agenda
     * topic and title is mandatory field
     * notes, start_time, end_time, with_whom, people, location, alarm, repeat_days is optional field
     */
    public function createUserAgendaAction()
    {
        $data = $this->getRequestData();
        if (isset($data['topic']) && isset($data['title'])) {
            $user = Object_User::getById($this->getDeviceSession()->getUserId());

            $folder = Object_Folder::getByPath('/agenda/' . $user->getKey() . "-agenda");
            if (!$folder) {
                $folder = new Object_Folder();
                $folder->setKey($user->getKey() . "-agenda");
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
            $agenda->setWith_people(isset($data['people']) ? Object_People::getById($data['people']) : "");
            $agenda->setLocation(isset($data['location']) ? $data['location'] : "");
            $agenda->setAlarm(isset($data['alarm']) ? $data['alarm'] : "");
            $agenda->setRepeat_days(isset($data['repeat_days']) ? $data['repeat_days'] : array());
            $agenda->setKey(Pimcore_File::getValidFilename($user->getKey() . "-" . $data['title'] . "-" . time()));
            $agenda->setPublished(true);
            $agenda->setParentId($folder->getId());
            if (!$agenda->save()) {
                $this->setErrorResponse('cannot save Agenda object');
            }
        } else {
            $this->setErrorResponse('topic and title is mandatory field for this request!');
        }

        $this->_helper->json($agenda);
    }


    /**
     * this action updates user agenda by agenda_id
     * topic and title is mandatory field
     * notes, start_time, end_time, with_whom, people, location, alarm, repeat_days is optional field
     */
    public function updateUserAgendaAction()
    {
        /** @var Object_Agenda $agenda */
        $data = $this->getRequestData();
        if (isset($data['agenda_id'])) {
            $agenda = Object_Agenda::getById($data['agenda_id']);
            if (!$agenda) {
                $this->setErrorResponse('no Agenda with this agenda_id!');
            } elseif ($this->getDeviceSession()->getUserId() != $agenda->getCreator()->getId()) {
                $this->setErrorResponse('you have no rights to change this Agenda!');
            } else {
                if (isset($data['topic'])) {
                    $agenda->setTopic($data['topic']);
                }
                if (isset($data['title'])) {
                    $agenda->setTitle($data['title']);
                }
                if (isset($data['notes'])) {
                    $agenda->setNotes($data['notes']);
                }
                if (isset($data['start_time'])) {
                    $agenda->setStart_time($data['start_time']);
                }
                if (isset($data['end_time'])) {
                    $agenda->setEnd_time($data['end_time']);
                }
                if (isset($data['with_whom'])) {
                    $agenda->setWith_whom($data['with_whom']);
                }
                if (isset($data['with_people'])) {
                    $agenda->setWith_people(Object_People::getById($data['with_people']));
                }
                if (isset($data['location'])) {
                    $agenda->setLocation($data['location']);
                }
                if (isset($data['alarm'])) {
                    $agenda->setAlarm($data['alarm']);
                }
                if (isset($data['repeat_days'])) {
                    $agenda->setRepeat_days($data['repeat_days']);
                }
                if (!$agenda->save()) {
                    $this->setErrorResponse('cannot update Agenda object');
                }
            }
        } else {
            $this->setErrorResponse('agenda_id is mandatory field for this request!');
        }

        $this->_helper->json($agenda);
    }


    /**
     * this method gets topic type list
     */
    public function getAgendaTopicTypeListAction(){
        /** @var Object_Agenda $agenda */
        $this->getDeviceSession()->getUserId();
        $agenda = new Object_Agenda();
        $this->_helper->json($agenda->getClass()->getFieldDefinition('Topic'));
    }


    /**
     * this method gets agenda alarm list
     */
    public function getAgendaAlarmListAction(){
        /** @var Object_Agenda $agenda */
        $this->getDeviceSession()->getUserId();
        $agenda = new Object_Agenda();
        $this->_helper->json($agenda->getClass()->getFieldDefinition('Alarm'));
    }


    /**
     * get list of user peoples
     */
    public function getUserPeoplesAction()
    {
        $people = new Workapp_People();
        $this->_helper->json($people->getPeopleList(array('user_id' => $this->getDeviceSession()->getUserId())));
    }


    /**
     * returns user people by people_id
     * people_id mandatory field
     */
    public function getUserPeopleByIdAction()
    {
        /** @var Object_People $people */
        $data = $this->getRequestData();
        if (isset($data['people_id'])) {
            $people = Object_People::getById($data['people_id']);
            if (!$people) {
                $this->setErrorResponse('no People with this people_id!');
            } elseif ($this->getDeviceSession()->getUserId() != $people->getCreator()->getId()) {
                $this->setErrorResponse('no People for this user with current people_id!');
            }
        }

        $this->_helper->json($people);
    }


    /**
     * delete user people by people_id
     * people_id mandatory field
     */
    public function deleteUserPeopleAction()
    {
        /** @var Object_People $people */
        $data = $this->getRequestData();
        if ($data['people_id']) {
            $people = Object_People::getById($data['people_id']);
            if (!$people) {
                $this->setErrorResponse('no People with this people_id!');
            } elseif ($this->getDeviceSession()->getUserId() == $people->getCreator()->getId()) {
                $people->setPublished(false);
                if (!$people->save()) {
                    $this->setErrorResponse('cannot delete People object!');
                }
            } else {
                $this->setErrorResponse('no People for this user with current people_id!');
            }
        } else {
            $this->setErrorResponse('people_id is mandatory field for this request!');
        }
        $this->_helper->json(array('deleted' => true));
    }


    /**
     * this action creates user people
     * name is mandatory field
     * company, phone, email, image is optional field
     */
    public function createUserPeopleAction()
    {
        $data = $this->getRequestData();
        if (isset($data['name'])) {
            $user = Object_User::getById($this->getDeviceSession()->getUserId());
            $folder = Object_Folder::getByPath('/peoples/' . $user->getKey() . "-people");
            if (!$folder) {
                $folder = new Object_Folder();
                $folder->setKey($user->getKey() . "-people");
                $folder->setParentId(52);
                $folder->save();
            }
            $people = new Object_People();
            $people->setCreator($user);
            $people->setName($data['name']);
            $people->setCompany(isset($data['company']) ? $data['company'] : "");
            $people->setPhone(isset($data['phone']) ? $data['phone'] : "");
            $people->setEmail(isset($data['email']) ? $data['email'] : "");
            $people->setImage(isset($data['image']) ? Asset_Image::getById($data['image']) : "");
            $people->setKey(Pimcore_File::getValidFilename($user->getKey() . "-" . $data['name'] . "-" . time()));
            $people->setPublished(true);
            $people->setParentId($folder->getId());
            if (!$people->save()) {
                $this->setErrorResponse('cannot save People object');
            }
        } else {
            $this->setErrorResponse('name is mandatory field for this request!');
        }

        $this->_helper->json($people);
    }


    /**
     * this action updates user people by people_id
     * name is mandatory field
     * company, phone, email, image is optional field
     */
    public function updateUserPeopleAction()
    {
        /** @var Object_People $people */
        $data = $this->getRequestData();
        if (isset($data['people_id'])) {
            $people = Object_People::getById($data['people_id']);
            if (!$people) {
                $this->setErrorResponse('no People with this people_id!');
            } elseif ($this->getDeviceSession()->getUserId() != $people->getCreator()->getId()) {
                $this->setErrorResponse('you have no rights to change this People!');
            } else {
                if (isset($data['name'])) {
                    $people->setName($data['name']);
                }
                if (isset($data['company'])) {
                    $people->setCompany($data['company']);
                }
                if (isset($data['phone'])) {
                    $people->setPhone($data['phone']);
                }
                if (isset($data['email'])) {
                    $people->setEmail($data['email']);
                }
                if (isset($data['image'])) {
                    $people->setImage(Asset_Image::getById($data['image']));
                }
                if (!$people->save()) {
                    $this->setErrorResponse('cannot update People object');
                }
            }
        } else {
            $this->setErrorResponse('people_id is mandatory field for this request!');
        }

        $this->_helper->json($people);
    }


    /**
     * this method allows user to report sick
     * history and already_sick is mandatory fields
     */
    public function reportSickAction(){
        /** @var Object_User $user */
        $data = $this->getRequestData();
        if(isset($data['history']) && isset($data['already_sick'])){
            $user = Object_User::getById($this->getDeviceSession()->getUserId());
            $sickArr = $user->getSick_history()?$user->getSick_history():array(array('Date', 'Sick history', 'Sick status'));
            $sick = array();
            $sick[] = date('Y-m-d');
            $sick[] = $data['history'];
            $sick[] = $data['already_sick'];
            $sickArr[] = $sick;
            $user->setSick_history($sickArr);
            $user->setAlready_sick($data['already_sick']);
            if(!$user->save()){
                $this->setErrorResponse('Cannot update User object');
            }
        } else {
            $this->setErrorResponse('Please, report your sick history. history and already_sick is mandatory fields!');
        }

        $this->_helper->json(array('added' => true));
    }


    /**
     * this method allows user to get sick history, or sick for each day
     * date is optional field for this request
     */
    public function getSickAction(){
        /** @var Object_User $user */
        $data = $this->getRequestData();
        $user = Object_User::getById($this->getDeviceSession()->getUserId());
        $sickArr = $user->getSick_history();
        if(isset($sickArr[0])){
            unset($sickArr[0]);
        }
        if(isset($data['date'])){
            foreach($sickArr as $sick){
                if($sick[0] == $data['date']){
                    $this->_helper->json($sick);
                } else {
                    $this->setErrorResponse('No sick for this day!');
                }
            }
        }
        $this->_helper->json($sickArr);
    }


    /**
     * this method allows user to report mood
     * mood is mandatory field
     */
    public function reportMoodAction(){
        /** @var Object_User $user */
        $data = $this->getRequestData();
        if(isset($data['mood']) && in_array($data['mood'], range(1,3))){
            $user = Object_User::getById($this->getDeviceSession()->getUserId());
            $moodArr = $user->getMoodmeter()?$user->getMoodmeter():array(array('Date', 'Text', 'Mood'));
            $mood = array();
            $mood[] = date('Y-m-d');
            $mood[] = isset($data['text'])?$data['text']:"";
            $mood[] = $data['mood'];
            $moodArr[] = $mood;
            $user->setMoodmeter($moodArr);
            if(!$user->save()){
                $this->setErrorResponse('Cannot update User object');
            }
        } else {
            $this->setErrorResponse('Please, report your mood. mood is mandatory field! Mood should be between 1 and 3');
        }

        $this->_helper->json(array('added' => true));
    }


    /**
     * this method allows user to get mood history, or mood for each day
     * date is optional field for this request
     */
    public function getMoodAction(){
        $data = $this->getRequestData();
        $user = Object_User::getById($this->getDeviceSession()->getUserId());
        $moodArr = $user->getMoodmeter();
        if(isset($moodArr[0])){
            unset($moodArr[0]);
        }
        if(isset($data['date'])){
            foreach($moodArr as $mood){
                if($mood[0] == $data['date']){
                    $this->_helper->json($mood);
                } else {
                    $this->setErrorResponse('No mood for this day!');
                }
            }
        }
        $this->_helper->json($moodArr);
    }


    /**
     * this method allows user to get his profile or update
     * phone, email, workphone, workemail is optional fields
     */
    public function profileAction(){
        /** @var Object_User $user */
        $data = $this->getRequestData();
        $user = Object_User::getById($this->getDeviceSession()->getUserId());
        if(isset($data['phone'])){
            $user->setPhone($data['phone']);
        }
        if(isset($data['email'])){
            $user->setPhone($data['email']);
        }
        if(isset($data['workphone'])){
            $user->setWorkPhone($data['workphone']);
        }
        if(isset($data['workemail'])){
            $user->setWorkEmail($data['workemail']);
        }
        if(!$user->save()){
            $this->setErrorResponse('Cannot update user profile');
        }
        $this->_helper->json($user);
    }


    /**
     * this method changes password for user
     * password and repeat_password is mandatory fields
     */
    public function changePasswordAction(){
        $data = $this->getRequestData();
        $user = Object_User::getById($this->getDeviceSession()->getUserId());
        if(isset($data['password']) && isset($data['repeat_password'])){
            if($data['password'] === $data['repeat_password']){
                $user->setPassword($data['password']);
            } else {
                $this->setErrorResponse('password and repeat_password should match!');
            }
        } else {
            $this->setErrorResponse('password and repeat_password is mandatory fields!');
        }
        $this->_helper->json(array('updated' => true));
    }


    /**
     * this method allows user to set his device_token
     * device_token is mandatory field
     */
    public function setDeviceTokenAction(){
        $data = $this->getRequestData();
        $this->getDeviceSession()->getUserId();
        if($data['device_token']){
            $session = Workapp_Session::getBySessionUid($data['session_uid']);
            if ($session) {
                $session->addDeviceToken($data['device_token']);
            } else {
                $this->setErrorResponse('This device has no running session which is required by service');
            }
        } else {
            $this->setErrorResponse('device_token is mandatory field for this request!');
        }
        $this->_helper->json(array('added' => true));
    }
}