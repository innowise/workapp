<?php

/**
 * Class Workapp_Agenda
 */
class Workapp_Agenda extends Pimcore_Model_Abstract
{

    /**
     * this method gets user agenda list from database
     * @param $options
     * @return array
     */
    public function getAgendaList($options)
    {
        $agendas = new Object_Agenda_List();
        if (isset($options['user_id'])) {
            $agendas->setCondition('Creator__id = ?', array($options['user_id']));
        }

        foreach ($agendas as $agenda) {
            $ags[] = $agenda;
        }

        return $ags;
    }
}