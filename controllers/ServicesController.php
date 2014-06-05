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
        } else {
            $this->_helper->json(array(
                'message' => 'This device has no running session'
            ));
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
        $data = $this->getRequestData();
        if (isset($data['getoperations'])) {
            $getoperations = $data['getoperations'];
        }

        $this->_helper->json($activity->getActivityList(array('user_id' => $this->getDeviceSession()->getUserId(), 'getoperations' => $getoperations)));
    }


    /**
     * returns user activity by activity_id
     * activity_id mandatory field
     */
    public function getUserActivityByIdAction()
    {
        $data = $this->getRequestData();
        if (isset($data['activity_id'])) {
            $activity = Object_Activity::getById($data['activity_id']);
            if (!$activity) {
                $activity = array('message' => 'no Activity with this activity_id!');
            } elseif ($this->getDeviceSession()->getUserId() != $activity->Creator->o_id) {
                $activity = array('message' => 'no Activity for this user with current activity_id!');
            }
        } else {
            $activity = array('message' => 'activity_id is mandatory field for this request!');
        }

        $this->_helper->json($activity);
    }


    /**
     * delete user activity with relative operations
     * activity_id mandatory field
     */
    public function deleteUserActivityAction()
    {
        $response['deleted'] = false;
        $data = $this->getRequestData();
        if (isset($data['activity_id'])) {
            $activity = Object_Activity::getById($data['activity_id']);
            if (!$activity) {
                $response = array('message' => 'no Activity with this activity_id!');
            } elseif ($this->getDeviceSession()->getUserId() == $activity->Creator->o_id) {
                $activity->o_published = false;
                if ($activity->save()) {
                    $response['deleted'] = true;
                    $operations = new Workapp_Activity();
                    $op = $operations->getActivityRequiredByOperations($activity);
                    foreach ($op as $operation) {
                        $operation = Object_Operation::getById($operation->o_id);
                        $operation->o_published = false;
                        if (!$operation->save()) {
                            $response['deleted'] = false;
                        }
                    }
                }
            } else {
                $response = array('message' => 'no Activity for this user with current activity_id!');
            }
        } else {
            $response = array('message' => 'activity_id is mandatory field for this request!');
        }
        $this->_helper->json($response);
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
            $activity->setPhoto(isset($data['photo']) ? Asset_Image::getById($data['photo']) : "");
            $activity->setKey(Pimcore_File::getValidFilename($user->o_key . "-" . $data['title'] . "-" . time()));
            $activity->setPublished(true);
            $activity->setParentId($folder->o_id);
            if (!$activity->save()) {
                $activity = array('message' => 'cannot save Activity object');
            }
        } else {
            $activity = array('message' => 'title is mandatory field for this request!');
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
        $data = $this->getRequestData();
        if (isset($data['activity_id'])) {
            $activity = Object_Activity::getById($data['activity_id']);
            if (!$activity) {
                $activity = array('message' => 'no Activity with this activity_id!');
            } elseif ($this->getDeviceSession()->getUserId() != $activity->Creator->o_id) {
                $activity = array('message' => 'you have no rights to change this Activity!');
            } else {
                if (isset($data['title'])) {
                    $activity->Title = $data['title'];
                }
                if (isset($data['photo'])) {
                    $activity->Photo = Asset_Image::getById($data['photo']);
                }
                if (!$activity->save()) {
                    $activity = array('message' => 'cannot update Activity object');
                }
            }
        } else {
            $activity = array('message' => 'activity_id is mandatory field for this request!');
        }

        $this->_helper->json($activity);
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
        $data = $this->getRequestData();
        if (isset($data['todo_id'])) {
            $todo = Object_Todo::getById($data['todo_id']);
            if (!$todo) {
                $todo = array('message' => 'no Todo with this todo_id!');
            } elseif ($this->getDeviceSession()->getUserId() != $todo->Creator->o_id) {
                $todo = array('message' => 'no Todo for this user with current todo_id!');
            }
        } else {
            $todo = array('message' => 'todo_id is mandatory field for this request!');
        }
        $this->_helper->json($todo);
    }


    /**
     * delete user todos by todo_id
     * todo_id mandatory field
     */
    public function deleteUserTodoAction()
    {
        $response['deleted'] = false;
        $data = $this->getRequestData();
        if (isset($data['todo_id'])) {
            $todo = Object_Todo::getById($data['todo_id']);
            if (!$todo) {
                $response = array('message' => 'no Todo with this todo_id!');
            } elseif ($this->getDeviceSession()->getUserId() == $todo->Creator->o_id) {
                $todo->o_published = false;
                if ($todo->save()) {
                    $response['deleted'] = true;
                }
            } else {
                $response = array('message' => 'no Todo for this user with current todo_id!');
            }
        } else {
            $response = array('message' => 'todo_id is mandatory field for this request!');
        }
        $this->_helper->json($response);
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
                $todo = array('message' => 'cannot save Todo object!');
            }
        } else {
            $todo = array('message' => 'todo_type is mandatory field for this request!');
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
        $data = $this->getRequestData();
        if (isset($data['todo_id'])) {
            $todo = Object_Todo::getById($data['todo_id']);
            if (!$todo) {
                $todo = array('message' => 'no Todo with this todo_id!');
            } elseif ($this->getDeviceSession()->getUserId() != $todo->Creator->o_id) {
                $todo = array('message' => 'you have no rights to change this Todo!');
            } else {
                if (isset($data['todo_type'])) {
                    $todo->Todo_type = $data['todo_type'];
                }
                if (isset($data['text'])) {
                    $todo->Text = $data['text'];
                }
                if (!$todo->save()) {
                    $todo = array('message' => 'cannot update Todo object');
                }
            }
        } else {
            $todo = array('message' => 'activity_id is mandatory field for this request!');
        }

        $this->_helper->json($todo);
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
        $data = $this->getRequestData();
        if (isset($data['operation_id'])) {
            $operation = Object_Operation::getById($data['operation_id']);
            if (!$operation) {
                $operation = array('message' => 'no Operation with this operation_id!');
            } elseif ($this->getDeviceSession()->getUserId() != $operation->Creator->o_id) {
                $operation = array('message' => 'no Operation for this user with current operation_id!');
            }
        } else {
            $operation = array('message' => 'operation_id is mandatory field for this request!');
        }
        $this->_helper->json($operation);
    }


    /**
     * delete user operation by operation_id
     * operation_id mandatory field
     */
    public function deleteUserOperationAction()
    {
        $response['deleted'] = false;
        $data = $this->getRequestData();
        if (isset($data['operation_id'])) {
            $operation = Object_Operation::getById($data['operation_id']);
            if (!$operation) {
                $response = array('message' => 'no Operation with this operation_id!');
            } elseif ($this->getDeviceSession()->getUserId() == $operation->Creator->o_id) {
                $operation->o_published = false;
                if ($operation->save()) {
                    $response['deleted'] = true;
                }
            } else {
                $response = array('message' => 'no Operation for this user with current operation_id!');
            }
        } else {
            $response = array('message' => 'operation_id is mandatory field for this request!');
        }
        $this->_helper->json($response);
    }


    /**
     * this action creates user operation
     * title and activity_id is mandatory field
     * explanation, photo, video, latitude, longtitude is optional field
     */
    public function createUserOperationAction()
    {
        $data = $this->getRequestData();
        if (isset($data['title']) && isset($data['activity_id'])) {
            $user = Object_User::getById($this->getDeviceSession()->getUserId());

            $folder = Object_Folder::getByPath('/operations/' . $user->o_key . "-operations");
            if (!$folder) {
                $folder = new Object_Folder();
                $folder->setKey($user->o_key . "-operations");
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
            $operation->setKey(Pimcore_File::getValidFilename($user->o_key . "-" . $data['title'] . "-" . time()));
            $operation->setPublished(true);
            $operation->setParentId($folder->o_id);
            if (!$operation->save()) {
                $operation = array('message' => 'cannot save Operation object');
            }
        } else {
            $operation = array('message' => 'title and activity_id is mandatory field for this request!');
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
        $data = $this->getRequestData();
        if (isset($data['operation_id'])) {
            $operation = Object_Operation::getById($data['operation_id']);
            if (!$operation) {
                $operation = array('message' => 'no Operation with this operation_id!');
            } elseif ($this->getDeviceSession()->getUserId() != $operation->Creator->o_id) {
                $operation = array('message' => 'you have no rights to change this Todo!');
            } else {
                if (isset($data['title'])) {
                    $operation->Title = $data['title'];
                }
                if (isset($data['explanation'])) {
                    $operation->Explanation = $data['explanation'];
                }
                if (isset($data['photo'])) {
                    $operation->Photo = Asset_Image::getById($data['photo']);
                }
                if (isset($data['video'])) {
                    $operation->Video = Asset_Video::getById($data['video']);
                }
                if (isset($data['latitude']) && isset($data['longtitude'])) {
                    $geo = new Object_Data_Geopoint($data['longtitude'], $data['latitude']);
                    $operation->Location = $geo;
                }
                if (isset($data['activity_id'])) {
                    $operation->Activity = Object_Activity::getById($data['activity_id']);
                }
                if (!$operation->save()) {
                    $operation = array('message' => 'cannot update Operation object');
                }
            }
        } else {
            $operation = array('message' => 'operation_id is mandatory field for this request!');
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
        $data = $this->getRequestData();
        if (isset($data['agenda_id'])) {

            $agenda = Object_Agenda::getById($data['agenda_id']);
            if (!$agenda) {
                $agenda = array('message' => 'no Agenda with this agenda_id!');
            } elseif ($this->getDeviceSession()->getUserId() != $agenda->Creator->o_id) {
                $agenda = array('message' => 'no Agenda for this user with current agenda_id!');
            }
        } else {
            $agenda = array('message' => 'agenda_id is mandatory field for this request!');
        }
        $this->_helper->json($agenda);
    }


    /**
     * delete user agenda by agenda_id
     * agenda_id mandatory field
     */
    public function deleteUserAgendaAction()
    {
        $response['deleted'] = false;
        $data = $this->getRequestData();
        if (isset($data['agenda_id'])) {
            $agenda = Object_Agenda::getById($data['agenda_id']);
            if (!$agenda) {
                $response = array('message' => 'no Agenda with this agenda_id!');
            } elseif ($this->getDeviceSession()->getUserId() == $agenda->Creator->o_id) {
                $agenda->o_published = false;
                if ($agenda->save()) {
                    $response['deleted'] = true;
                }
            } else {
                $response = array('message' => 'no Agenda for this user with current agenda_id!');
            }
        } else {
            $response = array('message' => 'agenda_id is mandatory field for this request!');
        }
        $this->_helper->json($response);
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
            $agenda->setWith_people(isset($data['people']) ? Object_People::getById($data['people']) : "");
            $agenda->setLocation(isset($data['location']) ? $data['location'] : "");
            $agenda->setAlarm(isset($data['alarm']) ? $data['alarm'] : "");
            $agenda->setRepeat_days(isset($data['repeat_days']) ? $data['repeat_days'] : array());
            $agenda->setKey(Pimcore_File::getValidFilename($user->o_key . "-" . $data['title'] . "-" . time()));
            $agenda->setPublished(true);
            $agenda->setParentId($folder->o_id);
            if (!$agenda->save()) {
                $agenda = array('message' => 'cannot save Agenda object');
            }
        } else {
            $agenda = array('message' => 'topic and title is mandatory field for this request!');
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
        $data = $this->getRequestData();
        if (isset($data['agenda_id'])) {
            $agenda = Object_Agenda::getById($data['agenda_id']);
            if (!$agenda) {
                $agenda = array('message' => 'no Agenda with this agenda_id!');
            } elseif ($this->getDeviceSession()->getUserId() != $agenda->Creator->o_id) {
                $agenda = array('message' => 'you have no rights to change this Agenda!');
            } else {
                if (isset($data['topic'])) {
                    $agenda->Topic = $data['topic'];
                }
                if (isset($data['title'])) {
                    $agenda->Title = $data['title'];
                }
                if (isset($data['notes'])) {
                    $agenda->Notes = $data['notes'];
                }
                if (isset($data['start_time'])) {
                    $agenda->Start_time = $data['start_time'];
                }
                if (isset($data['end_time'])) {
                    $agenda->End_time = $data['end_time'];
                }
                if (isset($data['with_whom'])) {
                    $agenda->With_whom = $data['with_whom'];
                }
                if (isset($data['with_people'])) {
                    $agenda->With_people = Object_People::getById($data['with_people']);
                }
                if (isset($data['location'])) {
                    $agenda->Location = $data['location'];
                }
                if (isset($data['alarm'])) {
                    $agenda->Alarm = $data['alarm'];
                }
                if (isset($data['repeat_days'])) {
                    $agenda->Repeat_days = $data['repeat_days'];
                }
                if (!$agenda->save()) {
                    $agenda = array('message' => 'cannot update Agenda object');
                }
            }
        } else {
            $agenda = array('message' => 'agenda_id is mandatory field for this request!');
        }

        $this->_helper->json($agenda);
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
        $data = $this->getRequestData();
        if (isset($data['people_id'])) {
            $people = Object_People::getById($data['people_id']);
            if (!$people) {
                $people = array('message' => 'no People with this people_id!');
            } elseif ($this->getDeviceSession()->getUserId() != $people->Creator->o_id) {
                $people = array('message' => 'no People for this user with current people_id!');
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
        $response['deleted'] = false;
        $data = $this->getRequestData();
        if ($data['people_id']) {
            $people = Object_People::getById($data['people_id']);
            if (!$people) {
                $response = array('message' => 'no People with this people_id!');
            } elseif ($this->getDeviceSession()->getUserId() == $people->Creator->o_id) {
                $people->o_published = false;
                if ($people->save()) {
                    $response['deleted'] = true;
                }
            } else {
                $response = array('message' => 'no People for this user with current people_id!');
            }
        } else {
            $response = array('message' => 'people_id is mandatory field for this request!');
        }
        $this->_helper->json($response);
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
            $people->setImage(isset($data['image']) ? Asset_Image::getById($data['image']) : "");
            $people->setKey(Pimcore_File::getValidFilename($user->o_key . "-" . $data['name'] . "-" . time()));
            $people->setPublished(true);
            $people->setParentId($folder->o_id);
            if (!$people->save()) {
                $people = array('message' => 'cannot save People object');
            }
        } else {
            $people = array('message' => 'name is mandatory field for this request!');
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
        $data = $this->getRequestData();
        if (isset($data['people_id'])) {
            $people = Object_People::getById($data['people_id']);
            if (!$people) {
                $people = array('message' => 'no People with this people_id!');
            } elseif ($this->getDeviceSession()->getUserId() != $people->Creator->o_id) {
                $people = array('message' => 'you have no rights to change this People!');
            } else {
                if (isset($data['name'])) {
                    $people->Name = $data['name'];
                }
                if (isset($data['company'])) {
                    $people->Company = $data['company'];
                }
                if (isset($data['phone'])) {
                    $people->Phone = $data['phone'];
                }
                if (isset($data['email'])) {
                    $people->Email = $data['email'];
                }
                if (isset($data['image'])) {
                    $people->Image = Asset_Image::getById($data['image']);
                }
                if (!$people->save()) {
                    $people = array('message' => 'cannot update People object');
                }
            }
        } else {
            $people = array('message' => 'people_id is mandatory field for this request!');
        }

        $this->_helper->json($people);
    }
}