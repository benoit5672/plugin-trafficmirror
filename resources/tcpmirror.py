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
import select
import socket

from mirror import Mirror

class TcpMirror(Mirror):
    def __init__(self, id,
                 localAddr, localPort,
                 targetAddr, targetPort,
                 mirrorAddr, mirrorPort,
                 targetRxToMirror = 0, mirrorRxToClient = 0,
                 jeedom = None):

        super().__init__(id,
                         localAddr, localPort,
                         targetAddr, targetPort,
                         mirrorAddr, mirrorPort,
                         'TCP',
                         targetRxToMirror, mirrorRxToClient,
                         jeedom)

    def _createServerSocket(self, addr, port):
        server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)

        # Create the connection socket
        server.setblocking(0)
        server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)

        server.bind((addr, port))
        server.listen(5)
        return server

    def _createTargetSocket(self, addr, port):
        targetSocket = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        targetSocket.connect((addr, port))
        targetSocket.setblocking(0)
        return targetSocket

    def _createMirrorSocket(self, addr, port):
        mirrorSocket = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        mirrorSocket.connect((addr, port))
        mirrorSocket.setblocking(0)
        return mirrorSocket

    def _recvData(self, sock):
        chunks = []
        chunk  = b''
        while True:
            chunk = sock.recv(4096)
            chunks.append(chunk)
            if chunk == b'' or len(chunk) < 4096:
                # no more data to read
                break
        return b''.join(chunks)

    def _sendData(self, sock, data):
        totalsent = 0
        datalen   = len(data)
        while totalsent < datalen:
            sent = sock.send(data[totalsent:])
            if sent == 0:
                raise Exception('socket connection broken')
            totalsent = totalsent + sent
