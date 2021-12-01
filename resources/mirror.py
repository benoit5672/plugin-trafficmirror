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

based on http://pymotw.com/2/select/
'''


import logging
import select
import socket
import threading
import struct


class Mirror:
    __TIMEOUT = 30

    def __init__(self, id,
                 localAddr, localPort,
                 targetAddr, targetPort,
                 mirrorAddr, mirrorPort,
                 protocol,
                 targetRxToMirror, mirrorRxToClient,
                 jeedom):
        self.__jeedom            = jeedom
        self.__id                = id
        self.__localAddr         = localAddr
        self.__localPort         = int(localPort)
        self.__targetAddr        = targetAddr
        self.__targetPort        = int(targetPort)
        self.__mirrorAddr        = mirrorAddr
        self.__mirrorPort        = int(mirrorPort)
        self.__protocol          = protocol
        self.__targetRxToMirror  = (targetRxToMirror == 1)
        self.__mirrorRxToClient  = (mirrorRxToClient == 1)
        self.__server            = None
        self.__sockets           = []
        self.__connections       = {}
        self.clearStatistics()

        if localAddr == None:
            self.__localAddr = '0.0.0.0'

    def start(self):
        logging.info('Start thread for {} server {}:{}'.format(self.__protocol, self.__localAddr, self.__localPort))
        self.__stop     = False
        self.__t        = threading.Thread(target=self.__run)
        self.__t.daemon = True
        self.__t.start()

    def stop(self, waitForStop = True):
        logging.info('Stop thread for {} server {}:{}'.format(self.__protocol, self.__localAddr, self.__localPort))
        self.__stop = True
        if waitForStop:
            self.__t.join()
            del self.__t
            gc.collect()

    def getStatistics(self):
        return {
            'id'                : self.__id,
            'clientConnections' : self.__clientConnections,
            'targetConnections' : self.__targetConnections,
            'mirrorConnections' : self.__mirrorConnections,
            'targetErrConnect'  : self.__targetErrConnect,
            'mirrorErrConnect'  : self.__mirrorErrConnect,
            'clientTxPkts'      : self.__clientTxPkts,
            'clientRxPkts'      : self.__clientRxPkts,
            'clientErrPkts'     : self.__clientErrPkts,
            'targetRxPkts'      : self.__targetRxPkts,
            'targetTxPkts'      : self.__targetTxPkts,
            'targetErrPkts'     : self.__targetErrPkts,
            'mirrorRxPkts'      : self.__mirrorRxPkts,
            'mirrorTxPkts'      : self.__mirrorTxPkts,
            'mirrorErrPkts'     : self.__mirrorErrPkts
        }

    def clearStatistics(self):
        self.__clientConnections = 0
        self.__targetConnections = 0
        self.__mirrorConnections = 0
        self.__targetErrConnect  = 0
        self.__mirrorErrConnect  = 0
        self.__clientRxPkts      = 0
        self.__clientTxPkts      = 0
        self.__clientErrPkts     = 0
        self.__targetRxPkts      = 0
        self.__targetTxPkts      = 0
        self.__targetErrPkts     = 0
        self.__mirrorRxPkts      = 0
        self.__mirrorTxPkts      = 0
        self.__mirrorErrPkts     = 0

    def setStatistics(self, stats):
        self.__clientConnections = stats['clientConnections']
        self.__targetConnections = stats['targetConnections']
        self.__mirrorConnections = stats['mirrorConnections']
        self.__targetErrConnect  = stats['targetErrConnect']
        self.__mirrorErrConnect  = stats['mirrorErrConnect']
        self.__clientRxPkts      = stats['clientRxPkts']
        self.__clientTxPkts      = stats['clientTxPkts']
        self.__clientErrPkts     = stats['clientErrPkts']
        self.__targetRxPkts      = stats['targetRxPkts']
        self.__targetTxPkts      = stats['targetTxPkts']
        self.__targetErrPkts     = stats['targetErrPkts']
        self.__mirrorRxPkts      = stats['mirrorRxPkts']
        self.__mirrorTxPkts      = stats['mirrorTxPkts']
        self.__mirrorErrPkts     = stats['mirrorErrPkts']


    def __run(self):
        try:
            self.__server = self._createServerSocket(self.__localAddr, self.__localPort)
            self.__sockets.append(self.__server)
            logging.info('{} server started : listening on {} {}' .format(self.__protocol, self.__localAddr, self.__localPort))
            while not self.__stop:
                logging.debug('number of entries in sockets: {}'.format(len(self.__sockets)))
                readable, writable, exceptional = select.select(self.__sockets, [], self.__sockets, self.__TIMEOUT)
                if not (readable or writable or exceptional):
                    # we got a timeout, send heartbeat
                    if (self.__jeedom != None):
                        self.__jeedom.heartbeat(self.__id)
                    continue

                for s in readable:
                    if s is self.__server:
                        # we received a new connection request
                        logging.info('Receive new connection request on {} server {}:{}'.format(self.__protocol, self.__localAddr, self.__localPort))
                        # We need to create connection to target and mirror
                        # if it fails, then we won't accept the connection from the client
                        target = self.__createTarget()
                        mirror = self.__createMirror()
                        if target != None and mirror != None:
                            # We have created target and mirror socket
                            # we accept the connection from the client (SYN/ACK)
                            client, addr = s.accept()
                            logging.info('  accept client {} connection from {}:{}'.format(self.__protocol, addr[0], addr[1]))
                            #client.setblocking(0)
                            self.__clientConnections = self.__clientConnections + 1
                            self.__storeConnections(client, target, mirror)
                            break
                        else:
                            logging.error('{} connection is closed because mirror or target refused the connection request'.format(self.__protocol))
                            # Accept and close with RST
                            client, addr = s.accept()
                            client.setsockopt(socket.SOL_SOCKET, socket.SO_LINGER, struct.pack('ii', 1, 0))
                            client.close()
                            if (target != None):
                                target.shutdown(socket.SHUT_RDWR)
                                target.close()
                            if (mirror != None):
                                mirror.shutdown(socket.SHUT_RDWR)
                                mirror.close()
                            break
                    else:
                        # We received data on client, target or mirror sockets
                        try:
                            data = self.__recvFrom(s, 5)
                            if len(data) == 0:
                                self.__closeConnections(s)
                                continue

                            if (len(self.__connections[s]['listeners']) == 0):
                                logging.info('   no {} listener installed for {} {}'.format(self.__protocol, self.__connections[s]['mode'], s.getpeername()))
                            else:
                                for sock in self.__connections[s]['listeners']:
                                    self.__sendTo(sock, data)
                        except Exception as e:
                            logging.error('Error sending of receiving: {}'.format(e))
                            self.__closeConnections(s)
                            break

                # Handle "exceptional conditions"
                for s in exceptional:
                    logging.error('handling exceptional condition for {} server from {}'.format(protocol, s.getpeername()))
                    # Stop listening for input on the connection
                    self.__closeConnections(s)

        except Exception as e:
            logging.error('Failed to listen on {}:{}'.format(self.__localAddr, self.__localPort))
            logging.error('exception {}'.format(e))
        finally:
            # shutdown server
            if self.__server:
                self.__server.shutdown(socket.SHUT_RDWR)
                self.__server.close()
            # close all connections
            self.__closeConnections()

    #@abstractmethod
    def _createServerSocket(self, addr, port):
        pass

    #@abstractmethod
    def _createTargetSocket(self, addr, port):
        pass

    #@abstractmethod
    def _createMirrorSocket(self, addr, port):
        pass

    #@abstractmethod
    def _recvData(self, sock):
        pass

    #@abstractmethod
    def _sendData(self, sock, data):
        pass

    def __createTarget(self):
        try:
            logging.info('  create target {}:{}'.format(self.__targetAddr, self.__targetPort))
            target = self._createTargetSocket(self.__targetAddr, self.__targetPort)
            self.__targetConnections = self.__targetConnections + 1
            return target
        except Exception as e:
            self.__targetErrConnect = self.__targetErrConnect + 1
            logging.error('Unable to connect to {} target {}:{} ({})'.format(self.__protocol, self.__targetAddr, self.__targetPort, e))
            return None

    def __createMirror(self):
        try:
            logging.info('  create mirror {}:{}'.format(self.__mirrorAddr, self.__mirrorPort))
            mirror = self._createMirrorSocket(self.__mirrorAddr, self.__mirrorPort)
            self.__mirrorConnections = self.__mirrorConnections + 1
            return mirror
        except Exception as e:
            self.__mirrorErrConnect = self.__mirrorErrConnect + 1
            logging.error('Unable to connect to {} mirror {}:{} ({})'.format(self.__protocol, self.__mirrorAddr, self.__mirrorPort, e))
            return None

    def __storeConnections(self, client, target, mirror):
        self.__sockets.append(client)
        self.__sockets.append(target)
        self.__sockets.append(mirror)

        #create the connection information
        # client
        self.__connections[client] = {}
        self.__connections[client]['listeners'] = [target, mirror]
        self.__connections[client]['peers']     = [target, mirror]
        self.__connections[client]['mode']      = 'client'

        # target
        self.__connections[target] = {}
        self.__connections[target]['listeners'] = [client]
        if self.__targetRxToMirror == 1:
            self.__connections[target]['listeners'].append(mirror)
        self.__connections[target]['peers']     = [client, mirror]
        self.__connections[target]['mode']      = 'target'

        # mirror
        self.__connections[mirror] = {}
        self.__connections[mirror]['listeners'] = []
        if self.__mirrorRxToClient == 1:
            self.__connections[mirror]['listeners'].append(client)
        self.__connections[mirror]['peers']     = [client, target]
        self.__connections[mirror]['mode']      = 'mirror'

    def __closeConnections(self, sock=None):
        if (sock == None):
            logging.info('Close all {} connections attached with {}:{}'.format(self.__protocol, self.__localAddr, self.__localPort))
            socks = []
            for key in self.__connections:
                if self.__connections[key]['mode'] == 'client':
                    socks.append(key)
        else:
            logging.info('Close {} connections attached with {}'.format(self.__protocol, sock.getpeername()))
            socks = [sock]

        for key in socks:
            # Remove socket from select
            for s in self.__connections[key]['peers']:
                logging.info('   remove and close {} connection to {}'.format(self.__protocol, s.getpeername()))
                self.__sockets.remove(s)
                s.shutdown(socket.SHUT_RDWR)
                s.close()
            logging.info('   remove and close {} connection to {}'.format(self.__protocol, sock.getpeername()))
            self.__sockets.remove(key)
            key.shutdown(socket.SHUT_RDWR)
            key.close()

            # clean memory
            del self.__connections[key]['listeners']
            del self.__connections[key]['peers']
            del self.__connections[key]['mode']
            del self.__connections[key]

    def __recvFrom(self, sock, timeout):
        data = b''
        sock.settimeout(timeout)
        try:
            data = self._recvData(sock)
            if data:
                if self.__connections[sock]['mode'] == 'client':
                    self.__clientRxPkts = self.__clientRxPkts + 1
                elif self.__connections[sock]['mode'] == 'mirror':
                    self.__mirrorRxPkts = self.__mirrorRxPkts + 1
                elif self.__connections[sock]['mode'] == 'target':
                    self.__targetRxPkts = self.__targetRxPkts + 1

                logging.info(' RECV {} bytes from {} {}'.format(len(data), self.__connections[sock]['mode'], sock.getpeername()))
                #logging.debug('RECV {} from {} {}'.format(data, self.__connections[sock]['mode'], sock.getpeername()))
            return data
        except Exception as e:
            logging.error('Error receiving from {} {} (reason: {})'.format(self.__connections[sock]['mode'], sock.getpeername(), e))
            if self.__connections[sock]['mode'] == 'client':
                self.__clientErrPkts = self.__clientErrPkts + 1
            elif self.__connections[sock]['mode'] == 'mirror':
                self.__mirrorErrPkts = self.__mirrorErrPkts + 1
            elif self.__connections[sock]['mode'] == 'target':
                self.__targetErrPkts = self.__targetErrPkts + 1
            raise e

    def __sendTo(self, sock, data):
            if self.__connections[sock]['mode'] == 'client':
                self.__clientTxPkts = self.__clientTxPkts + 1
            elif self.__connections[sock]['mode'] == 'mirror':
                self.__mirrorTxPkts = self.__mirrorTxPkts + 1
            elif self.__connections[sock]['mode'] == 'target':
                self.__targetTxPkts = self.__targetTxPkts + 1

            logging.info('   SEND {} bytes to {} {}'.format(len(data), self.__connections[sock]['mode'], sock.getpeername()))
            #logging.debug('SEND {} to {} {}'.format(data, self.__connections[sock]['mode'], sock.getpeername()))

            try:
                self._sendData(sock, data)
            except Exception as e:
                logging.error('Error sending {} bytes to {} {} (reason: {})'.format(len(data), self.__connections[sock]['mode'], sock.getpeername(), e))
                if self.__connections[sock]['mode'] == 'client':
                    self.__clientErrPkts = self.__clientErrPkts + 1
                elif self.__connections[sock]['mode'] == 'mirror':
                    self.__mirrorErrPkts = self.__mirrorErrPkts + 1
                elif self.__connections[sock]['mode'] == 'target':
                    self.__targetErrPkts = self.__targetErrPkts + 1
                raise e
