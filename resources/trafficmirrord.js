
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

const process     = require('process');
const express     = require('express');
const fs          = require('fs');
const yargs       = require('yargs/yargs')
const tcpMirror   = require('./tcp-mirror');
const udpMirror   = require('./udp-mirror');
const logger      = require('./util_log');

/**
 ******************************************************************************
 Traffic mirrors variables
 ******************************************************************************
 */

var servicePort = undefined;
var pidfile     = undefined;
var mirrors     = {};
const server    = express();
const log       = logger.createLogger('daemon', 'info');


/**
 ******************************************************************************
 * REST API implementation
 ******************************************************************************
 */
server.use(express.json());
server.use(express.urlencoded({ extended: true }));


server.get('/mirrors', (req,res) => {
    log.debug('GET mirrors. Mirrors count: ' + Object.keys(mirrors).length);
    res.status(200).json(mirrors);
})

server.get('/mirrors/:id', (req,res) => {
    log.debug('GET mirrors id: ' + req.params.id);
    const mirror = mirrors[req.params.id];
    if (mirror !== undefined) {
        res.status(200).json(mirror);
    } else {
        res.sendStatus(404);
        log.warning('Le miroir '  + id + ' n\'existe pas');
    }
})

server.post('/mirrors', (req,res) => {
    log.debug('REQ: ' + req.baseUrl);
    log.debug('BODY: ' + req.body);
    log.debug('POST mirrors id: ' + req.body.id);

    const id = req.body.id;
    if (mirrors[id] !== undefined) {
        log.warning('Le miroir '  + id + ' existe déjà');
        res.sendStatus(409);
    } else {
        // validate all the parameters are embeded in the payload
        log.debug('id:'          + req.body.id);
        log.debug('localPort:  ' + req.body.localPort)
        log.debug('mirrorHost: ' + req.body.mirrorHost);
        log.debug('mirrorPort: ' + req.body.mirrorPort);
        log.debug('targetHost: ' + req.body.targetHost);
        log.debug('targetPort: ' + req.body.targetPort);
        log.debug('protocol:   ' + req.body.protocol);
        if (req.body.id !== undefined
            && req.body.localPort  !== undefined
            && req.body.mirrorHost !== undefined
            && req.body.mirrorPort !== undefined
            && req.body.targetHost !== undefined
            && req.body.targetPort !== undefined
            && req.body.protocol   !== undefined) {

            // Create the mirror
            options = {};
            options['id']         = req.body.id;
            options['localAddr']  = req.body.localAddr || '0.0.0.0';
            options['localPort']  = req.body.localPort;
            options['targetHost'] = req.body.targetHost;
            options['targetPort'] = req.body.targetPort;
            options['mirrorHost'] = req.body.mirrorHost;
            options['mirrorPort'] = req.body.mirrorPort;
            options['loglevel']   = log.getLogLevel();
            if (req.body.protocol === 'tcp') {
                var mirror = tcpMirror.createTcpMirror(options);
                mirrors[req.body.id] = mirror;
            } else if (req.body.protocol === 'udp') {
                var mirror = udpMirror.createUdpMirror(options);
                mirrors[req.body.id] = mirror;
            }
            res.sendStatus(201);
            log.info('Le miroir '  + id + ' a été créé. Nombre miroirs: ' + Object.keys(mirrors).length);
        } else {
            res.sendStatus(400);
            log.warning('Le miroir '  + id + ' ne peut être créé, il manque des paramètres');
        }
    }
})

server.put('/mirrors/:id', (req,res) => {
    log.debug('PUT mirrors id: ' + req.params.id);
    const id   = req.params.id;
    var mirror = mirrors[id];
    if (mirror !== undefined) {
        var options = JSON.parse(JSON.stringify(mirror));
        if (req.body.mirrorHost !== undefined) {
            options['mirrorHost'] = req.body.mirrorHost;
        }
        if (req.body.mirrorPort !== undefined) {
            options['mirrorPort'] = req.body.mirrorPort;
        }
        if (req.body.targetHost !== undefined) {
            options['targetHost'] = req.body.targetHost;
        }
        if (req.body.targetPort !== undefined) {
            options['targetPort'] = req.body.targetPort;
        }
        if (req.body.protocol !== undefined) {
            options['protocol'] = req.body.protocol;
        }
        if (req.body.clientConnections !== undefined) {
            options['clientConnections'] = req.body.clientConnetions;
        }
        if (req.body.targetConnections !== undefined) {
            options['targetConnections'] = req.body.targetConnetions;
        }
        if (req.body.mirrorConnections !== undefined) {
            options['mirrorConnections'] = req.body.mirrorConnetions;
        }
        if (req.body.clientRxPkts !== undefined) {
            options['clientRxPkts'] = req.body.clientRxPkts;
        }
        if (req.body.clientTxPkts !== undefined) {
            options['clientTxPkts'] = req.body.clientTxPkts;
        }
        if (req.body.targetRxPkts !== undefined) {
            options['targetRxPkts'] = req.body.targetRxPkts;
        }
        if (req.body.targetTxPkts !== undefined) {
            options['targetTxPkts'] = req.body.targetTxPkts;
        }
        if (req.body.targetErrPkts !== undefined) {
            options['targetErrPkts'] = req.body.targetErrPkts;
        }
        if (req.body.mirrorRxPkts !== undefined) {
            options['mirrorRxPkts'] = req.body.mirrorRxPkts;
        }
        if (req.body.mirrorTxPkts !== undefined) {
            options['mirrorTxPkts'] = req.body.mirrorTxPkts;
        }
        if (req.body.mirrorErrPkts !== undefined) {
            options['mirrorErrPkts'] = req.body.mirrorErrPkts;
        }
        mirror.end();
        if (options['protocol'] === 'tcp') {
            mirrors[id] = tcpMirror.createTcpMirror(options);
        } else if (options['protocol'] === 'udp') {
            mirrors[id] = udpMirror.createUdpMirror(options);
        }
        delete(mirror);
        res.sendStatus(200);
        log.info('Le miroir '  + id + ' a été mis a jour');
    } else {
        res.sendStatus(404);
        log.warning('Le miroir '  + id + ' n\'existe pas');
    }
})

server.delete('/mirrors/:id', (req,res) => {
    const id   = req.params.id
    var mirror = mirrors[id];
    if (mirror !== undefined) {
        // remove from array
        mirrors.splice(mirrors.indexOf(mirror),1);
        // delete the object !
        delete(mirror);
        res.sendStatus(204);
        log.info('Le miroir '  + id + ' a été supprimé. Nombre de miroirs : ' + Object.keys(mirrors).length);
    } else {
        res.sendStatus(404);
        log.warning('Le miroir '  + id + ' n\'existe pas');
    }
})


/**
 ******************************************************************************
 * process exit cleanup
 ******************************************************************************
 */
process.stdin.resume();//so the program will not close instantly

function exitHandler(options, exitCode) {
    if (options.cleanup) {
        log.debug('deamon', 'Suppression du fichier ' + pidfile);
        try {
            if (fs.existsSync(pidfile)) {
                fs.unlinkSync(pidfile);
            }
        } catch (err) {
            log.error('deamon', 'Erreur lors de la suppression du fichier pid ' + err);
        }
    }
    if (options.exit) {
        log.debug('deamon', 'daemon stopped');
        process.exit(exitCode);
    }
}

//do something when app is closing
process.on('exit', exitHandler.bind(null, {cleanup:true}));

//catches ctrl+c event and kill SIGNAL
process.on('SIGINT', exitHandler.bind(null, {exit:true}));
process.on('SIGTERM', exitHandler.bind(null, {exit:true}));

//catches uncaught exceptions
process.on('uncaughtException', exitHandler.bind(null, {exit:true}));

/**
 ******************************************************************************
 * Main
 ******************************************************************************
 */
log.debug('start daemon');

//const argv = yargs(hideBin(process.argv)).argv
const argv = yargs(process.argv.slice(2))
    .usage('Usage: $0 [options]')
    .example('$0 --pidfile /tmp/trafficmirror.pid --loglevel debug --port 5003', 'start traffic mirror in debug mode, listening on port 5003')
    .alias('f', 'pidfile')
    .nargs('f', 1)
    .describe('f', 'the pidfile to create')
    .demandOption(['f'])
    .alias('l', 'loglevel')
    .nargs('l', 1)
    .describe('l', 'loglevel (debug, info, warning, error)')
    .alias('p', 'port')
    .nargs('p', 1)
    .describe('p', 'serviceport')
    .demandOption(['p'])
    .help('h')
    .alias('h', 'help')
    .epilog('copyright 2021')
    .argv;

if (argv.port === undefined || argv.pidfile === undefined) {
    log.error('Il manque un paramètre obligatoire au lancement du démon');
    process.exit(1);
}
servicePort = parseInt(argv.port);
pidfile     = argv.pidfile;
log.setLogLevel(argv.loglevel);
log.debug('servicePort: ' + servicePort);
log.debug('pidfile: ' + pidfile);
log.debug('level: ' + log.getLogLevel());

// Write pid file
try {
    log.debug('Création du fichier pid (' + pidfile + ')');
    fs.writeFileSync(pidfile, process.pid.toString());
} catch (err) {
    log.error('Erreur lors de la création du fichier pid ' + err);
    process.exit(1);
}

// bind the server to 'servicePort' and start listening
try {
    server.listen(servicePort, '127.0.0.1');
} catch (err) {
    log.error('Erreur lors du lancement du démon ' + err);
    process.exit(1);
}

log.info('daemon started !');
