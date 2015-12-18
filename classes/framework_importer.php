<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This file contains the form add/update a competency framework.
 *
 * @package   tool_lpimportau
 * @copyright 2015 Damyon Wiese
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_lpimportau;

defined('MOODLE_INTERNAL') || die('Direct access to this script is forbidden.');

use tool_lp\api;
use DOMDocument;
use stdClass;

/**
 * Import Competency framework form.
 *
 * @package   tool_lp
 * @copyright 2015 Damyon Wiese
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class framework_importer {

    /** @var string $error The errors message from reading the xml */
    var $error = '';

    /** @var array $tree The competencies tree */
    var $tree = array();

    public function fail($msg) {
        $this->error = $msg;
        return false;
    }

    /**
     * Constructor - parses the raw xml for sanity.
     */
    public function __construct($xml) {
        $doc = new DOMDocument();
        if (!@$doc->loadXML($xml)) {
            $this->fail(get_string('invalidimportfile', 'tool_lpimportau'));
            return;
        }

        $elements = $doc->getElementsByTagName('Description');
        $records = array();
        foreach ($elements as $element) {
            $record = new stdClass();
            // Get the idnumber.
            $attr = $element->attributes->getNamedItem('about');
            if (!$attr) {
                $this->fail(get_string('invalidimportfile', 'tool_lpimportau'));
                return;
            }
            $parts = explode('/', $attr->nodeValue);
            $record->idnumber = array_pop($parts);
            $record->parents = array();

            // Get the shortname and description.
            foreach ($element->childNodes as $child) {
                if ($child->localName == 'description') {
                    $record->description = $child->nodeValue;
                } else if ($child->localName == 'title') {
                    $record->shortname = $child->nodeValue;
                } else if ($child->localName == 'statementNotation') {
                    $record->code = $child->nodeValue;
                } else if ($child->localName == 'statementLabel') {
                    if ($child->nodeValue == 'Content description' || $child->nodeValue == 'Elaboration') {
                        $record->iscompetency = true;
                    }
                } else if ($child->localName == 'isChildOf') {
                    $attr = $child->attributes->getNamedItem('resource');
                    if ($attr) {
                        $parts = explode('/', $attr->nodeValue);
                        array_push($record->parents, array_pop($parts));
                    }
                }
            }

            if (empty($record->shortname) && !empty($record->code)) {
                $record->shortname = $record->code;
            }
            if (empty($record->shortname) && !empty($record->description)) {
                $record->shortname = $record->description;
            }
            $record->children = array();
            $record->childcount = 0;
            array_push($records, $record);
        }

        // Now rebuild into a tree.
        foreach ($records as $key => $record) {
            $record->foundparents = array();
            if (count($record->parents) > 0) {
                $foundparents = array();
                foreach ($records as $parentkey => $parentrecord) {
                    foreach ($record->parents as $parentid) {
                        if ($parentrecord->idnumber == $parentid) {
                            $parentrecord->childcount++;
                            array_push($foundparents, $parentrecord);
                        }
                    }
                }
                $record->foundparents = $foundparents;
            }
        }
        foreach ($records as $key => $record) {
            $record->related = array();
            if (count($record->foundparents) == 0) {
                $record->parentid = '';
            } else if (count($record->foundparents) == 1) {
                array_push($record->foundparents[0]->children, $record);
                $record->parentid = $record->foundparents[0]->idnumber;
            } else {
                // Multiple parents - choose the one with the least children.
                $chosen = null;
                foreach ($record->foundparents as $parent) {
                    if ($chosen == null || $parent->childcount < $chosen->childcount) {
                        $chosen = $parent;
                    }
                }
                foreach ($record->foundparents as $parent) {
                    if ($chosen !== $parent) {
                        array_push($record->related, $parent);
                    }
                }
                array_push($chosen->children, $record);
                $record->parentid = $chosen->idnumber;
            }
        }

        // Remove from top level any nodes with a parent.
        foreach ($records as $key => $record) {
            if (!empty($record->parentid)) {
                unset($records[$key]);
            }
        }

        $this->tree = $records;
    }

    /**
     * @return array of errors from parsing the xml.
     */
    public function get_error() {
        return $this->error;
    }

    public function create_competency($parent, $record, $framework) {
        $competency = new stdClass();
        $competency->competencyframeworkid = $framework->get_id();
        if ($parent) {
            $competency->parentid = $parent->get_id();
        } else {
            $competency->parentid = 0;
        }
        if (!empty($record->code)) {
            $competency->idnumber = trim(clean_param($record->code, PARAM_TEXT));
        } else {
            $competency->idnumber = trim(clean_param($record->idnumber, PARAM_TEXT));
        }
        $competency->shortname = trim(clean_param(shorten_text($record->shortname, 50), PARAM_TEXT));
        if (!empty($record->description)) {
            $competency->description = trim(clean_param($record->description, PARAM_TEXT));
        }

        if (!empty($competency->idnumber) && !empty($competency->shortname)) {
            $parent = api::create_competency($competency);

            $record->id = $parent->get_id();

            foreach ($record->children as $child) {
                $this->create_competency($parent, $child, $framework);
            }
            if (!empty($record->iscompetency) && !empty($record->children)) {
                $parent->set_ruletype('tool_lp\competency_rule_all');
                $parent->set_ruleoutcome(\tool_lp\competency::OUTCOME_EVIDENCE);
                $parent->update();
            }

        }
    }

    public function set_related_competencies($record) {
        if (!empty($record->related)) {
            foreach ($record->related as $related) {
                api::add_related_competency($record->id, $related->id);
            }
        }

        foreach ($record->children as $child) {
            $this->set_related_competencies($child);
        }
    }

    /**
     * @param \tool_lp\competency_framework
     * @return boolean
     */
    public function import_to_framework($framework) {
        foreach ($this->tree as $record) {
            $this->create_competency(null, $record, $framework);
        }
        foreach ($this->tree as $record) {
            $this->set_related_competencies($record);
        }
        return true;
    }
}
