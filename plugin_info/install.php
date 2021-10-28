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

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

function deleteDirectory($dirPath) {
    if (is_dir($dirPath)) {
        $objects = scandir($dirPath);
        foreach ($objects as $object) {
            if ($object != "." && $object !="..") {
                if (filetype($dirPath . DIRECTORY_SEPARATOR . $object) == "dir") {
                    deleteDirectory($dirPath . DIRECTORY_SEPARATOR . $object);
                } else {
                    unlink($dirPath . DIRECTORY_SEPARATOR . $object);
                }
            }
        }
        reset($objects);
        rmdir($dirPath);
    }
}

// Fonction exécutée automatiquement après l'installation du plugin
  function trafficmirror_install() {

  }

// Fonction exécutée automatiquement après la mise à jour du plugin
  function trafficmirror_update() {
      // files to remove, after migrating from nodejs to python3
      $files = ['dependance.lib', 'install_nodejs.sh', 'install.sh',
                'package.json', 'tcp-mirror.js', 'udp-mirror.js',
                'trafficmirrord.js', 'trafficmirror_version',
                'util_log.js'];
      $dirs =  ['node_modules'];
      foreach ($files as $f) {
          $path = dirname(__FILE__) . '/' . $f;
          unlink($path);
      }
      foreach ($dirs as $d) {
          $path = dirname(__FILE__) . '/' . $d;
          deleteDirectory($path);
      }
  }

// Fonction exécutée automatiquement après la suppression du plugin
  function trafficmirror_remove() {

  }

?>
