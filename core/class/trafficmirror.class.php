<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* * ***************************Includes********************************* */
require_once __DIR__  . '/../../../../core/php/core.inc.php';

class trafficmirror extends eqLogic {
    /*     * *************************Attributs****************************** */

  /*
   * Permet de définir les possibilités de personnalisation du widget (en cas d'utilisation de la fonction 'toHtml' par exemple)
   * Tableau multidimensionnel - exemple: array('custom' => true, 'custom::layout' => false)
   */
	public static $_widgetPossibility = array('custom' => true);

    /*     * ***********************Methode static*************************** */

    /************* Static methods ************/

    public static function deamon_info() {
       $return = array();
       $return['log']        = 'trafficmirror_daemon';
       $return['launchable'] = 'ok';
       $return['state']      = 'nok';
       $pid_file = jeedom::getTmpFolder('trafficmirror') . '/daemon.pid';
       if (file_exists($pid_file)) {
           if (posix_getsid(trim(file_get_contents($pid_file)))) {
               $return['state'] = 'ok';
           } else {
               shell_exec(system::getCmdSudo() . 'rm -rf ' . $pid_file . ' 2>&1 > /dev/null');
           }
       }
       $return['launchable'] = 'ok';

       if (trafficmirror::dependancy_info()['state'] == 'nok') {
           $cache = cache::byKey('dependancy' . 'trafficmirror');
           $cache->remove();
           $return['launchable'] = 'nok';
           $return['launchable_message'] = __('Veuillez (ré-)installer les dépendances', __FILE__);
           return $return;
       }
       return $return;

    }

    public static function deamon_start() {

        log::add('trafficmirror', 'debug', 'Start the daemon');

        self::deamon_stop();
        $deamon_info = self::deamon_info();
        if ($deamon_info['launchable'] != 'ok') {
            throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
        }
        $servicePort = config::byKey('servicePort', 'trafficmirror', 15003);
        $deamon_path = dirname(__FILE__) . '/../../resources';
        //$callback = network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') . '/plugins/trafficmirror/core/php/trafficmirror.php';

        $cmd  = 'sudo nice -n 19 nodejs "'. $deamon_path . '/trafficmirrord.js" ';
        $cmd .= ' --port ' . $servicePort;
        $cmd .= ' --loglevel ' . log::convertLogLevel(log::getLogLevel('trafficmirror'));
        //$cmd .= ' --apikey ' . jeedom::getApiKey('trafficmirror');
        $cmd .= ' --pidfile ' . jeedom::getTmpFolder('trafficmirror') . '/daemon.pid';
        //$cmd .= ' --callback ' . $callback;

        log::add('trafficmirror', 'info', 'Lancement démon trafficmirror : ' . $cmd);
        exec($cmd . ' >> ' . log::getPathToLog('trafficmirror') . ' 2>&1 &');
        $i = 0;
        while ($i < 5) {
            $deamon_info = self::deamon_info();
            if ($deamon_info['state'] == 'ok') {
                break;
            }
            sleep(1);
            $i++;
        }

        if ($i >= 5) {
            log::add('trafficmirror', 'error', 'Impossible de lancer le démon trafficmirror, relancer le démon en debug et vérifiez la log', 'unableStartDeamon');
            return false;
        }

        message::removeAll('trafficmirror', 'unableStartDeamon');

		// 1. get all eqLogic mirror objects, and add the mirrors in the daemon
		foreach (self::byType(__CLASS__) as $mirror) {
            if (is_object($mirror) && $mirror->getIsEnable() == 1) {
				$mirror->daemonAddMirror();
			}
		}

        log::add('trafficmirror', 'info', 'Démon trafficmirror lancé');
	}

  	public static function deamon_stop() {
        log::add('trafficmirror', 'debug', 'Stop the daemon');

        $deamon_info = self::deamon_info();
        $pid_file = jeedom::getTmpFolder('trafficmirror') . '/daemon.pid';
        if (file_exists($pid_file)) {
            $pid = intval(trim(file_get_contents($pid_file)));
            system::kill($pid);
        }
        $i = 0;
        while ($i < 5) {
            $deamon_info = self::deamon_info();
            if ($deamon_info['state'] == 'nok') {
                break;
            }
            sleep(1);
            $i++;
        }
        if ($i >= 5) {
            log::add('trafficmirror', 'error', 'Impossible d\'arrêter le démon trafficmirror, tuons-le');
            system::kill('trafficmirrord.js');
        }
		log::add('trafficmirror', 'debug', 'daemon stopped');
    }

    public static function dependancy_info() {

      	$return = array();
        $filename = dirname(__FILE__) . '/../../resources/trafficmirror_version';
    	$return['log']   = __CLASS__ . '_dependancy';
        $return['state'] = (file_exists($filename)) ? 'ok' : 'in_progress';
    	return $return;

    }


    public static function dependancy_install() {
		log::add('trafficmirror', 'debug', 'Install dependancy');
        // Remove the existing log file, if any
        log::remove(__CLASS__ . '_dependancy');
        return [
            'script' => dirname(__FILE__) . '/../../resources/install.sh ' . jeedom::getTmpFolder('trafficmirror') . '/dependancy',
            'log' => log::getPathToLog(__CLASS__ . '_dependancy')
        ];
  	}

	/**
	* Function used to populate the daemon
	*/
	//
    private static function daemonCommunication($_verb, $_options = array()) {
		$servicePort = config::byKey('servicePort', 'trafficmirror', 15003);
		$url         = 'http://localhost:' . $servicePort . '/mirrors/';

        if ($_verb === 'GET' || $_verb === 'DELETE') {
			if (isset($_options['id'])) {
            	$url = $url . urlencode($_options['id']);
			}
            $data = [];
        } else {
            // 'POST', 'PUT' . Copy the _options into a new array
			$data = $_options;
			if ($_verb === 'PUT') {
				$url = $url . urlencode($_options['id']);
				unset($data['id']);
			}
        }
        //log::add('trafficmirror', 'debug', ' url ' . $url . ', data=' . print_r($data, true));

        $nbRetry  = 0;
        $maxRetry = 3;
        while ($nbRetry < $maxRetry) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HEADER, false);
            //curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            if (count($data) > 0) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
            curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);

            // set the verb (POST, GET, DELETE)
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $_verb);

            $rsp = curl_exec($ch);
            $nbRetry++;
            if (curl_errno($ch) && $nbRetry < $maxRetry) {
                curl_close($ch);
                usleep($this->getSleepTime());
            } else {
                $nbRetry = $maxRetry + 1;
            }
        }
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        log::add('trafficmirror', 'debug', 'Response : ' . $rsp . ', code=' . $http_code);
        if (($_verb === 'DELETE' && $http_code != 204)
            || ($_verb === 'POST' && $http_code != 201)
            || ($_verb === 'GET' && $http_code != 200)) {
            throw new Exception($http_code);
        }
        return json_decode($rsp, true);
    }


	private static function daemonGetMirrors() {
		log::add('trafficmirror', 'debug', 'daemonGetMirrors');
		try {
			return self::daemonCommunication('GET', array());
		} catch (Exception $e) {
			log::add('trafficmirror', 'error', 'Erreur: ' . $e->getMessage());
			return [];
		}
	}

	private function daemonGetMirror() {
		log::add('trafficmirror', 'debug', 'daemonGetMirror');
		try {
			return self::daemonCommunication('GET', array('id' => $this->getId()));
		} catch (Exception $e) {
			if ($e->getMessage() !== '404') {
				log::add('trafficmirror', 'error', 'Erreur: ' . $e->getMessage());
			}
			return [];
		}
	}

	private function daemonDeleteMirror() {
		log::add('trafficmirror', 'debug', 'daemonDeleteMirror');
		try {
			return self::daemonCommunication('DELETE', array('id' => $this->getId()));
		} catch (Exception $e) {
			if ($e->getMessage() !== '404') {
				log::add('trafficmirror', 'error', 'Erreur: ' . $e->getMessage());
			}
		}
	}

	private function daemonAddMirror() {
		log::add('trafficmirror', 'debug', 'daemonAddMirror');
		$payload = array(
						 'id' 		  => $this->getId(),
						 'localPort'  => $this->getConfiguration('localPort'),
						 'mirrorHost' => $this->getConfiguration('mirrorHost'),
						 'mirrorPort' => $this->getConfiguration('mirrorPort'),
						 'targetHost' => $this->getConfiguration('targetHost'),
						 'targetPort' => $this->getConfiguration('targetPort'),
						 'protocol'   => $this->getConfiguration('protocol'),
					  );
		try {
			self::daemonCommunication('POST', $payload);
		} catch (Exception $e) {
			if ($e->getMessage === '409') {
				// Use update instead
				log::add('trafficmirror', 'debug', 'le miroir existe déjà, utiliser PUT pour le mettre a jour');
			} else {
				log::add('trafficmirror', 'error', 'Erreur: ' . $e->getMessage());
			}
		}
	}

	private function daemonUpdateMirror() {
		log::add('trafficmirror', 'debug', 'daemonUpdateMirror');
		$payload = array(
						 'id' 		  => $this->getId(),
						 'mirrorHost' => $this->getConfiguration('mirrorHost'),
						 'mirrorPort' => $this->getConfiguration('mirrorPort'),
						 'targetHost' => $this->getConfiguration('targetHost'),
						 'targetPort' => $this->getConfiguration('targetPort'),
						 'protocol'   => $this->getConfiguration('protocol'),
					  );
		try {
			$this->daemonCommunication('PUT', $payload);
		} catch (Exception $e) {
			log::add('trafficmirror', 'error', 'Erreur: ' . $e->getMessage());
		}
	}

	private function daemonClearStatistics() {
		log::add('trafficmirror', 'debug', 'daemonClearStatistics');

		$payload = array(
						 'id' 		         => $this->getId(),
						 'clientConnections' => 0,
						 'targetConnections' => 0,
						 'mirrorConnections' => 0,
						 'clientRxPkts'      => 0,
						 'clientTxPkts'      => 0,
						 'targetRxPkts'      => 0,
						 'targetTxPkts'      => 0,
						 'targetErrPkts'     => 0,
						 'mirrorRxPkts'      => 0,
						 'mirrorTxPkts'      => 0,
						 'mirrorErrPkts'     => 0
					 );
		try {
			self::daemonCommunication('PUT', $payload);
		} catch (Exception $e) {
			log::add('trafficmirror', 'error', 'Erreur: ' . $e->getMessage());
		}
	}


	private function updateStatistics($stats) {

		$infos = [
		   'isListening',
		   'clientConnections', 'targetConnections', 'mirrorConnections',
		   'clientRxPkts', 'clientTxPkts',
		   'targetRxPkts', 'targetTxPkts', 'targetErrPkts',
		   'mirrorRxPkts', 'mirrorTxPkts', 'mirrorErrPkts',
	   ];
	   foreach ($infos as $info) {
		   $this->checkAndUpdateCmd($info, $stats[$info]);
	   }

	}
    /*
     * Fonction exécutée automatiquement toutes les minutes par Jeedom
      public static function cron() {
      }
     */

    /*
     * Fonction exécutée automatiquement toutes les 5 minutes par Jeedom
	 */
      public static function cron5() {
		  /* Do the polling to get statistics from the daemon */
		  $mirrors = self::daemonGetMirrors();
		  foreach ($mirrors as $mirror) {
		  	 if(is_object($mirror) && $mirror->getIsEnable() == 1) {
			 	 $mirror->updateStatistics();
			 }
  		  }
      }

    /*
     * Fonction exécutée automatiquement toutes les 10 minutes par Jeedom
      public static function cron10() {
      }
     */

    /*
     * Fonction exécutée automatiquement toutes les 15 minutes par Jeedom
      public static function cron15() {
      }
     */

    /*
     * Fonction exécutée automatiquement toutes les 30 minutes par Jeedom
      public static function cron30() {
      }
     */

    /*
     * Fonction exécutée automatiquement toutes les heures par Jeedom
      public static function cronHourly() {
      }
     */

    /*
     * Fonction exécutée automatiquement tous les jours par Jeedom
      public static function cronDaily() {
      }
     */



    /*     * *********************Méthodes d'instance************************* */

 // Fonction exécutée automatiquement avant la création de l'équipement
    public function preInsert() {
		log::add('trafficmirror', 'debug', 'Entering preInsert: ' . $this->getHumanName());
    }

 // Fonction exécutée automatiquement après la création de l'équipement
    public function postInsert() {
		log::add('trafficmirror', 'debug', 'Entering postInsert: ' . $this->getHumanName());
    }

 // Fonction exécutée automatiquement avant la mise à jour de l'équipement
    public function preUpdate() {
		log::add('trafficmirror', 'debug', 'Entering preUpdate: ' . $this->getHumanName());

		// Check parameters, all configuration parameters should be filled
		$port = $this->getConfiguration('localPort', 0);
		if ($port < 1 || $port > 65535) {
			throw new Exception(__('Le port local du service doit être entre 1 et 65535.',__FILE__));
		}

		$ip = gethostbyname($this->getConfiguration('targetHost', ''));
		if(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false &&
		   filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
		   throw new Exception(__('L\'adresse IP de la destination n\'est pas valide.',__FILE__));
		}
		$port = $this->getConfiguration('targetPort', 0);
		if ($port < 1 || $port > 65535) {
			throw new Exception(__('Le port de la destination doit être entre 1 et 65535.',__FILE__));
		}

		$ip = gethostbyname($this->getConfiguration('mirrorHost', ''));
		if(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false &&
		   filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
		   throw new Exception(__('L\'adresse IP du miroir n\'est pas valide.',__FILE__));
		}
		$port = $this->getConfiguration('mirrorPort', 0);
		if ($port < 1 || $port > 65535) {
			throw new Exception(__('Le port du miroir doit être entre 1 et 65535.',__FILE__));
		}

		log::add('trafficmirror', 'debug', 'Exiting preUpdate: ' . $this->getHumanName());
    }

 // Fonction exécutée automatiquement après la mise à jour de l'équipement
    public function postUpdate() {
		log::add('trafficmirror', 'debug', 'Entering postUpdate: ' . $this->getHumanName());

		// Send the information to the daemon. Update if necessary, otherwise create
		// the new object
		if (count($this->daemonGetMirror()) === 0) {
			$this->daemonAddMirror();
		} else {
			$this->daemonUpdateMirror();
		}
    }

 // Fonction exécutée automatiquement avant la sauvegarde (création ou mise à jour) de l'équipement
    public function preSave() {
		log::add('trafficmirror', 'debug', 'Entering preSave: ' . $this->getHumanName());

    }

 // Fonction exécutée automatiquement après la sauvegarde (création ou mise à jour) de l'équipement
    public function postSave() {
		log::add('trafficmirror', 'debug', 'Entering postSave: ' . $this->getHumanName());

		// Create commands
		$order = 1;

		$cmd = $this->getCmd(null, 'refresh');
		if (!is_object($cmd)) {
			$cmd = new trafficmirrorCmd();
			$cmd->setLogicalId('refresh');
			$cmd->setIsVisible(1);
			$cmd->setName(__('Rafraîchir', __FILE__));
            $cmd->setOrder($order++);
        }
		$cmd->setEqLogic_id($this->getId());
		$cmd->setType('action');
		$cmd->setSubType('other');
        $cmd->save();

		$cmd = $this->getCmd(null, 'clearStatistics');
		if (!is_object($cmd)) {
			$cmd = new trafficmirrorCmd();
			$cmd->setLogicalId('clearStatistics');
			$cmd->setIsVisible(1);
			$cmd->setName(__('RAZ statistiques', __FILE__));
            $cmd->setOrder($order++);
        }
		$cmd->setEqLogic_id($this->getId());
		$cmd->setType('action');
		$cmd->setSubType('other');
        $cmd->save();

		$cmd = $this->getCmd(null, 'isListening');
		if (!is_object($cmd)) {
			$cmd = new trafficmirrorCmd();
			$cmd->setLogicalId('isListening');
			$cmd->setIsVisible(1);
			$cmd->setName(__('Opérationnel', __FILE__));
            $cmd->setOrder($order++);
        }
		$cmd->setEqLogic_id($this->getId());
		$cmd->setType('info');
		$cmd->setSubType('binary');
        $cmd->save();
		$this->checkAndUpdateCmd('isListening', false);

		$stats = [
			'clientConnections', 'targetConnections', 'mirrorConnections',
			'clientRxPkts', 'clientTxPkts',
			'targetRxPkts', 'targetTxPkts', 'targetErrPkts',
			'mirrorRxPkts', 'mirrorTxPkts', 'mirrorErrPkts',
		];
		foreach ($stats as $stat) {
			$cmd = $this->getCmd(null, $stat);
			if (!is_object($cmd)) {
				$cmd = new trafficmirrorCmd();
				$cmd->setLogicalId($stat);
				$cmd->setIsVisible(1);
				$cmd->setName(__($stat, __FILE__));
            	$cmd->setOrder($order++);
        	}
			$cmd->setEqLogic_id($this->getId());
			$cmd->setType('info');
			$cmd->setSubType('mumerical');
        	$cmd->save();
			$this->checkAndUpdateCmd($stat, 0);
		}
    }

 // Fonction exécutée automatiquement avant la suppression de l'équipement
    public function preRemove() {
		log::add('trafficmirror', 'debug', 'Entering preRemove: ' . $this->getHumanName());
		$this->daemonDeleteMirror();
    }

 // Fonction exécutée automatiquement après la suppression de l'équipement
    public function postRemove() {
		log::add('trafficmirror', 'debug', 'Entering postRemove: ' . $this->getHumanName());

    }
    /*
     * Non obligatoire : permet de modifier l'affichage du widget (également utilisable par les commandes)
      public function toHtml($_version = 'dashboard') {

      }
     */

	 public function refresh() {
		 log::add('trafficmirror', 'debug', 'Entering refresh: ' . $this->getHumanName());

		 $rsp = self::daemonGetMirror();
		 $this->updateStatistics($rsp);
	 }

	 public function clearStatistics() {
		 log::add('trafficmirror', 'debug', 'Entering clearStatistics: ' . $this->getHumanName());

	 	 $this->daemonClearStatistics();
		 $stats = [
 			'clientConnections', 'targetConnections', 'mirrorConnections',
 			'clientRxPkts', 'clientTxPkts',
 			'targetRxPkts', 'targetTxPkts', 'targetErrPkts',
 			'mirrorRxPkts', 'mirrorTxPkts', 'mirrorErrPkts',
 		];
		foreach ($stats as $stat) {
			$this->checkAndUpdateCmd($stat, 0);
		}
	 }

    /*     * **********************Getteur Setteur*************************** */
}

class trafficmirrorCmd extends cmd {
    /*     * *************************Attributs****************************** */

    /*
      public static $_widgetPossibility = array();
    */

    /*     * ***********************Methode static*************************** */


    /*     * *********************Methode d'instance************************* */

    /*
     * Non obligatoire permet de demander de ne pas supprimer les commandes même si elles ne sont pas dans la nouvelle configuration de l'équipement envoyé en JS
	 */
      public function dontRemoveCmd() {
      	return true;
      }

  // Exécution d'une commande
     public function execute($_options = array()) {
		$mirror = $this->getEqLogic();
 		switch ($this->getLogicalId()) {
 			case 'refresh':
                 $mirror->refresh();
                 break;
			case 'clearStatistics':
				$mirror->clearStatistics();
				break;
         }
     }

    /*     * **********************Getteur Setteur*************************** */
}
