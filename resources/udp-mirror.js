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

const dgram   = require('dgram');
const util   = require('util');
const logger = require('./util_log');

/** PUBLIC function */
module.exports.createUdpMirror = function(options) {

    return new UdpMirror(options);
};

function UdpMirror(options) {

    this.id                = options['id'];
    this.localAddr         = options['localAddr'];
    this.localPort         = options['localPort'];
    this.mirrorHost        = options['mirrorHost'];
    this.mirrorPort        = options['mirrorPort'];
    this.targetHost        = options['targetHost'];
    this.targetPort        = options['targetPort'];
    this.isListening       = false;
    this.isMirrorConnected = false;
    this.isTargetConnected = false;
    this.clientRxPkts      = options['clientRxPkts'] || 0;
    this.clientTxPkts      = options['clientTxPkts'] || 0;
    this.targetRxPkts      = options['targetRxPkts'] || 0;
    this.targetTxPkts      = options['targetTxPkts'] || 0;
    this.targetErrPkts     = options['targetErrPkts'] || 0;
    this.mirrorRxPkts      = options['mirrorRxPkts'] || 0;
    this.mirrorTxPkts      = options['mirrorTxPkts'] || 0;
    this.mirrorErrPkts     = options['mirrorErrPkts'] || 0;
    this.log               = logger.createLogger('daemon-' + this.id, options['loglevel']);


    this.log.info(util.format('UDP listen on %s:%d, target %s:%d, mirror %s:%d',
                  this.localAddr, this.localPort, this.targetHost,
                  this.targetPort, this.mirrorHost, this.mirrorPort));

    this.createMirrorSocket();
    this.createTargetSocket();
    this.createUDPServer();
}

UdpMirror.prototype.toJSON = function() {
    var self = this;
    return {
                id: self.id,
                localAddr: self.localAddr,
                localPort: self.localPort,
                targetHost: self.targetHost,
                targetPort: self.targetPort,
                mirrorHost: self.mirrorHost,
                mirrorPort: self.mirrorPort,
                protocol: 'udp',
                isListening: self.isListening,
                activeConnections: 0,
                clientConnections: 0,
                targetConnections: 0,
                mirrorConnections: 0,
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

UdpMirror.prototype.createUDPServer = function() {
    var self    = this;
    var options = Object.assign({
        type: 'udp4',
    });
    self.server = dgram.createSocket(options);
    self.server.bind(self.localPort, self.localAddr, function() {
        self.isListening = true;
        self.log.info('UDP server started, listening on ' + self.localAddr + ':' + self.localPort);
    });

    self.server.on("message", function(msg, rinfo) {
        self.log.debug(util.format("UDPServer: onMessage : %s", msg));

        self.clientRxPkts++;

        if (self.isTargetConnected === true && self.targetSocket !== undefined) {
            self.targetTxPkts++;
            self.targetSocket.send(msg);
        } else {
            self.targetErrPkts++;
        }

        if (self.isMirrorConnected === true && self.mirrorSocket !== undefined) {
            self.mirrorTxPkts++;
            self.mirrorSocket.send(msg);
        } else {
            self.mirrorErrPkts;
        }
    });

    self.server.on("close", function(hadError) {
        self.log.debug("UDPServer: onClose");
        self.isTargetConnected = false;
        self.isMirrorConnected = false;

        if (self.targetSocket !== undefined) {
            self.targetSocket.destroy();
        }
        if (self.mirrorSocket !== undefined) {
            self.mirrorSocket.destroy();
        }
        self.log.info(util.format('UDP (c/t/m) : RX (%d/%d/%d), TX(%d/%d/%d), Err(%d/%d/%d)',
                             self.clientRxPkts, self.targetRxPkts, self.mirrorRxPkts,
                             self.clientTxPkts, self.targetTxPkts, self.mirrorTxPkts,
                             0, self.targetErrPkts, self.mirrorErrPkts));
    });

    self.server.on("error", function(e) {
        self.log.debug("UDPServer, onError: ", e);
        if (e.code === 'EADDRINUSE') {
          self.log.error(util.format('The port %d is already used on this platform', self.localPort));
        }
        self.isListening = false;
        // close is immediately called after "error"
    });
};

UdpMirror.prototype.end = function() {
    this.isListening = false;
    this.server.close();
    this.server.unref();
};

UdpMirror.prototype.createTargetSocket = function() {
    this.log.debug("UDPServer: createTargetSocket");

    var self = this;
    var options = Object.assign({
        type: 'udp4',
    });
    self.targetSocket = new dgram.createSocket(options);
    self.targetSocket.connect(self.targetPort, self.targetHost);

    // events
    self.targetSocket.on("listening", function() {
        self.log.debug("UDPtargetSocket: onListening");
        self.isTargetConnected = true;
    });

    self.targetSocket.on("message", function(msg, rinfo) {
        self.log.debug(util.format("UDPtargetSocket: onMessage : %s", msg));
        self.targetRxPkts++;

        // copy msg to proxy and mirror
        self.clientTxPkts++;
        self.server.send(msg);

        if (self.isMirrorConnected === true && self.mirrorSocket !== undefined) {
            self.mirrorTxPkts++;
            self.mirrorSocket.send(msg);
        } else {
            self.mirrorErrPkts++;
        }
    });

    self.targetSocket.on("close", function(hadError) {
        self.log.debug("UDPtargetSocket: onClose");
        self.server.destroy();
    });

    self.targetSocket.on("error", function(e) {
        self.log.debug("UDPtargetSocket: onError: " + e);
        if (e.code === 'ECONNREFUSED') {
            self.log.error(util.format("Unable to connect to target (%s:%d), aborting client connection", self.targetHost, self.targetPort));
        }
        self.server.destroy();
    });
};

UdpMirror.prototype.createMirrorSocket = function() {
    this.log.debug("UDPServer: createMirrorSocket");

    var self = this;
    var options = Object.assign({
        type: 'udp4',
    });

    self.mirrorSocket = dgram.createSocket(options);
    self.mirrorSocket.connect(self.mirrorPort, self.mirrorHost);

    // events
    self.mirrorSocket.on("listening", function() {
        self.log.debug("UDPmirrorSocket: onListening");
        self.isMirrorConnected = true;
    });

    self.mirrorSocket.on("message", function(msg, rinfo) {
        self.log.debug(util.format("UDPmirrorSocket: onMessage : %s", msg));
        self.mirrorRxPkts++;
        // always ignore msg from mirror
    });

    self.mirrorSocket.on("close", function(hadError) {
        self.log.debug("UDPmirrorSocket: onClose");
        self.server.destroy();
    });

    self.mirrorSocket.on("error", function(e) {
        self.log.debug("UDPmirrorSocket: onError: " + e);
        if (e.code === 'ECONNREFUSED') {
            self.log.error(util.format("Unable to connect to mirror (%s:%d), aborting client connection", self.mirrorHost, self.mirrorPort));
        }
        self.server.destroy();
    });
};
