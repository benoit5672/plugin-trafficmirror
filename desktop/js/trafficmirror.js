
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


/*
* Permet la réorganisation des commandes dans l'équipement
*/
$("#table_cmd").sortable({axis: "y", cursor: "move", items: ".cmd", placeholder: "ui-state-highlight", tolerance: "intersect", forcePlaceholderSize: true});

/*
* Fonction permettant l'affichage des commandes dans l'équipement
*/
function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
     var _cmd = {configuration: {}};
   }
   if (!isset(_cmd.configuration)) {
     _cmd.configuration = {};
   }
   var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
   tr += '    <td>';
   tr += '        <input class="cmdAttr form-control input-sm" data-l1key="id" style="display : none;">';
   tr += '        <input class="cmdAttr form-control input-sm" data-l1key="name" placeholder="{{Nom}}">';
   tr += '    </td>';
   tr += '    <td>';
   tr += '        <span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>';
   tr += '        <span class="subType" subType="' + init(_cmd.subType) + '"></span>';
   tr += '    </td>';
   tr += '    <td>';
   tr += '        <label class="checkbox-inline"><input type="checkbox" class="cmdAttr checkbox-inline" data-l1key="isVisible" checked/>{{Afficher}}</label>';
   tr += '        <label class="checkbox-inline"><input type="checkbox" class="cmdAttr checkbox-inline" data-l1key="isHistorized" checked/>{{Historiser}}</label>';
   tr += '    </td>';
   tr += '    <td>';
   tr += '       <span class="cmdAttr" data-l1key="htmlstate"></span>';
   tr += '    </td>';   
   tr += '    <td>';
   if (is_numeric(_cmd.id)) {
      tr += '     <a class="btn btn-default btn-xs cmdAction expertModeVisible" data-action="configure"><i class="fa fa-cogs"></i></a> ';
      tr += '     <a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fa fa-rss"></i> {{Tester}}</a>';
   }
   tr += '    </td>';
   tr += '</tr>';
   $('#table_cmd tbody').append(tr);
   var tr = $('#table_cmd tbody tr').last();
   jeedom.eqLogic.builSelectCmd({
     id:  $('.eqLogicAttr[data-l1key=id]').value(),
     filter: {type: 'info'},
     error: function (error) {
       $('#div_alert').showAlert({message: error.message, level: 'danger'});
     },
     success: function (result) {
       tr.find('.cmdAttr[data-l1key=value]').append(result);
       tr.setValues(_cmd, '.cmdAttr');
       jeedom.cmd.changeType(tr, init(_cmd.subType));
     }
   });
 }



 // ---- Thanks @mips: open documentation or community links from the desktop
 $('.pluginAction[data-action=openLocation]').on('click',function(){
     window.open($(this).attr("data-location"), "_blank", null);
 });
