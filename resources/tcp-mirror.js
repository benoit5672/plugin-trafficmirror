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

/**
 * Based on node-tcp-proxy (https://github.com/tewarid/node-tcp-proxy)
 * from Devendra Tewari
 */

const net    = require('net');
const util   = require('util');
const logger = require('./util_log');

/** PUBLIC function */
module.exports.createTcpMirror = function(options) {

    return new TcpMirror(options);
};


function uniqueKey(socket) {
    var key = socket.remoteAddress + ":" + socket.remotePort;
    return key;
}


function TcpMirror(options) {

    this.id                = options['id'];
    this.localAddr         = options['localAddr'];
    this.localPort         = options['localPort'];
    this.mirrorHost        = options['mirrorHost'];
    this.mirrorPort        = options['mirrorPort'];
    this.targetHost        = options['targetHost'];
    this.targetPort        = options['targetPort'];
    this.isListening       = false;
    this.clientConnections = options['clientConnections'] || 0;
    this.targetConnections = options['targetConnections'] || 0;
    this.mirrorConnections = options['mirrorConnections'] || 0;
    this.clientRxPkts      = options['clientRxPkts'] || 0;
    this.clientTxPkts      = options['clientTxPkts'] || 0;
    this.targetRxPkts      = options['targetRxPkts'] || 0;
    this.targetTxPkts      = options['targetTxPkts'] || 0;
    this.targetErrPkts     = options['targetErrPkts'] || 0;
    this.mirrorRxPkts      = options['mirrorRxPkts'] || 0;
    this.mirrorTxPkts      = options['mirrorTxPkts'] || 0;
    this.mirrorErrPkts     = options['mirrorErrPkts'] || 0;
    this.acceptedSockets   = {};
    this.log               = logger.createLogger('daemon-' + this.id, options['loglevel']);


    this.log.info(util.format('TCP listen on %s:%d, target %s:%d, mirror %s:%d',
                  this.localAddr, this.localPort, this.targetHost,
                  this.targetPort, this.mirrorHost, this.mirrorPort));
    this.createServer();
}

TcpMirror.prototype.toJSON = function() {
    var self = this;
    return {
                id: self.id,
                localAddr: self.localAddr,
                localPort: self.localPort,
                targetHost: self.targetHost,
                targetPort: self.targetPort,
                mirrorHost: self.mirrorHost,
                mirrorPort: self.mirrorPort,
                protocol: 'tcp',
                isListening: self.isListening,
                activeConnections: Object.keys(self.acceptedSockets).length,
                clientConnections: self.clientConnections,
                targetConnections: self.targetConnections,
                mirrorConnections: self.mirrorConnections,
                clientRxPkts: self.clientRxPkts,
                clientTxPkts: self.clientTxPkts,
                targetRxPkts: self.targetRxPkts,
                targetTxPkts: self.targetTxPkts,
                targetErrPkts: self.targetErrPkts,
                mirrorRxPkts: self.mirrorRxPkts,
                mirrorTxPkts: self.mirrorTxPkts,
                mirrorErrPkts: self.mirrorErrPkts
            };
}

TcpMirror.prototype.createServer = function() {
    var self = this;
    self.server = net.createServer(function(socket) {
        self.handleClient(socket);
    });
    self.server.listen(self.localPort, self.localAddr);
    self.isListening = true;
    self.log.info('TCP Server started, listening on ' + self.localAddr + ':' + self.localPort);
};

TcpMirror.prototype.end = function() {
    this.isListening = false;
    this.server.close();
    for (var key in this.acceptedSockets) {
        this.acceptedSockets[key].destroy();
    }
    this.server.unref();
};

TcpMirror.prototype.handleClient = function(clientSocket) {
    this.log.debug("clientSocket: handleClient");

    var self = this;
    var key  = uniqueKey(clientSocket);
    self.acceptedSockets[key] = clientSocket;
    self.clientConnections++;

    self.log.info(util.format('active connections: %d', Object.keys(self.acceptedSockets).length));
    self.log.debug(util.format('(c/t/m) : total connections:  (%d/%d/%d), RX (%d/%d/%d), TX(%d/%d/%d), Err(%d/%d/%d)',
                         self.clientConnections, self.targetConnections, self.mirrorConnections,
                         self.clientRxPkts, self.targetRxPkts, self.mirrorRxPkts,
                         self.clientTxPkts, self.targetTxPkts, self.mirrorTxPkts,
                         0, self.targetErrPkts, self.mirrorErrPkts));

    var context = {
        targetBuffer: [],
        mirrorBuffer: [],
        isTargetConnected: false,
        isMirrorConnected: false,
        clientSocket: clientSocket,
    };

    // Create the target socket and mirror as soon as the client is connected.
    self.log.debug(util.format('Connecting to target %s:%d', self.targetHost, self.targetPort));
    self.createTargetSocket(context);

    self.log.debug(util.format('Connecting to mirror %s:%d', self.mirrorHost, self.mirrorPort));
    self.createMirrorSocket(context);

    clientSocket.on("end", function(data) {
        self.log.debug("clientSocket: end");
    });

    clientSocket.on("data", function(data) {
        self.log.debug(util.format("clientSocket: onData : %s", data));

        self.clientRxPkts++;

        // put data into buffer until the socket is created.
        if (context.isTargetConnected === true && context.targetSocket !== undefined) {
            self.targetTxPkts++;
            context.targetSocket.write(data);
        } else {
            self.targetErrPkts++;
            context.targetBuffer[context.targetBuffer.length] = data;
        }

        if (context.isMirrorConnected === true && context.mirrorSocket !== undefined) {
            self.mirrorTxPkts++;
            context.mirrorSocket.write(data);
        } else {
            self.mirrorErrPkts;
            context.mirrorBuffer[context.mirrorBuffer.length] = data;
        }
    });

    clientSocket.on("close", function(hadError) {
        self.log.debug("clientSocket: onClose");
        context.isTargetConnected = false;
        context.isMirrorConnected = false;

        delete self.acceptedSockets[uniqueKey(clientSocket)];
        if (context.targetSocket !== undefined) {
            context.targetSocket.destroy();
        }
        if (context.mirrorSocket !== undefined) {
            context.mirrorSocket.destroy();
        }
        self.log.info(util.format('active connections: %d', Object.keys(self.acceptedSockets).length));
        self.log.info(util.format('(c/t/m) : total connections:  (%d/%d/%d), RX (%d/%d/%d), TX(%d/%d/%d), Err(%d/%d/%d)',
                             self.clientConnections, self.targetConnections, self.mirrorConnections,
                             self.clientRxPkts, self.targetRxPkts, self.mirrorRxPkts,
                             self.clientTxPkts, self.targetTxPkts, self.mirrorTxPkts,
                             0, self.targetErrPkts, self.mirrorErrPkts));
    });

    clientSocket.on("error", function(e) {
        self.log.debug("clientSocket, onError: ", e);
        if (e.code === 'EADDRINUSE') {
          self.log.error(util.format('The port %d is already used on this platform', self.localPort));
        }
        self.isListening = false;
        // close is immediately called after "error"
    });
}


TcpMirror.prototype.createTargetSocket = function(context) {
    this.log.debug("clientSocket: createTargetSocket");

    var self = this;
    var options = Object.assign({
        port: self.targetPort,
        host: self.targetHost,
    });
    context.targetSocket = new net.Socket();
    context.targetSocket.connect(options);

    // events
    context.targetSocket.on("connect", function() {
        self.log.debug("targetSocket: onConnect");
        self.targetConnections++;

        if (context.targetBuffer.length > 0) {
            for (var i = 0; i < context.targetBuffer.length; i++) {
                context.targetSocket.write(context.targetBuffer[i]);
            }
        }
        context.isTargetConnected = true;
    });

    context.targetSocket.on("data", function(data) {
        self.log.debug(util.format("targetSocket: onData : %s", data));
        self.targetRxPkts++;

        // copy data to proxy and mirror
        self.clientTxPkts++;
        context.clientSocket.write(data);

        if (context.isMirrorConnected === true && context.mirrorSocket !== undefined) {
            self.mirrorTxPkts++;
            context.mirrorSocket.write(data);
        } else {
            self.mirrorErrPkts++;
        }
    });

    context.targetSocket.on("close", function(hadError) {
        self.log.debug("targetSocket: onClose");
        context.clientSocket.destroy();
    });

    context.targetSocket.on("error", function(e) {
        self.log.debug("targetSocket: onError: " + e);
        if (e.code === 'ECONNREFUSED') {
            self.log.error(util.format("Unable to connect to target (%s:%d), aborting client connection", self.targetHost, self.targetPort));
        }
        context.clientSocket.destroy();
    });
};

TcpMirror.prototype.createMirrorSocket = function(context) {
    this.log.debug("clientSocket: createMirrorSocket");

    var self = this;
    var options = Object.assign({
        port: self.mirrorPort,
        host: self.mirrorHost,
    });

    context.mirrorSocket = new net.Socket();
    context.mirrorSocket.connect(options);

    // events
    context.mirrorSocket.on("connect", function() {
        self.log.debug("mirrorSocket: onConnect");
        self.mirrorConnections++;
        if (context.mirrorBuffer.length > 0) {
            for (var i = 0; i < context.mirrorBuffer.length; i++) {
                context.mirrorSocket.write(context.mirrorBuffer[i]);
            }
        }
        context.isMirrorConnected = true;
    });

    context.mirrorSocket.on("data", function(data) {
        self.log.debug(util.format("mirrorSocket: onData : %s", data));
        self.mirrorRxPkts++;
        // always ignore data from mirror
    });

    context.mirrorSocket.on("close", function(hadError) {
        self.log.debug("mirrorSocket: onClose");
        context.clientSocket.destroy();
    });

    context.mirrorSocket.on("error", function(e) {
        self.log.debug("mirrorSocket: onError: " + e);
        if (e.code === 'ECONNREFUSED') {
            self.log.error(util.format("Unable to connect to mirror (%s:%d), aborting client connection", self.mirrorHost, self.mirrorPort));
        }
        context.clientSocket.destroy();
    });
};
