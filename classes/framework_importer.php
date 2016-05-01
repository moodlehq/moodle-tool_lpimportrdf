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
 * @package   tool_lpimportrdf
 * @copyright 2015 Damyon Wiese
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_lpimportrdf;

defined('MOODLE_INTERNAL') || die('Direct access to this script is forbidden.');

use context_system;
use core_competency\api;
use core_competency\invalid_persistent_exception;
use DOMDocument;
use stdClass;

/**
 * Import Competency framework form.
 *
 * @package   tool_lpimportrdf
 * @copyright 2015 Damyon Wiese
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class framework_importer {

    /** @var string $error The errors message from reading the xml */
    var $error = '';

    /** @var array $tree The competencies tree */
    var $tree = array();

    /** @var stdClass The framework node */
    var $framework = null;

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
            $this->fail(get_string('invalidimportfile', 'tool_lpimportrdf'));
            return;
        }

        $this->framework = new stdClass();
        $this->framework->shortname = get_string('unnamed', 'tool_lpimportrdf');
        $this->framework->idnumber = generate_uuid();
        $this->framework->description = '';
        $this->framework->subject = '';
        $this->framework->educationLevel = '';

        $elements = $doc->getElementsByTagName('StandardDocument');
        foreach ($elements as $element) {
            // Get the idnumber.
            $attr = $element->attributes->getNamedItem('about');
            if (!$attr) {
                $this->fail(get_string('invalidimportfile', 'tool_lpimportrdf'));
                return;
            }
            $parts = explode('/', $attr->nodeValue);
            $this->framework->idnumber = array_pop($parts);
            $this->framework->shortname = $this->framework->idnumber;
            
            foreach ($element->childNodes as $child) {
                if ($child->localName == 'description') {
                    $this->framework->description = $child->nodeValue;
                } else if ($child->localName == 'title') {
                    $this->framework->shortname = $child->nodeValue;
                } else if ($child->localName == 'subject') {
                    // Get the resource attribute.
                    $attr = $child->attributes->getNamedItem('resource');
                    if ($attr) {
                        $parts = explode('/', $attr->nodeValue);
                        $this->framework->subject = array_pop($parts);
                    }
                }
            }
            break;
        }

        if ($this->framework->subject) {
            $this->framework->description .= '<br/>' . get_string('subject', 'tool_lpimportrdf', $this->framework->subject);
        }

        $nodes = array();
        $elements = $doc->getElementsByTagName('Statement');
        foreach ($elements as $element) {
            $nodes[] = $element;
        }
        $elements = $doc->getElementsByTagName('Description');
        foreach ($elements as $element) {
            $nodes[] = $element;
        }
        $records = array();
        foreach ($nodes as $element) {
            $record = new stdClass();
            $record->shortname = '';
            // Get the idnumber.
            $attr = $element->attributes->getNamedItem('about');
            if (!$attr) {
                $this->fail(get_string('invalidimportfile', 'tool_lpimportrdf'));
                return;
            }
            $parts = explode('/', $attr->nodeValue);
            $record->idnumber = array_pop($parts);
            $record->shortname = '';
            $record->description = '';
            $record->parents = array();

            // Get the shortname and description.
            foreach ($element->childNodes as $child) {
                if ($child->localName == 'description') {
                    $record->description = $child->nodeValue;
                } else if ($child->localName == 'title') {
                    $record->shortname = $child->nodeValue;
                } else if ($child->localName == 'statementNotation') {
                    $record->betteridnumber = $child->nodeValue;
                } else if ($child->localName == 'educationLevel') {
                    // Get the resource attribute.
                    $attr = $child->attributes->getNamedItem('resource');
                    if ($attr) {
                        $parts = explode('/', $attr->nodeValue);
                        $record->educationLevel = array_pop($parts);
                    }
                } else if ($child->localName == 'subject') {
                    // Get the resource attribute.
                    $attr = $child->attributes->getNamedItem('resource');
                    if ($attr) {
                        $parts = explode('/', $attr->nodeValue);
                        $record->subject = array_pop($parts);
                    }
                } else if ($child->localName == 'isChildOf') {
                    $attr = $child->attributes->getNamedItem('resource');
                    if ($attr) {
                        $parts = explode('/', $attr->nodeValue);
                        array_push($record->parents, array_pop($parts));
                    }
                }
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

    public static function compare_nodes($nodea, $nodeb) {
        $cmp = strcmp($nodea->shortname, $nodeb->shortname);
        if (!$cmp) {
            $cmp = strcmp($nodea->idnumber, $nodeb->idnumber);
        }
        return $cmp;
    }

    private function sort_children($children) {
        usort($children, "\\tool_lpimportrdf\\framework_importer::compare_nodes");
        return $children;
    }

    /**
     * Apply some heuristics to get the best possible idnumber, shortname and description.
     *
     * The heuristics are:
     * 1. Clean all params and use shorten_text where needed to stay inside the max length of each field.
     * 2. If the original record had a statementNotation field - this is the most human readable idnumber - use it for both
     *    the idnumber and shortname
     * 3. If the record had a title - use it for the shortname.
     * 4. If the record has children and a short description - use the description for the shortname.
     * 5. Append the subject and educationLevel to the description.
     * 6. If we still don't have a shortname and the idnumber is short (< 16) use it.
     * 7. If we still don't have a shortname and the description is short use it.
     * 8. If we still don't have a shortname just call it "Competency".
     *
     * @param $record The record from the xml file
     * @param $framework - The created framework 
     * @return none - the $record is modified in place
     */
    public function sanitise_competency($record, $framework) {
        $record->shortname = trim(clean_param(shorten_text($record->shortname, 80), PARAM_TEXT));
        if (!empty($record->description)) {
            $record->description = trim(clean_param($record->description, PARAM_TEXT));
        }
        if (!empty($record->betteridnumber)) {
            $record->idnumber = trim(clean_param($record->betteridnumber, PARAM_TEXT));
            $record->shortname = $record->idnumber;
        } else {
            $record->idnumber = trim(clean_param($record->idnumber, PARAM_TEXT));
        }

        $shortdesc = trim(clean_param(shorten_text($record->description, 80), PARAM_TEXT));
        if (!empty($record->children) && $shortdesc == $record->description && empty($record->shortname)) {
            $record->shortname = $shortdesc;
        }
        if (!empty($record->educationLevel)) {
            $record->description .= '<br/>' . get_string('educationlevel', 'tool_lpimportrdf', $record->educationLevel);
        }
        if (!empty($record->subject)) {
            $record->description .= '<br/>' . get_string('subject', 'tool_lpimportrdf', $record->subject);
        }
        if (empty($record->shortname)) {
            if (!empty($record->idnumber) && (strlen($record->idnumber) < 16)) {
                $record->shortname = $record->idnumber;
            } else if (!empty($shortdesc)) {
                $record->shortname = $shortdesc;
            } else {
                $record->shortname = get_string('competency', 'tool_lpimportrdf');
            }
        }

        foreach ($record->children as $child) {
            $this->sanitise_competency($child, $framework);
        }
    }

    public function create_competency($parent, $record, $framework) {
        $competency = new stdClass();
        $competency->competencyframeworkid = $framework->get_id();
        $competency->shortname = $record->shortname;
        $competency->description = $record->description;
        $competency->idnumber = $record->idnumber;
        if ($parent) {
            $competency->parentid = $parent->get_id();
        } else {
            $competency->parentid = 0;
        }

        if (!empty($competency->idnumber) && !empty($competency->shortname)) {
            $parent = api::create_competency($competency);

            $record->id = $parent->get_id();

            $record->children = $this->sort_children($record->children);

            foreach ($record->children as $child) {
                $this->create_competency($parent, $child, $framework);
            }

        }
    }

    public function set_related_competencies($record) {
        if (!empty($record->related)) {
            foreach ($record->related as $related) {
                if (!empty($record->id) && !empty($related->id)) {
                    api::add_related_competency($record->id, $related->id);
                }
            }
        }

        foreach ($record->children as $child) {
            $this->set_related_competencies($child);
        }
    }

    private function create_framework($scaleid, $scaleconfiguration, $visible) {
        $framework = false;

        $record = new stdClass();
        $record->shortname = $this->framework->shortname;
        $record->idnumber = $this->framework->idnumber;
        $record->description = $this->framework->description;
        $record->descriptionformat = FORMAT_HTML;
        $record->scaleid = $scaleid;
        $record->scaleconfiguration = $scaleconfiguration;
        $record->visible = $visible;
        $record->contextid = context_system::instance()->id;

        $taxdefaults = array();
        $taxcount = 4;
        for ($i = 1; $i <= $taxcount; $i++) {
            $taxdefaults[$i] = \core_competency\competency_framework::TAXONOMY_COMPETENCY;
        }
        $record->taxonomies = $taxdefaults;

        try {
            $framework = api::create_framework($record);
        } catch (invalid_persistent_exception $ip) {
            return $this->fail($ip->getMessage());
        }
        
        return $framework;
    }

    /**
     * @param \stdClass containing scaleconfig
     * @return boolean
     */
    public function import($data) {

        $framework = $this->create_framework($data->scaleid, $data->scaleconfiguration, $data->visible);
        if (!$framework) {
            return false;
        }

        foreach ($this->tree as $record) {
            $this->sanitise_competency($record, $framework);
        }
        $this->tree = $this->sort_children($this->tree);
        foreach ($this->tree as $record) {
            $this->create_competency(null, $record, $framework);
        }
        // Not used right now.
        foreach ($this->tree as $record) {
            $this->set_related_competencies($record);
        }
        return $framework;
    }

}
