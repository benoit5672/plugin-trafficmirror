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
       return $return;
    }

    public static function deamon_start() {

        log::add('trafficmirror', 'debug', 'Start the daemon');

        self::deamon_stop();
        $deamon_info = self::deamon_info();
        if ($deamon_info['launchable'] != 'ok') {
            throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
        }

        $deamon_path = dirname(__FILE__) . '/../../resources';
        $tcpport = config::byKey('servicePort', 'trafficmirror', 15003);
		$callback = network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') . '/plugins/trafficmirror/core/php/trafficmirror.php';

		$cmd = '/usr/bin/python3 ' . $deamon_path . '/trafficmirrord.py ';
        $cmd .= ' --loglevel ' . log::convertLogLevel(log::getLogLevel('trafficmirror'));
        $cmd .= ' --apikey ' . jeedom::getApiKey('trafficmirror');
        $cmd .= ' --pidfile ' . jeedom::getTmpFolder('trafficmirror') . '/daemon.pid';
        $cmd .= ' --socket ' . jeedom::getTmpFolder('trafficmirror') . '/daemon.sock';
        $cmd .= ' --callback ' . $callback;

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

	/**
	* Function used to populate the daemon
	*/
	//
    private static function daemonCommunication($_action, $expected, $_args = array()) {
		$socketPort = config::byKey('servicePort', 'trafficmirror', 15003);
		$sock       = 'unix://' . jeedom::getTmpFolder('trafficmirror') . '/daemon.sock';

		$query = array(
           'action' => $_action,
           'args' => $_args,
           'apikey' => jeedom::getApiKey('trafficmirror')
        );

		$fp = stream_socket_client($sock, $errno, $errstr, 5);
        $result = '';
        log::add('trafficmirror', 'debug', 'stream socket error ' . $errno .' : '. $errstr);

        if ($fp) {
            try {
		        stream_set_timeout($fp, 5);
                if (false !== fwrite($fp, json_encode($query))) {
                    while (!feof($fp)) {
                        $result .= fgets($fp, 1024);
	                	$info = stream_get_meta_data($fp);
		        		if ($info['timed_out']) {
                            log::add('trafficmirror', 'error', 'timeout in callDaemon('.$sock.') '.print_r($result, true));
			    			$result = '';
		        		}
            		}
	        	}
            } catch(Exception $ex) {
                log::add('trafficmirror', 'error', $ex->getMessage());
            } finally {
                fclose($fp);
            }
        }
        $result = (is_json($result)) ? json_decode($result, true) : $result;
		if ($result['return_code'] != $expected) {
			log::add('trafficmirror', 'error', 'error:' . $result['result'] . '(' . $result['return_code'] . ')');
			throw new Exception($result[$return_code]);
		}
        log::add('trafficmirror', 'debug', 'result daemonCommunication '.print_r($result, true));
        return $result['result'];
    }


	private function daemonExistMirror() {
		log::add('trafficmirror', 'debug', 'daemonExistMirror');
		try {
			return (self::daemonCommunication('exist_mirror', '200', array('id' => $this->getId())) == 1);
		} catch (Exception $e) {
			log::add('trafficmirror', 'error', 'Erreur: ' . $e->getMessage());
		}
	}

	private function daemonGetStatistics() {
		log::add('trafficmirror', 'debug', 'daemonGetStatistics');
		try {
			return self::daemonCommunication('get_statistics', '200', array('id' => $this->getId()));
		} catch (Exception $e) {
			if ($e->getMessage() != '404') {
				log::add('trafficmirror', 'error', 'Erreur: ' . $e->getMessage());
			}
		}
	}

	private function daemonClearStatistics() {
		log::add('trafficmirror', 'debug', 'daemonClearStatistics');
		try {
			return self::daemonCommunication('clear_statistics', '200', array('id' => $this->getId()));
		} catch (Exception $e) {
			if ($e->getMessage() != '404') {
				log::add('trafficmirror', 'error', 'Erreur: ' . $e->getMessage());
			}
		}
	}


	private function daemonRemoveMirror() {
		log::add('trafficmirror', 'debug', 'daemonRemoveMirror');
		try {
			return self::daemonCommunication('remove_mirror', '204', array('id' => $this->getId()));
		} catch (Exception $e) {
			if ($e->getMessage() != '404') {
				log::add('trafficmirror', 'error', 'Erreur: ' . $e->getMessage());
			}
		}
	}

	private function daemonInsertMirror() {
		log::add('trafficmirror', 'debug', 'daemonInsertMirror');
		$payload = array(
						 'id' 		  => $this->getId(),
						 'localAddr'  => '0.0.0.0',
						 'localPort'  => $this->getConfiguration('localPort'),
						 'mirrorHost' => $this->getConfiguration('mirrorHost'),
						 'mirrorPort' => $this->getConfiguration('mirrorPort'),
						 'targetHost' => $this->getConfiguration('targetHost'),
						 'targetPort' => $this->getConfiguration('targetPort'),
						 'protocol'   => $this->getConfiguration('protocol'),
						 'mirrorRx'   => $this->getConfiguration('mirrorRx', 0),
						 'targetRx'   => $this->getConfiguration('targetRx', 0),
					  );
		try {
			self::daemonCommunication('insert_mirror', '201', $payload);
		} catch (Exception $e) {
			if ($e->getMessage() == '409') {
				// Use update instead
				log::add('trafficmirror', 'debug', 'le miroir existe déjà, utiliser daemonUpdateMirror pour le mettre a jour');
			} else {
				log::add('trafficmirror', 'error', 'Erreur: ' . $e->getMessage());
			}
		}
	}

	private function daemonUpdateMirror() {
		log::add('trafficmirror', 'debug', 'daemonUpdateMirror');
		$payload = array(
						 'id' 		  => $this->getId(),
						 'localAddr'  => '0.0.0.0',
						 'localPort'  => $this->getConfiguration('localPort'),
						 'mirrorHost' => $this->getConfiguration('mirrorHost'),
						 'mirrorPort' => $this->getConfiguration('mirrorPort'),
						 'targetHost' => $this->getConfiguration('targetHost'),
						 'targetPort' => $this->getConfiguration('targetPort'),
						 'protocol'   => $this->getConfiguration('protocol'),
						 'mirrorRx'   => $this->getConfiguration('mirrorRx', 0),
						 'targetRx'   => $this->getConfiguration('targetRx', 0),
					  );
		try {
			self::daemonCommunication('update_mirror', '200', $payload);
		} catch (Exception $e) {
			log::add('trafficmirror', 'error', 'Erreur: ' . $e->getMessage());
		}
	}

	public static function daemonChangeLogLive($_level) {
        self::daemonCommunication($_level, '200');
    }


	private function updateStatistics($stats) {

		$infos = [
		   'clientConnections', 'targetConnections', 'mirrorConnections',
		   'targetErrConnect', 'mirrorErrConnect',
		   'clientRxPkts', 'clientTxPkts', 'clientErrPkts',
		   'targetRxPkts', 'targetTxPkts', 'targetErrPkts',
		   'mirrorRxPkts', 'mirrorTxPkts', 'mirrorErrPkts',
	   ];
	   log::add('trafficmirror', 'debug', 'updateStatistics: ' . print_r($stats, true));
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
		  // 1. get all eqLogic mirror objects and update with the information
		  // fetch from daemon
  		  foreach (self::byType(__CLASS__) as $mirror) {
              if (is_object($mirror) && $mirror->getIsEnable() == 1) {
				  $stats = $mirror->daemonGetStatistics();
				  if ($stats === undefined) {
					  //log::add('trafficmirror', 'error', __('Le miroir ' . $mirror->getId() . 'n\'est pas présent dans le démon', __FILE__));
					  continue;
				  }
			 	  $mirror->updateStatistics($stats);
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

 // Fonction exécutée  avant la création de l'équipement
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
		$isInDaemon = $this->daemonExistMirror();
		if ($this->getIsEnable() == true) {
			if ($isInDaemon == false) {
				$this->daemonInsertMirror();
			} else {
				$this->daemonUpdateMirror();
			}
    	} else {
			// The mirror has been disabled, remove it from the daemon
			if ($isInDaemon == true) {
				$this->daemonRemoveMirror();
			}
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
			$cmd->setLogicalId('');
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
			'targetErrConnect', 'mirrorErrConnect',
			'clientRxPkts', 'clientTxPkts', 'clientErrPkts',
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
			$cmd->setSubType('numeric');
        	$cmd->save();
			$this->checkAndUpdateCmd($stat, 0);
		}
    }

 // Fonction exécutée automatiquement avant la suppression de l'équipement
    public function preRemove() {
		log::add('trafficmirror', 'debug', 'Entering preRemove: ' . $this->getHumanName());
		$this->daemonRemoveMirror();
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
		 $this->updateStatistics($this->daemonGetStatistics());
	 }

	 public function clearStatistics() {
		 log::add('trafficmirror', 'debug', 'Entering clearStatistics: ' . $this->getHumanName());

	 	 $this->daemonClearStatistics();
		 $stats = [
 			'clientConnections', 'targetConnections', 'mirrorConnections',
			'targetErrConnect', 'mirrorErrConnect',
 			'clientRxPkts', 'clientTxPkts', 'clientErrPkts',
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
