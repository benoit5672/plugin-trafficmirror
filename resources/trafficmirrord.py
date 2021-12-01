#!/usr/bin/env python3
'''
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
'''
import logging
import os
import sys
import time
import signal
import json
import argparse
import socketserver
import requests
import threading
import uuid
import subprocess
import collections
import gc

from datetime import date, datetime, timedelta

from tcpmirror import TcpMirror

BASE_PATH   = os.path.join(os.path.dirname(__file__), '..', '..', '..', '..')
BASE_PATH   = os.path.abspath(BASE_PATH)
PLUGIN_NAME = "trafficmirrord"
MIRRORS     = {}
DATEFORMAT  = '%Y-%m-%d %H:%M:%S'
LOGLEVEL    = logging.WARNING;

"""
Permet d'interroger Jeedom à partir du démon
"""
class JeedomCallback:
    def __init__(self, apikey, url):
        logging.info('Create {} daemon'.format(PLUGIN_NAME))
        self.url      = url
        self.apikey   = apikey
        self.messages = []

    def __request(self, m):
        response = None
        for i in range (0,3):
            #logging.debug('Send to jeedom : {}'.format(json.dumps(m)))
            r = requests.post('{}?apikey={}'.format(self.url, self.apikey), data=json.dumps(m), verify=False)
            #logging.debug('Status Code :  {}'.format(r.status_code))
            if r.status_code != 200:
                logging.error('Error on send request to jeedom, return code {} - {}'.format(r.status_code, r.reason))
                time.sleep(0.150)
            else:
                response = r.json()
                #logging.debug('Jeedom reply :  {}'.format(response))
                break
        return response

    def send(self, message):
        self.messages.append(message)

    def __send_now(self, message):
        return self.__request(message)

    def test(self):
        r = self.__send_now({'action': 'test'})
        if not r or not r.get('success'):
            logging.error('Calling jeedom failed')
        return True

    def heartbeat(self, id):
        r = self.__send_now({'action' : 'heartbeat', 'id' : id})
        if not r or not r.get('success'):
            logging.error('Calling jeedom failed for heartbeat')
            return False
        return True

    def getStatistics(self, id):
        r = self.__send_now({'action' : 'get_statistics', 'id' : id})
        if not r or not r.get('success'):
            logging.error('Error calling getStatistics')
            return False
        return r['value'] == 1

    def setStatistics(self, id, statistics):
        r = self.__send_now({'action': 'set_statistics', 'id' : id, 'value': (0,1)[statistics]})
        if not r or not r.get('success'):
            logging.error('Error during update status')
        return True

    def getMirrors(self):
        logging.info('Get mirrors from Jeedom')
        mirrors = self.__send_now({'action':'get_mirrors'})
        if not mirrors or not mirrors.get('success'):
            logging.error('FAILED')
            return None
        #values = json.loads(mirrors)
        return mirrors



"""
Intercepte les demandes de Jeedom : update_mirror, insert_mirror and remove_mirror
ainsi que get_statistics
"""
class JeedomHandler(socketserver.BaseRequestHandler):

    def __removeMirror(self, id):
        if id in MIRRORS:
            MIRRORS[id].stop(False)
            del MIRRORS[id]

    def __updateMirror(self, args):
        response = {'result': None, 'return_code': None}
        if (len(args.keys()) != 10):
            response['return_code'] = 400
            response['result'] = 'updateMirror: Invalid number of arguments'
            return response

        # get arguments
        id         = args['id']
        if id not in MIRRORS:
            response['return_code'] = 404;
            response['result'] = 'updateMirror: The mirror does not exist'
            return response

        # update
        logging.debug('Update mirror {}'.format(id))
        statistics = MIRRORS[id].getStatistics()
        self.__removeMirror(id)
        self.__insertMirror(args)
        if id in MIRRORS:
            MIRRORS[id].setStatistics(statistics)
            response['return_code'] = 200
            response['result'] = 'Update OK'
        else:
            response['return_code'] = 500
            response['result'] = 'Update fail'
        return response


    def __insertMirror(self, args):
        response = {'result': None, 'return_code': None}

        if (len(args.keys()) != 10):
            response['return_code'] = 400
            response['result'] = 'update_mirror: Invalid number of arguments'
            return response

        # get arguments
        id         = args['id']
        localAddr  = args['localAddr']
        localPort  = args['localPort']
        targetHost = args['targetHost']
        targetPort = args['targetPort']
        mirrorHost = args['mirrorHost']
        mirrorPort = args['mirrorPort']
        targetRx   = args['targetRx']
        mirrorRx   = args['mirrorRx']
        protocol   = args['protocol']

        if id in MIRRORS:
            response['return_code'] = 409;
            response['result'] = 'insert_mirror: The mirror already exists'
            return response

        # create
        logging.debug('Add new mirror {}'.format(id))
        if protocol == 'tcp':
            MIRRORS[id] = TcpMirror(id,
                                    localAddr, localPort,
                                    targetHost, targetPort,
                                    mirrorHost, mirrorPort,
                                    targetRx, mirrorRx,
                                    jc)
            MIRRORS[id].start()
            response['result'] = 'Insert OK'
            response['return_code'] = 201
        else:
            response['result'] = 'Insert failed (unknown protocol)'
            response['return_code'] = 500

        return response



    def handle(self):
        # self.request is the TCP socket connected to the client
        self.data = self.request.recv(1024)
        logging.debug("Message received in socket")
        message = json.loads(self.data.decode())
        lmessage = dict(message)
        del lmessage['apikey']
        logging.debug(lmessage)
        response = {'result': None, 'return_code': None}
        stop = False
        if message.get('apikey') != _apikey:
            logging.error("Invalid apikey from socket : {}".format(self.data))
            return

        action = message.get('action')
        args   = message.get('args')

        if action == 'update_mirror':
            response = self.__updateMirror(args)

        if action == 'insert_mirror':
            response = self.__insertMirror(args)

        if action == 'remove_mirror':
            id = args['id']
            if id not in MIRRORS:
                response['return_code'] = 404
                response['result'] = 'remove_mirror: The mirror does not exist'
            else:
                self.__removeMirror(id)
                response['return_code'] = 204
                response['result'] = 'Remove OK'

        if action == 'exist_mirror':
            id = args['id']
            response['return_code'] = 200
            if id in MIRRORS:
                response['result'] = 1
            else:
                response['result'] = 0

        if action == 'get_statistics':
            id = args['id']
            if id not in MIRRORS:
                response['return_code'] = 404
                response['result'] = 'get_statistics: The mirror does not exist'
            else:
                response['return_code'] = 200
                response['result'] = MIRRORS[id].getStatistics()

        if action == 'clear_statistics':
            id = args['id']
            if id not in MIRRORS:
                response['return_code'] = 404
                response['result'] = 'clear_statistics: The mirror does not exist'
            else:
                MIRRORS[id].clearStatistics()
                response['return_code'] = 200
                response['result'] = 'clear_statistics OK'

        if action == 'logdebug':
            logging.debug('Dynamically change log to debug')
            log = logging.getLogger()
            for hdlr in log.handlers[:]:
               log.removeHandler(hdlr)
               logging.basicConfig(level=logging.DEBUG,
                                   format=FORMAT, datefmt="%Y-%m-%d %H:%M:%S")
            response['result'] = 'logdebug OK'
            response['return_code'] = 200
            logging.debug('logging level is now DEBUG')

        if action == 'lognormal':
            logging.debug('Dynamically restore the default log level')
            log = logging.getLogger()
            for hdlr in log.handlers[:]:
               log.removeHandler(hdlr)
               logging.basicConfig(level=LOGLEVEL,
                                   format=FORMAT, datefmt="%Y-%m-%d %H:%M:%S")
            response['return_code'] = 200
            response['result'] = 'lognormal OK'

        if action == 'stop':
            logging.debug('Receive stop request from jeedom')
            response['return_code'] = 200
            stop = True

        self.request.sendall(json.dumps(response).encode())

        if stop == True:
            os.kill(os.getpid(),signal.SIGTERM)


"""
Converti le loglevel envoyer par jeedom
"""
def convert_log_level(level='error'):
    LEVELS = {'debug': logging.DEBUG,
              'info': logging.INFO,
              'notice': logging.WARNING,
              'warning': logging.WARNING,
              'error': logging.ERROR,
              'critical': logging.CRITICAL,
              'none': logging.NOTSET,
              'default': logging.INFO }
    return LEVELS.get(level, logging.NOTSET)

def handler(signum=None, frame=None):
    logging.debug("Signal %i caught, exiting..." % int(signum))
    print("Signal %i caught, exiting..." % int(signum))
    shutdown()

"""
shutdown: nettoie les ressources avant de quitter
"""
def shutdown():
    logging.info("=========== Shutdown ===========")
    logging.info("Stopping all threads")
    for key in MIRRORS:
        MIRRORS[key].stop(False)
    logging.info("Shutting down local server")
    server.shutdown()
    server.server_close()
    if (_sockfile != None and len(str(_sockfile)) > 0):
        logging.info("Removing Socket file " + str(_sockfile))
        if os.path.exists(_sockfile):
            os.remove(_sockfile)
    logging.info("Removing PID file " + str(_pidfile))
    if os.path.exists(_pidfile):
        os.remove(_pidfile)
    logging.info("Exit 0")
    logging.info("=================================")

### Init & Start
parser = argparse.ArgumentParser()
parser.add_argument('--loglevel', help='LOG Level', default='warning')
parser.add_argument('--socket', help='Daemon socket', default='')
parser.add_argument('--sockethost', help='Daemon socket host', default='')
parser.add_argument('--socketport', help='Daemon socket port', default='0')
parser.add_argument('--pidfile', help='PID File', default='/tmp/{}d.pid'.format(PLUGIN_NAME))
parser.add_argument('--apikey', help='API Key', default='nokey')
parser.add_argument('--callback', help='Jeedom callback', default='http://localhost')
args = parser.parse_args()

FORMAT = '[%(asctime)-15s][%(levelname)s][%(name)s](%(threadName)s) : %(message)s'
LOGLEVEL = convert_log_level(args.loglevel);
logging.basicConfig(level=LOGLEVEL,
                    format=FORMAT, datefmt="%Y-%m-%d %H:%M:%S")
urllib3_logger = logging.getLogger('urllib3')
urllib3_logger.setLevel(logging.CRITICAL)

logging.info('Start {}d'.format(PLUGIN_NAME))
logging.info('Log level : {}'.format(args.loglevel))
logging.info('Socket : {}'.format(args.socket))
logging.info('PID file : {}'.format(args.pidfile))
logging.info('Callback : {}'.format(args.callback))
logging.info('Python version : {}'.format(sys.version))

_pidfile = args.pidfile
_sockfile = args.socket
_apikey = args.apikey

# Configuration du handler pour intercepter les commandes
# kill -9 et kill -15
signal.signal(signal.SIGINT, handler)
signal.signal(signal.SIGTERM, handler)

# Ecrit le PID du démon dans un fichier
pid = str(os.getpid())
logging.debug("Writing PID " + pid + " to " + str(args.pidfile))
with open(args.pidfile, 'w') as fp:
    fp.write("%s\n" % pid)

# Configure et test le callback vers jeedom
jc = JeedomCallback(args.apikey, args.callback)
if not jc.test():
    sys.exit()

# Démarre le serveur qui écoute les requests de jeedom
logging.info('Use Unix socket for Jeedom -> daemon communication')
if os.path.exists(args.socket):
    os.unlink(args.socket)

server = socketserver.UnixStreamServer(args.socket, JeedomHandler)
handlerThread = threading.Thread(target=server.serve_forever)
handlerThread.start()

# Récupération des devices dans Jeedom (pm=pluginMirrors)
pm = jc.getMirrors()

# Create mirrors and start threads
if pm != None and len(pm['value']) > 0:
    for key in pm['value'].keys():
        mirror = pm['value'][key]
        if mirror['protocol'] == 'tcp':
            id = mirror['id']
            MIRRORS[id] = TcpMirror(id,
                                    mirror['localAddr'], mirror['localPort'],
                                    mirror['targetHost'], mirror['targetPort'],
                                    mirror['mirrorHost'], mirror['mirrorPort'],
                                    mirror['targetRx'], mirror['mirrorRx'],
                                    jc)
            MIRRORS[id].start()
        else:
            logging.warning('Unknown protocol {}'.format(mirror['protocol']))
