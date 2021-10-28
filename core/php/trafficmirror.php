<?php

require_once dirname(__FILE__) . "/../../../../core/php/core.inc.php";
require_once dirname(__FILE__) . "/../class/trafficmirror.class.php";

if (!jeedom::apiAccess(init('apikey'), 'trafficmirror')) {
    echo __('Vous n\'êtes pas autorise a effectuer cette action', __FILE__);
    die();
}

$results  = json_decode(file_get_contents("php://input"), true);
$action   = $results['action'];
$value    = 0;

switch ($action) {
    case 'test':
        log::add('trafficmirror','debug','mirror test');
        $success = true;
        $value   = 1;
        break;

    case 'heartbeat':
        log::add('trafficmirror','debug','mirror heartbeat received for ' . $results['id']);
        $id     = $results['id'];

        $eqLogic = eqLogic::byId($id);
        if ($eqLogic->getIsEnable()) {
            $isListening = $eqLogic->getCmd(null, 'isListening');
            $eqLogic->checkAndUpdateCmd($isListening, 1);
        }
        $success = true;
        $value   = 1;
        break;

    case 'get_mirrors':
        log::add('trafficmirror','info','Receive get_mirrors');
        $mirrors = eqLogic::byType('trafficmirror', true);
        $values  = array();

        foreach($mirrors as $m) {
            if ($m->getIsEnable() == true) {
                log::add('trafficmirror', 'debug', 'Add ' . $m->getHumanName());
                $id = $m->getId();
                $values[$id] = [
    			    'id' 		 => $id,
    			    'localAddr'  => '0.0.0.0',
    			    'localPort'  => $m->getConfiguration('localPort'),
    			    'mirrorHost' => $m->getConfiguration('mirrorHost'),
    			    'mirrorPort' => $m->getConfiguration('mirrorPort'),
    			    'targetHost' => $m->getConfiguration('targetHost'),
    			    'targetPort' => $m->getConfiguration('targetPort'),
    			    'protocol'   => $m->getConfiguration('protocol'),
    			    'mirrorRx'   => $m->getConfiguration('mirrorRx', 0),
    			    'targetRx'   => $m->getConfiguration('targetRx', 0)
                ];
            }
        }
        $success = true;
        $value   = $values;
        break;
}

$response = array('success' => $success, 'value' => $value);

echo json_encode($response);
?>
