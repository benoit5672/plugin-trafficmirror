
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

 const dateFormat  = require('dateformat');

 /** PUBLIC function */

module.exports.createLogger = function(fct, level) {
    return new Logger(fct, level);
};

const DEBUG   = 100;
const INFO    = 200;
const WARNING = 300;
const ERROR   = 400;

function convertUTCDateToLocalDate(date) {
  var newDate = new Date(date.getTime() + date.getTimezoneOffset() * 60 * 1000);
  var offset = date.getTimezoneOffset() / 60;
  var hours = date.getHours();
  newDate.setHours(hours - offset);
  return newDate;
}

function log(lvl, fct, str) {
  var now     = convertUTCDateToLocalDate(new Date());
  var datestr = dateFormat(now, "yyyy:mm:dd hh:MM:ss");
  var fctstr  = fct.substring(0, 10) +  ' '.repeat(Math.max(10 - fct.length, 0));
  console.log( "[" + datestr + "][" + lvl + "][" + fct + '] ' + str);
}

function Logger(fct, level) {
    this.fct = fct;
    this.setLogLevel(level);
}

Logger.prototype.setLogLevel = function(level) {
    var self = this;
    switch(level) {
        case 'debug':
            self.level = DEBUG;
            break;
        case 'info':
            self.level = INFO;
            break;
        case 'warning':
            self.level = WARN;
            break;
        case 'error':
            self.level = ERROR;
            break;
        default:
            self.level = INFO;
    }
}

Logger.prototype.getLogLevel = function() {
    var self = this;
    switch(self.level) {
        case DEBUG:
            return 'debug';
        case WARN:
            return 'warning';
        case ERROR:
            return 'error';
        case INFO:
        default:
            return 'info';
    }
}

Logger.prototype.debug = function(str) {
    var self = this;
    if (self.level <= DEBUG) {
        log( "DEBUG", self.fct, str);
    }
}

Logger.prototype.info = function(str) {
    var self = this;
    if (self.level <= INFO) {
        log( "INFO-", self.fct, str);
    }
}

Logger.prototype.warning = function(str) {
    self = this;
    if (self.level <= WARNING) {
        log( "WARN-", self.fct, str);
    }
}

Logger.prototype.error = function(str) {
    var self = this;
    if (self.level <= ERROR) {
        log( "ERROR", self.fct, str);
    }
}
