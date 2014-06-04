<?php

/**
 * Class Workapp_Todo
 */
class Workapp_Todo extends Pimcore_Model_Abstract
{

    /**
     * this method gets user todos list from database
     * @param $options
     * @return array
     */
    public function getTodoList($options)
    {
        $todos = new Object_Todo_List();
        if (isset($options['user_id'])) {
            $todos->setCondition('Creator__id = ?', array($options['user_id']));
        }

        foreach ($todos as $todo) {
            $tods[] = $todo;
        }

        return $tods;
    }
}