<?php

/**
 * Class Workapp_Activity
 */
class Workapp_Activity extends Pimcore_Model_Abstract
{

    /**
     * this method gets user activities list from database
     * @param $options
     * @return array
     */
    public function getActivityList($options)
    {
        $activities = new Object_Activity_List();
        $acts = array();
        if (isset($options['user_id'])) {
            $activities->setCondition('Creator__id = ?', array($options['user_id']));
        }

        foreach ($activities as $activity) {
            if ($options['getoperations']) {
                $activity->operations = $this->getActivityRequiredByOperations($activity);
            }
            $acts[] = $activity;
        }

        return $acts;
    }


    /**
     * this method gets activity required by operations
     * @param $activity
     * @return array
     */
    public function getActivityRequiredByOperations($activity)
    {
        $dependencies = $activity->getDependencies();
        $operations = array();
        foreach ($dependencies->requiredBy as $dependency) {
            if ($dependency['type'] == 'object') {
                $operation = Object_Operation::getById($dependency['id']);
                if ($operation) {
                    $operations[] = $operation;
                }
            }
        }
        return $operations;
    }
}