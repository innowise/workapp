<?php

/**
 * Class Workapp_People
 */
class Workapp_People extends Pimcore_Model_Abstract
{

    /**
     * this method gets user peoples list from database
     * @param $options
     * @return array
     */
    public function getPeopleList($options)
    {
        $peoples = new Object_People_List();
        if (isset($options['user_id'])) {
            $peoples->setCondition('Creator__id = ?', array($options['user_id']));
        }

        foreach ($peoples as $people) {
            $ps[] = $people;
        }

        return $ps;
    }
}