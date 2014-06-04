<?php

/**
 * Class Workapp_Session_Resource
 */
class Workapp_Session_Resource extends Pimcore_Model_Resource_Abstract
{
    /**
     * @var string
     */
    protected $tableName = 'workapp_sessions';

    /**
     * @var Workapp_Session
     */
    protected $model;

    /**
     * Serialize and save
     */
    public function save()
    {
        $buffer = array();

        $validColumns = $this->getValidTableColumns($this->tableName);

        foreach ($validColumns as $column) {
            if (method_exists($this->model, 'get' . ucfirst(preg_replace_callback('/_[a-z]?/', function ($matches) {
                    return strtoupper(ltrim($matches[0], "_"));
                }, $column)))
            ) {
                $buffer[$column] = $this->model->{'get' . ucfirst(preg_replace_callback('/_[a-z]?/', function ($matches) {
                        return strtoupper(ltrim($matches[0], "_"));
                    }, $column))}();

                // Complex types handling (serialization)
                if ($buffer[$column] instanceof Zend_Date) {
                    $buffer[$column] = $buffer[$column]->get(Zend_Date::ISO_8601);
                }
            }
        }

        if ($this->model->getId() !== null) {
            $this->db->update($this->tableName, $buffer, $this->db->quoteInto("id = ?", $this->model->getId()));
            return;
        }

        $this->db->insert($this->tableName, $buffer);
        $this->model->setId($this->db->lastInsertId());
    }


    /**
     * @param $deviceUid
     * @throws Exception
     */
    public function getByDeviceUid($deviceUid)
    {
        if ($deviceUid != null)
            $this->model->setDeviceUid($deviceUid); // We want to apply setter and getter just in case. They can have some filters after all

        $data = $this->db->fetchRow('SELECT * FROM ' . $this->tableName . ' WHERE device_uid LIKE ?', $this->model->getDeviceUid());

        if (!$data["id"]) {
            throw new Zend_Exception("Session with the device_id " . $this->model->getDeviceUid() . " doesn't exists");
            var_dump($data);
            exit;
        }

        $this->assignVariablesToModel($data);
    }

} 