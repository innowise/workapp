<?php

/**
 * Class Workapp_Operation
 */
class Workapp_Operation extends Pimcore_Model_Abstract
{

    /**
     * this method gets user operations list from database
     * @param $options
     * @return array
     */
    public function getOperationList($options)
    {
        $operations = new Object_Operation_List();
        if (isset($options['user_id'])) {
            $operations->setCondition('Creator__id = ?', array($options['user_id']));
        }

        foreach ($operations as $operation) {
            $ops[] = $operation;
        }

        return $ops;
    }
}