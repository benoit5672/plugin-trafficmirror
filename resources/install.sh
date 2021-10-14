#!/bin/bash
# From script : https://github.com/NebzHB/nodejs_install
# Thanks to NebzHB
#

######################### INCLUSION LIB ##########################
BASEDIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
sudo wget https://raw.githubusercontent.com/NebzHB/dependance.lib/master/dependance.lib -O $BASEDIR/dependance.lib &>/dev/null
PLUGIN=$(basename "$(realpath $BASEDIR/..)")
sudo mkdir -p /tmp/jeedom/${PLUGIN}
sudo chmod 777 /tmp/jeedom/${PLUGIN}
. ${BASEDIR}/dependance.lib
##################################################################
sudo wget https://raw.githubusercontent.com/NebzHB/nodejs_install/main/install_nodejs.sh -O $BASEDIR/install_nodejs.sh &>/dev/null

installVer='14' 	#NodeJS major version to be installed

pre

if [ -f ${BASEDIR}/trafficmirror_version ]; then
	sudo rm -f ${BASEDIR}/trafficmirror_version
fi

step 0 "Début d'installation des dependances"
cd ${BASEDIR}
sudo rm -rf nodes_modules
sudo rm -f package-lock.json

step 5 "Mise à jour APT et installation des packages nécessaires"
try sudo apt-get update
#try sudo DEBIAN_FRONTEND=noninteractive apt-get install -y exemple_package_needed_after_step_50

#install nodejs, steps 10->50
. ${BASEDIR}/install_nodejs.sh ${installVer}

step 60 "Installation des dépendances"
sudo npm install

step 90 "Fin d'installation des dépendances"
sudo touch ${BASEDIR}/trafficmirror_version

post
