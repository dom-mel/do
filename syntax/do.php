<?php
/**
 * DokuWiki Plugin do (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <gohr@cosmocode.de>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once(DOKU_PLUGIN.'syntax.php');

class syntax_plugin_do_do extends DokuWiki_Syntax_Plugin {
    private $docstash;
    private $taskdata;
    private $run;
    private $status;
    private $oldStatus;
    private $position = 0;
    private $saved = array();
    private $ids = array();

    function getType() {
        return 'formatting';
    }

    function getPType() {
        return 'normal';
    }

    function getSort() {
        return 155;
    }

    function getAllowedTypes() {
        return array('formatting');
    }

    function connectTo($mode) {
       $this->Lexer->addEntryPattern('<do.*?>(?=.*?</do>)',$mode,'plugin_do_do');
    }

    function postConnect() {
        $this->Lexer->addExitPattern('</do>','plugin_do_do');
    }

    function handle($match, $state, $pos, &$handler){
        $data = array();
        $data['state'] = $state;
        switch($state){
            case DOKU_LEXER_ENTER:
                $match = trim(substr($match,3,-1));

                if(preg_match('/\b(\d\d\d\d-\d\d-\d\d)\b/',$match,$grep)){
                    $data['date'] = $grep[1];
                    $match = trim(str_replace($data['date'],'',$match));
                }
                //FIXME call $auth->cleanUser()
                $data['user'] = $match;
                break;

            case DOKU_LEXER_UNMATCHED:
                $data['match'] = $match;
                break;
        }

        return $data;
    }

    function render($mode, &$R, $data) {
        if ($mode === 'metadata') {
            $this->_save($data);
            return true;
        }
        global $ID;

        // get the helper
        $hlp = plugin_load('helper', 'do');

        // get the page status if not present
        if (!$this->status) {
            $this->status = array();
            $statuses = $hlp->loadPageStatuses($ID);
            foreach ($statuses as $state) {
                $this->status[$state['md5']] = $state;
            }
        }

        switch($data['state']){
            case DOKU_LEXER_ENTER:
                // move current document data to stack
                $this->docstash = $R->doc;
                $R->doc = '';

                // initialize data storage
                $this->taskdata = array(
                    'date' => $data['date'],
                    'user' => $data['user'],
                    'page' => $ID,
                );

                break;

            case DOKU_LEXER_UNMATCHED:
                // plain text, just print it
                $R->cdata($data['match']);
                break;

            case DOKU_LEXER_EXIT:
                global $ID;
                // determine the ID (ignore tags, case and whitespaces)
                $md5 = md5(utf8_strtolower(str_replace(' ','',strip_tags($R->doc.$ID))));
                $this->taskdata['md5']  = $md5;
                $this->taskdata['text'] = trim(strip_tags($R->doc));

                if($mode != 'xhtml') {
                    $text = $R->doc;
                    $R->doc = '';
                    $task = $hlp->loadTasks(array('md5' => $md5));
                    $R->externalmedia(DOKU_URL . 'lib/plugins/do/pix/' .
                                      ($task[0]['status'] ? '' : 'un') . 'done.png');
                    $R->cdata($text);
                    if ($task[0]['msg']) {
                        $R->cdata(' (' . $task[0]['msg'] . ')');
                    }
                    $R->doc = $this->docstash.$R->doc;
                    $this->docstash = '';
                    return true;
                }

                $param = array(
                    'do' => 'plugin_do',
                    'do_page' => $ID,
                    'do_md5' => $md5
                );

                $R->doc = '<span class="plugin_do_item plugin_do_'.$md5.'">'
                        . '<a class="plugin_do_status" href="'.wl($ID,$param).'">'
                        . ' <img src="'.DOKU_BASE.'lib/plugins/do/pix/undone.png" />'
                        . '</a>'
                        .   $R->doc
                        . '<span class="plugin_do_commit">'
                        . ((empty($this->oldStatus[$md5]['msg']))?'':'(' . $this->lang['js']['note_done'] . hsc($this->oldStatus[$md5]['msg']) .')')
                        . '</span>';

                $meta = '';
                if ($this->taskdata['user'] || $this->taskdata['date']) {
                    $meta  = ' <span class="plugin_do_meta">(';
                    if ($this->taskdata['user']) {
                        $meta .= $this->getLang('user').' <span class="plugin_do_meta_user">'.$hlp->getPrettyUser($this->taskdata['user']).'</span>';
                        if ($this->taskdata['date']) $meta .= ', ';
                    }
                    if ($this->taskdata['date']) {
                        $meta .= $this->getLang('date').' <span class="plugin_do_meta_date">'.hsc($this->taskdata['date']).'</span>';
                    }
                    $meta .=')</span>';
                }
                $meta .='</span>';
                // restore the full document, including our additons
                $R->doc = $this->docstash.$R->doc.$meta;
                $this->docstash = '';
                $this->taskdata['msg'] = $this->oldStatus[$md5]['msg'];

                // we're done with this task
                $this->taskdata = array();
                break;
        }

        return true;
    }

    function _save($data) {
        global $ID;

        // get the helper
        $hlp = plugin_load('helper', 'do');

        // hold old status. we need this to keep creator stuff
        if (!$this->oldStatus) {
            $this->oldStatus = array();
            $statuses = $hlp->getAllPageStatuses($ID);
            foreach ($statuses as $state) {
                $this->oldStatus[$state['md5']] = $state;
            }
        }


        // on the first run for this page, clean up
        if(!isset($this->run[$ID])){
            $hlp->cleanPageTasks($ID);
            $this->run[$ID] = true;
        }

        // get the page status if not present
        if (!$this->status) {
            $this->status = array();
            $statuses = $hlp->loadPageStatuses($ID);
            foreach ($statuses as $state) {
                $this->status[$state['md5']] = $state;
            }
        }

        switch($data['state']){
            case DOKU_LEXER_ENTER:
                // initialize data storage
                $this->taskdata = array(
                    'date' => $data['date'],
                    'user' => $data['user'],
                    'page' => $ID,
                );
                break;
            case DOKU_LEXER_UNMATCHED:
                $this->taskdata['text'] = trim(strip_tags($data['match']));
                break;

            case DOKU_LEXER_EXIT:
                global $ID;
                // determine the ID (ignore tags, case and whitespaces)
                $md5 = md5(utf8_strtolower(str_replace(' ','',strip_tags($this->taskdata['text'].$ID))));
                $this->taskdata['md5']  = $md5;


                $this->taskdata['msg'] = $this->oldStatus[$md5]['msg'];

                $this->taskdata['pos'] = ++$this->position;

                // save the task data - only when not saved yet.
                if (!in_array($this->taskdata['md5'], $this->saved)) {
                    $hlp->saveTask($this->taskdata, $this->oldStatus[$this->taskdata['md5']]['creator']);
                    $this->saved[] = $this->taskdata['md5'];
                }
                // we're done with this task
                $this->taskdata = array();
                break;
        }
    }
}

// vim:ts=4:sw=4:et:enc=utf-8
