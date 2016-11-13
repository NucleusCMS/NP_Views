<?php
/*
  History:
    v1.1
      - add getTableList
    v1.2
      - use sql_table
      - Added silent mode (configure via option) to support NP_MostViews
      - Added Cleanup upon uninstall option
      - Added "just number" minimalist mode
      - Added supportsFeature
    V1.2a
      - Added min version support
    V1.3
      - Replaced doSkinVar with DoTemplateVar
    V1.3a
      - Added repeat views ignore function aka repeat F5s from those bored and lonely one
    V1.3b
      - Fixed counting off by 1 bug
    V1.3c
      - Added <%Views(skipCount)%> to allow skipping count when used in template (ie to not count on click in main page)
    V1.4
      - Fixed ignoe same IP count problem
    V1.5
      - Added views_log table and changed plugin performance to check for unique visits by IP address. [gRegor]
      - Added option to set the length of time before re-counting hits from the same IP address (default: 2 hours) [gRegor]
    V1.6
      - Added plugin menu to display all view count, w/ counter reset function
      - Delete view counter and log for deleted item
    V1.7
      - use sql_query
    V1.8
      - Admin page enhancement to preserve order and sort info
    v1.9
      - Added item title in admin menu
    v1.9.1
      - ignore draft in admin menu
    v1.9.1-fix1
      - short_open_tag off
			- getURL method
      - delete [$CONF['IndexURL'] . ] for views/index.php
*/
class NP_Views extends NucleusPlugin {

   // Note: I never run this plugin on 2.0 and have no idea whether it
   //       wil work on <2.5. A user can simply chnage it to return
   //       '200' and see if it works (likely will). I will gladly
   //       change the min version to 2.0 and add the sql_table fix
   //       upon such report. 8)
    function getName()              {return 'Views';}
    function getDescription()       {return 'This plugin counts how many times an entry has been displayed.';}
    function getAuthor()            {return 'Rodrigo Moraes | Edmond Hui (admun) | gRegor Morrill';}
    function getURL()               {return 'https://github.com/NucleusCMS/NP_Views';} // return 'http://www.tipos.com.br'
    function getVersion()           {return '1.9.2';}
    function getMinNucleusVersion() {return '340';}
    function getEventList()         {return array('PostAddItem', 'QuickMenu', 'PostDeleteItem');}
    function supportsFeature($what) {return ($what=='SqlTablePrefix');}
    function getTableList()         {return array( sql_table('plugin_views'), sql_table('plugin_views_log') );}
    function hasAdminArea()         {return 1;}

   function doTemplateVar(&$item, $input) {
        $itemid    = $item->itemid;
        $remote_ip = serverVar('REMOTE_ADDR');
        $timespan  = $this->getOption('timespan') * 3600;
        $now       = $_SERVER['REQUEST_TIME'];

        // get the current Views count
        $query = sprintf('SELECT views FROM %s WHERE id=%s', sql_table('plugin_views'), $itemid);
        $result = sql_query($query);
        $total = sql_num_rows($result);
        $row = sql_fetch_object($result);
        $views = intval($row->views);

        // Only do count updates if "skipcount" is not set
        if ($input != 'skipcount') { // This takes care of previous items
            if ($total == 0) { //$views = 0;
                sql_query(sprintf("INSERT INTO %s (id, views) VALUES('%s', '1')", sql_table('plugin_views'), $itemid));
            }
            
            // Check the views_log table to see if this IP has a viewtime for this item
            $param = array(sql_table('plugin_views_log'), $remote_ip, $itemid);
            $result = sql_query(vsprintf("SELECT viewtime FROM %s WHERE ip='%s' AND itemid=%s", $param));
            
            // No views from this IP in the past X hours, so update the Views count
            if (sql_num_rows($result) == 0) {
                $views++;
                $this->_updateViewsCount($itemid, $views);
                $this->_addViewsLog($itemid, $remote_ip, $now);
            } else {
                $viewtime = sql_result($result, 0, 'viewtime');
            
                // It's been longer than X hours, so recount
                if (($now - $timespan) > $viewtime) {
                   $views++;
                   $this->_updateViewsCount($itemid, $views);
                   $this->_updateViewsLog($itemid, $remote_ip, $now);
                }
            }
        }
        
        // Clear logs that are more than X hours old
        $time = $now - $timespan;
        sql_query(sprintf("DELETE FROM %s WHERE (viewtime < %s)", sql_table('plugin_views_log'), $time));
        
        if($this->getOption('silent')=='no') echo $views;
   }

    function event_PostAddItem($data) {
        $query = sprintf("INSERT INTO %s (id, views) VALUES('%s', '0')", sql_table('plugin_views'), $data['itemid']);
        sql_query($query);
    }
    
    function event_PostDeleteItem($data) {
        $tpl = 'DELETE FROM %s WHERE id=%s';
        sql_query(sprintf($tpl, sql_table('plugin_views'),     $data['itemid']));
        sql_query(sprintf($tpl, sql_table('plugin_views_log'), $data['itemid']));
    }
    
    function _updateViewsCount($itemid, $views) { // update the Views table with the new count
        sql_query(sprintf("UPDATE %s SET views='%s' WHERE id=%s", sql_table('plugin_views'), $views, $itemid));
    }
    
    function _addViewsLog($itemid, $ip, $time) { // add IP and itemid to views_log table so it won't be recounted for X hours
        $param = array(sql_table('plugin_views_log'), $ip, $itemid, $time);
        sql_query(vsprintf("INSERT INTO %s (ip, itemid, viewtime) VALUES ('%s', '%s', '%s')", $param));
    }
    
    function _updateViewsLog($itemid, $ip, $time) { // update the views_log viewtime so it won't be recounted for X hours
        sql_query(sprintf("UPDATE %s SET viewtime='%s' WHERE ip='%s'", sql_table('plugin_views_log'), $time, $ip));
    }
    
    function doAction($actionType) {
        global $CONF, $member;
        if (!($member->isLoggedIn() && $member->isAdmin())) return 'Sorry. not allowed';
        
        if     ($actionType == 'resetview')
            sql_query(sprintf('UPDATE %s SET views=0 WHERE id=%s', sql_table('plugin_views'), requestVar('id')));
        elseif ($actionType == 'resetallview')
            sql_query(sprintf("UPDATE %s SET views=0",             sql_table('plugin_views')));
        
        $url = $CONF['PluginURL'] . sprintf("views/index.php?sort=%s&order=%s", requestVar('sort'), requestVar('order'));
        header('Location: ' . $url);
    }
    
    function install() {
      sql_query('CREATE TABLE IF NOT EXISTS ' . sql_table('plugin_views') . ' (id int(11) NOT NULL default "0", views int(15) NOT NULL default "0")');
      sql_query('CREATE TABLE IF NOT EXISTS ' . sql_table('plugin_views_log') . ' (id int(11) NOT NULL auto_increment, ip varchar(20) NOT NULL default "", itemid int(11) NOT NULL default "0", viewtime varchar(32) NOT NULL default "", PRIMARY KEY (id)
)');
      $this->createOption('silent','Silent mode - No #Display shown in Item (still need to add the skinVar, for use with MostViewed)','yesno','no');
      $this->createOption('deletetables',"Delete this plugin's table and data when uninstalling?",'yesno','yes');
      $this->createOption('timespan', 'Hours to wait before re-counting visitors', 'text', '2');
    }

    function unInstall() {
        if ($this->getOption('deletetables') == 'yes') {
           sql_query('DROP TABLE ' . sql_table('plugin_views') );
           sql_query('DROP TABLE ' . sql_table('plugin_views_log') );
        }
    }
    
   /**
    * Adds an entry to the 'Quick Menu' on the Nucleus administration pages.
    * The entry will link to the commentcontrol admin page
    */
   function event_QuickMenu(&$data) {
        global $member;
        if (!($member->isLoggedIn() && $member->isAdmin())) return;
        
        $data['options'][] = array('title'=>'View Counts', 'url'=>$this->getAdminURL(), 'tooltip'=>'See the view count of all items');
   }
}
