#!/bin/bash
# From script : https://github.com/NebzHB/nodejs_install
# Thanks to NebzHB
#

######################### INCLUSION LIB ##########################
BASEDIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
wget https://raw.githubusercontent.com/NebzHB/dependance.lib/master/dependance.lib -O $BASEDIR/dependance.lib &>/dev/null
PLUGIN=$(basename "$(realpath $BASEDIR/..)")
. ${BASEDIR}/dependance.lib
##################################################################
wget https://raw.githubusercontent.com/NebzHB/nodejs_install/main/install_nodejs.sh -O $BASEDIR/install_nodejs.sh &>/dev/null

installVer='14' 	#NodeJS major version to be installed

pre
step 0 "Début d'installation des dependances"
if [ -f /var/www/html/plugins/trafficmirror/resources/trafficmirror_version ]; then
	rm /var/www/html/plugins/trafficmirror/resources/trafficmirror_version
fi
cd ../../plugins/trafficmirror/resources
sudo rm -rf nodes_modules
sudo rm package-lock.json

step 5 "Mise à jour APT et installation des packages nécessaires"
try sudo apt-get update
#try sudo DEBIAN_FRONTEND=noninteractive apt-get install -y exemple_package_needed_after_step_50

#install nodejs, steps 10->50
. ${BASEDIR}/install_nodejs.sh ${installVer}

step 60 "Installation nodejs (https)"
sudo npm install https

step 70 "Installation nodejs (dataformat)"
sudo npm install dateformat

step 80 "Installation nodejs (yargs)"
sudo npm install yargs

step 90 "Installation nodejs (express)"
sudo npm install express

step 95 "Fin d'installation des dependances"
touch /var/www/html/plugins/trafficmirror/resources/trafficmirror_version
rm -f $BASEDIR/dependance.lib
rm -f $BASEDIR/install_nodejs.sh

post
